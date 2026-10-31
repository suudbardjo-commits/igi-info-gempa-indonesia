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
        $villages = array_values(array_unique(array_filter(array_map(fn ($k) => $this->norm($address[$k] ?? ''), ['village', 'suburb', 'neighbourhood']))));
        $regions = array_values(array_unique(array_filter(array_map(fn ($k) => $this->norm($address[$k] ?? ''), ['county', 'city', 'city_district', 'municipality', 'state_district']))));
        if (! $villages || ! $regions) {
            return null;
        }

        foreach ($villages as $v) {
            $rows = DB::table('admin_areas')->where('level', 4)->whereRaw("lower(regexp_replace(name, '[^a-zA-Z0-9]', '', 'g')) = ?", [$v])->get(['code']);
            $found = [];
            foreach ($rows as $r) {
                $kab = DB::table('admin_areas')->where('code', substr($r->code, 0, 5))->where('level', 2)->value('name');
                if ($kab && in_array($this->norm($kab), $regions, true)) {
                    $found[] = $r->code;
                }
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
