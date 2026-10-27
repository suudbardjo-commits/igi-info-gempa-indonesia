# Sumber Data API

Diuji langsung pada 23 Okt 2026. Semua yang bertanda ✅ menjawab HTTP 200 tanpa kunci API.

## Dipakai di MVP

| API | Endpoint | Isi | Catatan |
|---|---|---|---|
| ✅ BMKG gempa M5+ | `https://data.bmkg.go.id/DataMKG/TEWS/gempaterkini.json` | 15 gempa M5+ terbaru | Pakai field `DateTime` (ISO). Ada header `ETag` dan `Last-Modified`. |
| ✅ BMKG gempa terbaru | `https://data.bmkg.go.id/DataMKG/TEWS/autogempa.json` | 1 gempa terbaru | Dipakai untuk deteksi cepat. |
| ✅ BMKG gempa dirasakan | `https://data.bmkg.go.id/DataMKG/TEWS/gempadirasakan.json` | Gempa yang dirasakan warga | Ada field `Dirasakan`. |
| ✅ USGS | `https://earthquake.usgs.gov/fdsnws/event/1/query?format=geojson&minlatitude=-11&maxlatitude=6&minlongitude=95&maxlongitude=141&minmagnitude=4&orderby=time` | Gempa M4+ di kotak Indonesia | Jangan pakai `all_hour` (global, semua magnitudo). |
| ✅ BMKG peringatan dini cuaca | `https://www.bmkg.go.id/alerts/nowcast/id` (RSS, tiap item mengarah ke CAP XML) | Cuaca ekstrem, bisa jadi sinyal banjir | Format CAP, ada poligon wilayah. |
| ✅ BMKG prakiraan cuaca | `https://api.bmkg.go.id/publik/prakiraan-cuaca?adm4=<kode>` | Prakiraan per kelurahan | Butuh kode wilayah `adm4`. Ada `lat` dan `lon`. |
| ✅ Overpass (OpenStreetMap) | `POST https://overpass-api.de/api/interpreter` | Rumah sakit, puskesmas, sekolah, dll. | **Wajib header `User-Agent`**, tanpa itu server membalas 406. Hasilnya di-cache di tabel sendiri. |

## Opsional

| API | Status |
|---|---|
| ✅ Open-Meteo prakiraan hujan `api.open-meteo.com/v1/forecast` | Jalan. Berguna untuk curah hujan per titik. |
| ⚠️ Open-Meteo banjir `flood-api.open-meteo.com` | Jalan, tapi untuk Surabaya nilai `river_discharge` kosong (`null`). Tidak andal untuk sungai kecil di Indonesia. |
| 🔑 NASA FIRMS (titik panas/kebakaran) | Gratis tapi butuh `MAP_KEY`. Belum diuji. |
| 🔑 MAGMA Indonesia (gunung api) | Membalas 401, butuh token. |
| ❓ PetaBencana.id (banjir) | Parameter `city` ditolak (400), API tampaknya berubah. Belum ketemu endpoint yang jalan. |
| ❓ BNPB DIBI / InaRISK | Portal web (statistik/risiko), tidak ketemu API publik. |

## Aturan pemakaian
- Cantumkan "Sumber: BMKG" dan "Sumber: USGS" di antarmuka.
- Jangan polling lebih rapat dari yang perlu: kirim `If-None-Match` (ETag) ke BMKG, dan cache hasil Overpass.
- Overpass: server publik dengan batas pemakaian wajar, jangan dipakai tiap permintaan pengguna.

## Cuaca, peringatan dini, dan status sumber (25 Okt 2026)

Semua panggilan ke penyedia lewat server (cache, kunci anti-tabrakan, batas laju internal, `Retry-After` dihormati). Tanpa kunci API. Konfigurasi di `config/weather.php`.

| Endpoint publik | Isi |
|---|---|
| `GET /api/v1/locations?q=` | Pencarian tempat (Open-Meteo Geocoding / GeoNames, Indonesia saja, cache 24 jam). ID GeoNames BUKAN kode wilayah BMKG. |
| `GET /api/v1/weather?lat=&lon=` | Estimasi model Open-Meteo: saat ini, per jam 7 hari, harian. Berlabel "Estimasi cuaca saat ini". |
| `GET /api/v1/weather?adm4=31.71.03.1001` | Prakiraan resmi BMKG 3 hari per 3 jam. Kode adm4 tidak pernah ditebak dari nama. Bila BMKG gagal dan lokasinya pernah berhasil, dipakai cadangan Open-Meteo dengan penanda `fallback`. |
| `GET /api/v1/weather-alerts?lat=&lon=&province=&geometry=1&state=all` | Peringatan dini BMKG (RSS + CAP). `coverage`: `polygon` (titik di dalam poligon) atau `province` (hanya nama provinsi cocok). Status test/exercise dibuang. |
| `GET /api/v1/sources/status` | healthy / late / failed / unknown per sumber, tanpa rahasia. |
| `GET /api/v1/disasters-export?hours=&min_magnitude=&q=&south=&north=&west=&east=` | CSV (hingga 1.000 baris; sel berawalan `= + - @` dinetralkan). |

Respons cuaca: `provider`, `label`, `attribution`, `fetched_at`, `issued_at` (null bila penyedia tidak memberikannya), `valid_from/valid_to`, `timezone` + `tz_label` (WIB/WITA/WIT), `is_stale`, `cache_age_seconds`, `current`, `hourly`, `daily`, `units`. Galat: `{status:"error", error:{code,message}}` dengan 422/502/503.

Peringatan disegarkan terjadwal oleh `php artisan weather:alerts` (tiap 3 menit). Tautan CAP hanya diterima bila https, host `www.bmkg.go.id`, tanpa port/userinfo/query, dan jalur `/alerts/nowcast/id/<nama>.xml`; dokumen ber-DOCTYPE/ENTITY ditolak (XXE).

**Belum ada:** daftar kode wilayah (provinsi → desa) sehingga tidak ada pemilih bertingkat; prakiraan BMKG hanya lewat kode adm4 yang dimasukkan pengguna. Open-Meteo gratis hanya untuk penggunaan non-komersial; periksa https://open-meteo.com/en/pricing sebelum dipakai komersial.

### Katalog kode wilayah (25 Okt 2026)

`GET /api/v1/areas` (provinsi) dan `GET /api/v1/areas?parent=31.71` (anak langsung; tingkat kecamatan → desa/kelurahan = kode adm4 BMKG). Respons memuat `catalog` (keputusan, sumber, lisensi, jumlah, waktu impor).

- Data: Kepmendagri No. 300.2.2-2138 Tahun 2025 (kode dan data wilayah administrasi pemerintahan), ditranskripsi oleh proyek cahyadsn/wilayah (lisensi MIT, commit d68e8d55…, berkas `db/wilayah.sql`), disimpan di `database/data/wilayah-kepmendagri-2025.sql` (SHA-256 c4c3396d…). Ini transkripsi komunitas dari dokumen resmi, bukan berkas yang dirilis Kemendagri sendiri.
- Impor: `php artisan migrate && php artisan areas:import` (satu transaksi; versi dicatat di tabel `admin_area_versions`). Hasil: 38 provinsi, 514 kab/kota, 7.285 kecamatan, 83.762 desa/kelurahan, cocok dengan total resmi. Untuk memperbarui, ganti berkas SQL lalu jalankan ulang perintah.
- BMKG tidak menjamin semua kode ada datanya; kode di katalog yang belum punya prakiraan dijawab 404 "Penyedia tidak memiliki data" (contoh yang ditemui: 94.01.01.2001). `/weather?adm4=` menolak kode di luar katalog.

### API untuk aplikasi (React Native / Expo)

Aplikasi `aplikasi-expo` hanya klien: seluruh data dari API publik berikut (JSON, tanpa login, dibatasi laju per IP). Galat memakai bentuk `{status:"error", error:{code,message}}`.

| Endpoint | Dipakai untuk |
|---|---|
| `GET /api/v1/disasters?hours=&min_magnitude=&limit=` · `/disasters/{id}` | Daftar, peta, dan detail gempa (GeoJSON `[bujur, lintang]`) |
| `GET /api/v1/camps` | Posko (kapasitas/penghuni tercatat) |
| `GET /api/v1/locations?q=` · `/areas?parent=` · `/weather` · `/weather-alerts` | Cuaca dan peringatan dini |
| `GET /api/v1/news?limit=` · `/announcements` · `/sources/status` | Berita lembaga, pengumuman, status sumber |
| `GET /api/v1/stats` | Ringkasan angka |

Login petugas, logistik, dan laporan lapangan memakai API terproteksi (token Sanctum, `POST /api/v1/auth/token`); layar-layar itu masih lewat tab "Situs" (WebView) di aplikasi.
