<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AreasImport extends Command
{
    protected $signature = 'areas:import {--file= : Berkas SQL katalog (bawaan: database/data/wilayah-kepmendagri-2025.sql)}';

    protected $description = 'Impor katalog kode wilayah (provinsi sampai desa) dan catat versinya';

    public function handle(): int
    {
        $file = $this->option('file') ?: database_path('data/wilayah-kepmendagri-2025.sql');
        if (! is_file($file)) {
            $this->error("Berkas tidak ditemukan: {$file}");

            return self::FAILURE;
        }

        $rows = self::parse(file_get_contents($file));
        $counts = array_count_values(array_column($rows, 'level'));
        if (count($rows) < 1000 || ($counts[1] ?? 0) < 30) {
            $this->error('Isi berkas tidak terbaca sebagai katalog wilayah; impor dibatalkan.');

            return self::FAILURE;
        }

        // Satu transaksi: katalog lama baru diganti bila seluruh data baru berhasil masuk.
        DB::transaction(function () use ($rows, $counts, $file) {
            DB::table('admin_areas')->delete();
            foreach (array_chunk($rows, 2000) as $chunk) {
                DB::table('admin_areas')->insert($chunk);
            }
            DB::table('admin_area_versions')->insert([
                'source' => 'Kode dan Data Wilayah Administrasi Pemerintahan, Kemendagri (transkripsi cahyadsn/wilayah, lisensi MIT)',
                'decree' => 'Kepmendagri No. 300.2.2-2138 Tahun 2025',
                'license' => 'MIT (transkripsi); dasar hukum: keputusan menteri yang diumumkan publik',
                'source_url' => 'https://github.com/cahyadsn/wilayah',
                'source_commit' => 'd68e8d5516f969d1905d0b2940f20034becb0db7',
                'file_sha256' => hash_file('sha256', $file),
                'counts' => json_encode(['provinsi' => $counts[1] ?? 0, 'kabupaten_kota' => $counts[2] ?? 0, 'kecamatan' => $counts[3] ?? 0, 'desa_kelurahan' => $counts[4] ?? 0]),
                'imported_at' => now(),
            ]);
        });

        $this->info(count($rows).' wilayah diimpor (provinsi '.($counts[1] ?? 0).', kab/kota '.($counts[2] ?? 0).', kecamatan '.($counts[3] ?? 0).', desa/kelurahan '.($counts[4] ?? 0).').');

        return self::SUCCESS;
    }

    /** @return list<array{code: string, name: string, level: int, parent_code: ?string}> */
    public static function parse(string $sql): array
    {
        preg_match_all("/\('(\d{2}(?:\.\d{2}(?:\.\d{2}(?:\.\d{4})?)?)?)','((?:[^'\\\\]|\\\\.|'')*)'\)/", $sql, $m, PREG_SET_ORDER);
        $seen = [];
        foreach ($m as [, $code, $name]) {
            $parts = explode('.', $code);
            $seen[$code] = [
                'code' => $code,
                'name' => trim(str_replace(["\\'", "''"], "'", $name)),
                'level' => count($parts),
                'parent_code' => count($parts) > 1 ? implode('.', array_slice($parts, 0, -1)) : null,
            ];
        }

        return array_values($seen);
    }
}
