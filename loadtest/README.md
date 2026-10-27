# Uji beban

Empat skenario. Semuanya dijalankan terhadap **instance uji terpisah** (port 8001), bukan server yang dipakai sehari-hari, dengan batas laju dinaikkan supaya yang terukur adalah aplikasinya, bukan pembatas lajunya.

| Skenario | Berkas | Yang dibuktikan |
|---|---|---|
| Pembacaan publik | `public-read.js` (k6) | Peta dan API GeoJSON tetap menjawab saat lonjakan pengunjung |
| Sinkronisasi lapangan | `sync.js` (k6) | Banyak perangkat menyinkronkan antrean offline sekaligus; 15% operasi sengaja dikirim ulang untuk membuktikan idempotensi |
| Siaran WebSocket | `ws-fanout.mjs` (Node) | Satu gempa sampai ke ratusan klien Reverb |
| Lonjakan gempa | `php artisan loadtest:burst` | Antrean `alerts` dan Horizon menuntaskan banyak gempa beruntun tanpa job gagal |

## Menyiapkan

Semua perintah dijalankan dari folder proyek. Perintah `loadtest:*` hanya jalan di `APP_ENV=local`.

```bash
docker compose exec -T -u sail laravel.test php artisan loadtest:prepare --users=30 --camps=10
```

Instance uji di port 8001 dengan batas laju longgar:

```bash
docker compose exec -d -u sail -e PHP_CLI_SERVER_WORKERS=4 -e RATE_LIMIT_PUBLIC=1000000 -e RATE_LIMIT_AUTHENTICATED=1000000 -e RATE_LIMIT_LOGIN=1000000 laravel.test php artisan serve --host=0.0.0.0 --port=8001
```

## Menjalankan

```bash
docker run --rm --network monitoringbencanaalam_sail -v "${PWD}/loadtest:/s" grafana/k6 run /s/public-read.js
docker run --rm --network monitoringbencanaalam_sail -v "${PWD}/loadtest:/s" grafana/k6 run /s/sync.js
docker compose exec -T -u sail laravel.test node loadtest/ws-fanout.mjs --clients=300
docker compose exec -T -u sail laravel.test php artisan loadtest:burst --count=40
```

Variabel k6: `BASE_URL`, `PEAK_VUS`, `USERS`, `BATCH`.

## Membersihkan

```bash
docker compose exec -T -u sail laravel.test php artisan loadtest:cleanup
```

Lalu hentikan instance port 8001 (`docker compose restart laravel.test` cukup).

## Hasil (24 Okt 2026, laptop pengembangan, `artisan serve` 4 pekerja)

Angka ini mengukur server pengembangan, **bukan** produksi. Pakai untuk membandingkan sebelum/sesudah perubahan, bukan sebagai janji kapasitas.

- **Pembacaan publik**: 60 pengguna maya, ±82 dtk, 1.949 permintaan, 0% gagal, ±24 permintaan/dtk, p95 API 1,04 dtk, p95 halaman 1,17 dtk. Server pengembangan sudah jenuh di sini.
- **Sinkronisasi**: 30 perangkat, kiriman 20 operasi, 4.660 operasi terkirim (637 pengiriman ulang disengaja). 0 `retry`, 0 `rejected`, 0 kegagalan HTTP. Di database: `field_reports` = `sync_operations` = 4.023 operasi unik baru, tanpa yatim di kedua arah, jadi tidak ada operasi hilang maupun ganda. p95 latensi kiriman 10,6 dtk (terikat server). Biaya: 6 kueri database per operasi.
  - Uji ini menemukan satu cacat pelaporan: operasi yang muncul dua kali dalam satu kiriman dilaporkan `duplicate` dua kali (hasil dipetakan per ID). Data tidak terpengaruh; sudah diperbaiki dan ada tesnya.
- **Siaran WebSocket**: 300 klien terhubung dan berlangganan dalam 1,7 dtk, 300/300 menerima gempa, selisih penerima tercepat ke terlambat 13 ms.
- **Lonjakan gempa**: 40 gempa disuntik dalam 1,75 dtk; antrean tuntas 7,0 dtk sejak mulai, 0 job gagal, 111 posko terdampak tercatat, throughput ujung ke ujung 5,7 gempa/dtk.

## Yang belum diuji

- Produksi sungguhan (PHP-FPM/Octane, Postgres terpisah). Latensi 10 dtk per kiriman di atas tidak akan sama di sana.
- Reverb di atas ±300 koneksi atau lewat proxy TLS.
- Telegram sungguhan (belum ada token bot).
