<?php

return [

    /*
    | Radius dampak (km) menurut magnitudo, dicari dari atas ke bawah.
    | Ini heuristik sederhana untuk MVP, bukan model sebaran guncangan.
    */
    'impact_radius' => [
        ['min_magnitude' => 7.0, 'radius_km' => 100],
        ['min_magnitude' => 6.0, 'radius_km' => 60],
        ['min_magnitude' => 5.0, 'radius_km' => 35],
        ['min_magnitude' => 0.0, 'radius_km' => 15],
    ],

    /*
    | Aturan menggabungkan gempa yang sama dari dua sumber (BMKG dan USGS).
    */
    'dedupe' => [
        'time_window_seconds' => 60,
        'radius_km' => 100,
        'magnitude_tolerance' => 1.0,
    ],

    'bmkg' => [
        'base_url' => 'https://data.bmkg.go.id/DataMKG/TEWS',
        'timeout' => 10,
    ],

    'usgs' => [
        'endpoint' => 'https://earthquake.usgs.gov/fdsnws/event/1/query',
        'timeout' => 15,
        // Kotak Indonesia.
        'bbox' => ['minlatitude' => -11, 'maxlatitude' => 6, 'minlongitude' => 95, 'maxlongitude' => 141],
        'min_magnitude' => 4.0,
        'lookback_hours' => (int) env('DISASTER_USGS_LOOKBACK_HOURS', 6),
    ],

    'alerts' => [
        // Gempa yang kejadiannya lebih lama dari ini disimpan tetapi tidak memicu siaran atau notifikasi,
        // supaya pengambilan data pertama (feed berisi gempa beberapa hari) tidak membanjiri orang dengan alert basi.
        'max_age_minutes' => (int) env('DISASTER_ALERT_MAX_AGE_MINUTES', 180),
        // Operator BPBD diberi tahu untuk gempa sebesar ini ke atas (atau yang berpotensi tsunami), di mana pun lokasinya.
        'operator_min_magnitude' => (float) env('DISASTER_OPERATOR_MIN_MAGNITUDE', 5.0),
    ],

    // Batas permintaan per menit. Bawaan cocok untuk penggunaan nyata (satu pengguna = satu IP/akun); naikkan
    // lewat .env hanya pada instance uji beban, tempat semua pengguna virtual datang dari satu alamat.
    'rate_limits' => [
        'public' => (int) env('RATE_LIMIT_PUBLIC', 60),
        'authenticated' => (int) env('RATE_LIMIT_AUTHENTICATED', 120),
        'login' => (int) env('RATE_LIMIT_LOGIN', 5),
    ],

    'logistics' => [
        // Barang mudah rusak yang kedaluwarsanya lebih dekat dari ini tidak dialokasikan.
        'min_shelf_life_days' => 7,
        // Satu permintaan dipenuhi dari paling banyak sekian gudang, supaya tidak tersebar jadi banyak truk.
        'max_sources' => 3,
        // Jarak maksimum posko dari lokasi geolokasi serah terima.
        'geofence_radius_m' => 500,
    ],

    /*
    | Berita dari lembaga resmi. Hanya judul, ringkasan singkat, tanggal, dan tautan yang disimpan; tautan hanya diterima
    | bila berada di host sumbernya sendiri. BNPB tidak menyediakan RSS yang bisa diakses program, jadi daftar beritanya
    | dibaca dari halaman HTML (rapuh bila tampilan situsnya berubah; matikan dengan NEWS_BNPB_ENABLED=false).
    */
    'news' => [
        'timeout' => 15,
        'max_items' => 30,
        'max_bytes' => 2_000_000,
        'sources' => [
            'bmkg' => [
                'enabled' => (bool) env('NEWS_BMKG_ENABLED', true),
                'label' => 'BMKG',
                'kind' => 'Peringatan dini cuaca',
                'url' => 'https://www.bmkg.go.id/alerts/nowcast/id',
                'host' => 'www.bmkg.go.id',
                'home' => 'https://www.bmkg.go.id',
                'retention_hours' => 24,
            ],
            'bnpb' => [
                'enabled' => (bool) env('NEWS_BNPB_ENABLED', true),
                'label' => 'BNPB',
                'kind' => 'Berita',
                'url' => 'https://www.bnpb.go.id/berita',
                'host' => 'www.bnpb.go.id',
                'home' => 'https://www.bnpb.go.id/berita',
                'retention_hours' => 720,
            ],
        ],
    ],

    'http_user_agent' => 'MonitoringBencanaAlam/0.1 (proyek magang)',
];
