<?php

return [

    /*
    | Cuaca, pencarian lokasi, dan peringatan dini. Semua panggilan keluar lewat server; browser tidak pernah
    | menghubungi penyedia langsung, dan tidak ada kunci API yang dibutuhkan (BMKG dan Open-Meteo tingkat gratis).
    | Kontrak penyedia diperiksa langsung 25 Okt 2026.
    */
    'user_agent' => env('WEATHER_USER_AGENT', 'IGI-InfoGempaIndonesia/1.0 (portal informasi publik)'),
    'timeout' => 8,
    'max_bytes' => 1_500_000,

    // Detik. Prakiraan diperbarui penyedia beberapa kali sehari; data basi tetap disajikan (berlabel) selama stale_for.
    'ttl' => [
        'forecast' => (int) env('WEATHER_TTL_FORECAST', 1200),
        'alerts' => (int) env('WEATHER_TTL_ALERTS', 180),
        'geocode' => (int) env('WEATHER_TTL_GEOCODE', 86400),
    ],
    'stale_for' => 21600,

    // Pembatas internal ke penyedia (permintaan per menit), di luar batas per-IP pengguna kita.
    'upstream_per_minute' => ['open-meteo' => 240, 'bmkg' => 50, 'geocoder' => 120, 'nominatim' => 40],

    // Wilayah yang dilayani: Indonesia dengan sedikit margin. Mencegah endpoint dipakai sebagai proksi cuaca dunia.
    'bounds' => ['south' => -12.5, 'north' => 7.5, 'west' => 94.0, 'east' => 142.0],

    'open_meteo' => [
        'forecast_url' => 'https://api.open-meteo.com/v1/forecast',
        'geocode_url' => 'https://geocoding-api.open-meteo.com/v1/search',
        'attribution' => 'Data cuaca oleh Open-Meteo.com (CC BY 4.0; tingkat gratis untuk penggunaan non-komersial). Geokode: GeoNames.',
    ],

    // Balik-geokode koordinat ke nama desa/kabupaten agar prakiraan BMKG (per desa) bisa dipakai untuk titik GPS.
    'nominatim' => ['url' => 'https://nominatim.openstreetmap.org/reverse'],

    'bmkg' => [
        'forecast_url' => 'https://api.bmkg.go.id/publik/prakiraan-cuaca',
        'attribution' => 'Prakiraan cuaca: BMKG (Badan Meteorologi, Klimatologi, dan Geofisika).',
        'alert_rss_url' => 'https://www.bmkg.go.id/alerts/nowcast/id/rss.xml',
        'alert_host' => 'www.bmkg.go.id',
        'alert_path' => '#^/alerts/nowcast/id/[A-Za-z0-9_\-]{3,60}\.xml$#',
        'alert_max_items' => 40,
        'alert_max_bytes' => 400_000,
        'alert_attribution' => 'Peringatan dini cuaca: BMKG.',
    ],
];
