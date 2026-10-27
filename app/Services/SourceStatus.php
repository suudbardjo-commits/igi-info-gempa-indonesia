<?php

namespace App\Services;

use App\Models\ExternalNews;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Status pengambilan data dari tiap sumber untuk halaman "Tentang data". Yang ditampilkan adalah kapan sumber itu
 * terakhir berhasil dijawab, bukan kapan gempa terakhir terjadi: sumber yang sepi tetap sehat bila terakhir dijawab baru-baru ini.
 */
class SourceStatus
{
    /** @return list<array{key: string, name: string, what: string, every: string, last_ok: ?string, stale: bool}> */
    public function report(): array
    {
        $news = ExternalNews::query()->selectRaw('source, max(fetched_at) as at')->groupBy('source')->pluck('at', 'source');

        $rows = [
            ['bmkg', 'BMKG, data gempa', 'Gempa terbaru dan gempa M 5+ (JSON data.bmkg.go.id)', 'tiap 1 sampai 2 menit', $this->cached('bmkg'), 10],
            ['usgs', 'USGS, data gempa', 'Gempa wilayah Indonesia M 4+ sebagai pembanding (earthquake.usgs.gov)', 'tiap 5 menit', $this->cached('usgs'), 20],
            ['bmkg-cuaca', 'BMKG, peringatan dini cuaca', 'RSS peringatan cuaca ekstrem', 'tiap 15 menit', $this->at($news['bmkg'] ?? null), 90],
            ['bnpb', 'BNPB, berita', 'Judul dan tanggal dari daftar berita www.bnpb.go.id', 'tiap jam', $this->at($news['bnpb'] ?? null), 360],
        ];

        return array_map(fn ($r) => [
            'key' => $r[0],
            'name' => $r[1],
            'what' => $r[2],
            'every' => $r[3],
            'last_ok' => $r[4]?->toIso8601String(),
            'stale' => ! $r[4] || $r[4]->lt(now()->subMinutes($r[5])),
        ], $rows);
    }

    /**
     * Untuk API publik: semua sumber dengan status healthy / late / failed / unknown. Tanpa URL sumber berparameter dan tanpa rahasia.
     *
     * @return list<array{key: string, name: string, last_ok: ?string, last_failure: ?array{at: string, code: string}, status: string}>
     */
    public function forApi(): array
    {
        $rows = array_map(fn ($r) => [
            'key' => $r['key'], 'name' => $r['name'], 'last_ok' => $r['last_ok'], 'last_failure' => null,
            'status' => $r['last_ok'] === null ? 'unknown' : ($r['stale'] ? 'late' : 'healthy'),
        ], $this->report());

        // [kunci, nama, menit sebelum dianggap terlambat]
        foreach ([['open-meteo', 'Open-Meteo, prakiraan koordinat', 180], ['bmkg-forecast', 'BMKG, prakiraan cuaca wilayah', 180], ['bmkg-alerts', 'BMKG, peringatan dini (CAP)', 30], ['geocoder', 'Open-Meteo Geocoding, pencarian lokasi', 1440]] as [$key, $name, $late]) {
            $ok = $this->at(Cache::get("source:last_ok:{$key}"));
            $fail = Cache::get("source:last_fail:{$key}");
            $status = match (true) {
                $fail && (! $ok || CarbonImmutable::parse($fail['at'])->gt($ok)) => 'failed',
                ! $ok => 'unknown',
                $ok->lt(now()->subMinutes($late)) => 'late',
                default => 'healthy',
            };
            $rows[] = ['key' => $key, 'name' => $name, 'last_ok' => $ok?->toIso8601String(), 'last_failure' => $fail, 'status' => $status];
        }

        return $rows;
    }

    private function cached(string $key): ?CarbonImmutable
    {
        return $this->at(Cache::get("ingest:last_ok:{$key}"));
    }

    private function at(mixed $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value) : null;
    }
}
