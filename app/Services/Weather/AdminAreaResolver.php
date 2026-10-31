<?php

namespace App\Services\Weather;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Mencocokkan koordinat ke kode desa/kelurahan (adm4) agar prakiraan BMKG bisa dipakai untuk titik GPS.
 * Alur: balik-geokode (OpenStreetMap Nominatim) menghasilkan nama desa dan kabupaten/kota, lalu dicocokkan ke katalog
 * Kemendagri di tabel admin_areas. Kode hanya dikembalikan bila cocok tepat satu; selain itu null (pemanggil memakai cadangan).
 */
class AdminAreaResolver
{
    public function __construct(private Upstream $upstream) {}

    public function resolve(float $lat, float $lon): ?string
    {
        $key = sprintf('adm4:geo:%.2f:%.2f', $lat, $lon);
        $hit = Cache::get($key);
        if ($hit !== null) {
            return $hit === '' ? null : $hit;
        }

        try {
            $json = $this->upstream->get('nominatim', 'nominatim', config('weather.nominatim.url'), [
                'format' => 'jsonv2', 'zoom' => 18, 'addressdetails' => 1, 'accept-language' => 'id', 'lat' => $lat, 'lon' => $lon,
            ])->json();
        } catch (UpstreamException) {
            return null; // gagal sementara: tidak disimpan, dicoba lagi pada permintaan berikutnya
        }

        $code = is_array($json) ? $this->match($json['address'] ?? []) : null;
        Cache::put($key, $code ?? '', 86400 * 30);

        return $code;
    }

    /** @param array<string, mixed> $address */
    public function match(array $address): ?string
    {
        if (($address['country_code'] ?? 'id') !== 'id') {
            return null;
        }
        $villages = [];
        foreach (['village', 'suburb', 'neighbourhood', 'quarter', 'hamlet'] as $k) {
            $raw = (string) ($address[$k] ?? '');
            // OSM kadang menulis "RW 06 Kel. 19 Ilir Kec. Bukit Kecil"; ambil nama setelah "Kel."/"Desa".
            if (preg_match('/\b(?:kel(?:urahan)?\.?|desa)\s+(.+?)(?:\s+kec(?:amatan)?\b.*)?$/iu', $raw, $m)) {
                $villages[] = $this->norm($m[1]);
            }
            $villages[] = $this->norm($raw);
        }
        $villages = array_values(array_unique(array_filter($villages)));
        $regions = array_values(array_unique(array_filter(array_map(fn ($k) => $this->norm($address[$k] ?? ''), ['county', 'city', 'city_district', 'municipality', 'state_district']))));
        if (! $villages || ! $regions) {
            return null;
        }
        $kec = [];
        foreach (['city_district', 'suburb', 'neighbourhood'] as $k) {
            $raw = (string) ($address[$k] ?? '');
            $kec[] = $this->norm(preg_match('/\bkec(?:amatan)?\.?\s+(.+)$/iu', $raw, $m) ? $m[1] : $raw);
        }
        $kec = array_values(array_filter($kec));

        foreach ($villages as $v) {
            $rows = DB::table('admin_areas')->where('level', 4)->whereRaw("lower(regexp_replace(name, '[^a-zA-Z0-9]', '', 'g')) = ?", [$v])->get(['code']);
            $found = [];
            foreach ($rows as $r) {
                $kab = DB::table('admin_areas')->where('code', substr($r->code, 0, 5))->where('level', 2)->value('name');
                if ($kab && in_array($this->norm($kab), $regions, true)) {
                    $found[] = $r->code;
                }
            }
            if (count($found) > 1 && $kec) {
                // Nama desa kembar di satu kabupaten: pilih yang kecamatannya sama dengan hasil balik-geokode.
                $found = array_values(array_filter($found, function ($code) use ($kec) {
                    $name = DB::table('admin_areas')->where('code', substr($code, 0, 8))->where('level', 3)->value('name');

                    return $name && in_array($this->norm($name), $kec, true);
                }));
            }
            if (count($found) === 1) {
                return $found[0];
            }
        }

        return null;
    }

    private function norm(?string $s): string
    {
        $s = mb_strtolower((string) $s);
        $s = preg_replace('/\b(kabupaten|kab\.|kota administrasi|kota adm\.|kota)\b/u', '', $s);

        return preg_replace('/[^a-z0-9]/', '', $s);
    }
}
