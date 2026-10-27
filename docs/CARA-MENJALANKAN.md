# Cara menjalankan

Semua berjalan di Docker (Sail). Docker Desktop terpasang di `D:\Docker`, datanya di `D:\DockerData`.

## Menyalakan

1. Buka Docker Desktop dan tunggu mesinnya siap.
2. Dari folder proyek:

```bash
docker compose up -d
```

Aplikasi: http://localhost:8000. Dasbor antrean: http://localhost:8000/horizon (hanya lokal).

Servis: `laravel.test` (web), `pgsql` (PostGIS), `redis`, `reverb` (WebSocket, port 8080), `horizon` (antrean), `scheduler` (jadwal ingesti).

## Perintah sehari-hari

Selalu jalankan perintah artisan, composer, dan npm **sebagai user `sail`** (`-u sail`). Tanpa itu, berkas yang dibuat milik `root` dan Horizon gagal menulis log.

```bash
docker compose exec -u sail laravel.test php artisan test
docker compose exec -u sail laravel.test php artisan migrate:fresh --seed
docker compose exec -u sail laravel.test npm run build
```

| Tujuan | Perintah |
|---|---|
| Ambil gempa BMKG | `php artisan ingest:bmkg --feed=terkini` (atau `--feed=auto`) |
| Ambil gempa USGS | `php artisan ingest:usgs` |
| Simulasi gempa (uji siaran langsung) | `php artisan quake:simulate --mag=6.1 --lat=-8.10 --lng=113.20` |
| Hapus gempa simulasi | `DELETE FROM disaster_events WHERE source = 'manual';` |

`ingest:*` juga berjalan otomatis lewat scheduler.

## Jebakan yang sudah ditemui

- **`vendor/` dan `node_modules/` ada di volume Docker**, bukan di folder Windows. Membacanya lewat bind mount Windows membuat `php artisan` 8,6 detik (di volume 0,3 detik). Setelah mengubah `composer.json` atau `package.json`, jalankan `composer install` / `npm ci` di dalam kontainer. Folder `vendor` di Windows tidak dipakai.
- **BMKG memblokir `Accept: application/json`** (403 "Laman diblokir"). Klien BMKG mengirim `Accept: */*`.
- **Layanan tile CARTO kini butuh kunci API.** Peta dasar memakai Esri World Light Gray (tanpa kunci). Untuk produksi, pakai penyedia tile yang lisensinya sesuai.
- **`migrate:fresh` tidak menghapus function PostgreSQL.** Migration memakai `CREATE OR REPLACE FUNCTION`.
- Setelah `migrate:fresh`, ETag BMKG masih tersimpan di Redis sehingga BMKG membalas 304. Jalankan `php artisan cache:clear` agar data diambil ulang.
- RAM Windows sempat tinggal ~1,5 GB dan Docker Desktop pernah tertutup sendiri. Tutup aplikasi lain bila terulang.

## API publik (tanpa login, 60 permintaan/menit)

| Endpoint | Isi |
|---|---|
| `GET /api/v1/disasters?hours=168&min_magnitude=5&source=bmkg&limit=200` | GeoJSON gempa terbaru (duplikat antar-sumber disembunyikan) |
| `GET /api/v1/disasters/{id}` | Satu gempa beserta posko dalam radius dampak |
| `GET /api/v1/camps` | GeoJSON posko (tanpa nomor telepon) |

## Konsol logistik (antarmuka di peramban)

Buka http://localhost:8000/logistik, masuk dengan akun demo di bawah. Halaman:

| Halaman | Isi | Siapa |
|---|---|---|
| Permintaan (`/logistik`) | Ringkasan angka, daftar permintaan posko urut urgensi, panel saran alokasi stok dan tombol "Buat pengiriman" | pengelola gudang (bisa buat pengiriman), operator (hanya melihat) |
| Pengiriman (`/logistik/pengiriman`) | Daftar per status; Berangkatkan, Batalkan, dan "Terima atas nama posko" (dengan lokasi dan override geofence) | tombol muncul sesuai hak dari server |
| Stok gudang (`/logistik/stok`) | Stok per batch dengan peringatan kedaluwarsa; Tambah stok | pengelola gudang itu menambah; operator melihat |
| Jejak audit (`/logistik/audit`) | Pemeriksaan integritas (rantai audit dan saldo vs ledger) dan daftar catatan | operator dan admin |

Cara kerja: login berbasis sesi (`/masuk`), lalu halaman memanggil API yang sama dengan aplikasi lapangan lewat cookie sesi (Sanctum stateful, token CSRF dari cookie `XSRF-TOKEN`). Hak akses tidak ditulis ulang di antarmuka: server yang memutuskan, dan `DispatchResource` mengirim bendera `can` untuk menampilkan tombol. Setelah mengubah berkas di `resources/js`, jalankan `npm run build` di kontainer.

Koordinator posko dan relawan tidak punya akses ke konsol (mereka memakai aplikasi lapangan di Sprint 6).

## Peringatan gempa ke posko dan operator

Saat gempa **baru** masuk, `CalculateDisasterImpactJob` menilai posko mana yang berada di radius dampak, lalu `AlertDispatcher`:

| Penerima | Lewat | Syarat |
|---|---|---|
| Koordinator posko yang terdampak | WebSocket channel private `camps.{id}` dan Telegram | postonya di dalam radius dampak |
| Konsol logistik (pengelola gudang dan operator) | WebSocket channel private `logistics` | ada posko terdampak |
| Operator BPBD | Telegram | gempa M5,0 ke atas (atur `DISASTER_OPERATOR_MIN_MAGNITUDE`) atau berpotensi tsunami, di mana pun lokasinya |

Aturan yang melindungi dari peringatan palsu atau ganda:
- **Hanya gempa segar** (maksimal 180 menit, `DISASTER_ALERT_MAX_AGE_MINUTES`). Gempa lama yang ikut terambil saat ingesti pertama tetap disimpan dan dinilai dampaknya, tetapi tidak menyiarkan apa pun.
- **Laporan duplikat dari sumber lain** (BMKG dan USGS untuk gempa yang sama) tidak memicu peringatan kedua.
- **Satu orang paling banyak sekali per gempa** (tabel `alert_deliveries`, kunci unik per gempa, pengguna, kanal), walau penilaian dampak dijalankan ulang.
- Pengguna yang belum menautkan Telegram dicatat sebagai `skipped` dengan alasannya, bukan menghilang.

Halaman `/akun` (semua pengguna yang login) menampilkan peringatan 72 jam terakhir untuk posko yang diampu dan menerima peringatan baru langsung tanpa memuat ulang. Koordinator posko dan relawan diarahkan ke sana setelah login.

### Menyiapkan bot Telegram

Tanpa token, notifikasi Telegram mati dan sisanya (WebSocket, halaman Akun) tetap berjalan.

1. Di Telegram, buka `@BotFather`, kirim `/newbot`, ikuti petunjuknya. Anda mendapat **token** dan **username** bot.
2. Isi di `.env` (token **jangan** ditempel di obrolan, kode, atau repositori):
   ```
   TELEGRAM_BOT_TOKEN=...
   TELEGRAM_BOT_USERNAME=nama_bot_anda
   ```
3. Restart pekerja antrean supaya membaca token baru: `docker compose restart horizon scheduler`.
4. Tiap pengguna membuka halaman Akun, menekan "Hubungkan Telegram", lalu mengirim `/start KODE` ke bot dalam 15 menit. Kode berlaku sekali. Bot membaca pesan masuk lewat `telegram:poll` yang berjalan tiap menit (tanpa perlu alamat publik), jadi penautan bisa tertunda sampai satu menit.
5. Kirim `/stop` ke bot, atau tombol "Putuskan Telegram", untuk berhenti.

Bila token salah atau dicabut, Telegram membalas 401: dicatat sebagai log **kritis** (tanpa token) dan pengiriman berstatus `failed`. Pengguna yang memblokir bot otomatis diputus tautannya.

### Mencoba tanpa menunggu gempa sungguhan

```bash
docker compose exec -u sail laravel.test php artisan quake:simulate --mag=6.2 --lat=-8.14 --lng=113.23
```

Buka `/akun` sebagai `koordinator@bencana.test` (posko Lumajang) dan `/logistik` sebagai `operator@bencana.test`; keduanya menerima peringatan langsung. Lihat hasil pengiriman Telegram di tabel `alert_deliveries`. Hapus sisa uji dengan `DELETE FROM disaster_events WHERE source = 'manual';`.

## Logistik (perlu login)

Login: `POST /api/v1/auth/token` dengan `email`, `password`, `device_name`; pakai token sebagai `Authorization: Bearer ...`.

Akun contoh lokal (kata sandi semuanya `password`, dibuat `migrate:fresh --seed` di lingkungan `local`):

| Email | Peran | Hak khusus |
|---|---|---|
| `admin@bencana.test` | super-admin | semuanya |
| `operator@bencana.test` | bpbd-operator | melihat semua; boleh melewati geofence dengan alasan |
| `gudang@bencana.test` | warehouse-manager | mengelola ketiga gudang contoh |
| `koordinator@bencana.test` | camp-coordinator | koordinator posko `PSK-LMJ-01` |
| `relawan@bencana.test` | field-volunteer | tidak punya akses logistik |

| Endpoint | Siapa | Fungsi |
|---|---|---|
| `GET /demands` | semua yang login | Permintaan (koordinator: milik posko-nya saja), urut paling mendesak |
| `POST /camps/{camp}/demands` | koordinator posko itu | Ajukan kebutuhan. Boleh kirim `id` UUID dari perangkat (idempoten untuk mode offline) |
| `GET /demands/{demand}/allocation-plan` | pengelola gudang | Usulan alokasi: gudang terdekat, FEFO, kekurangan. Tidak mengubah stok |
| `POST /demands/{demand}/dispatches` | pengelola gudang | Buat pengiriman draft dan reservasi stok. Rencana dihitung ulang di server |
| `POST /dispatches/{id}/send` | pengelola gudang asal | Berangkat: stok gudang berkurang |
| `POST /dispatches/{id}/cancel` | pengelola gudang asal | Batalkan draft, lepas reservasi (`reason` wajib) |
| `POST /dispatches/{id}/receive` | koordinator posko tujuan | Terima. Wajib `latitude`, `longitude`; opsional `accuracy`, `received[]`, `notes` |
| `GET/POST /warehouses/{id}/stock` | pengelola gudang itu | Lihat / tambah stok per batch |

Kode galat: **409** status tidak cocok atau stok tidak cukup, **422** di luar geofence (`distance_m`, `allowed_m`) atau input salah, **403** tidak berhak.

Perintah pemeriksaan (juga berjalan harian pukul 02:00 dan mencatat log kritis bila gagal):

```bash
docker compose exec -u sail laravel.test php artisan audit:verify
docker compose exec -u sail laravel.test php artisan ledger:reconcile
```

Aturan alokasi: hanya stok bebas (tersedia dikurangi reservasi); barang mudah rusak yang kedaluwarsa kurang dari 7 hari dilewati; gudang diurutkan dari yang terdekat (garis lurus, bukan rute jalan); di dalam gudang batch yang lebih cepat kedaluwarsa dipakai lebih dulu (FEFO); maksimal 3 gudang sumber. Nilainya ada di `config/disaster.php`.

Geofence: serah terima hanya sah bila GPS penerima berada dalam 500 m dari posko, dikurangi akurasi yang dilaporkan perangkat (maksimal 100 m). Koordinat dari klien bisa dipalsukan, jadi ini pencegah, bukan jaminan.

## Jebakan tambahan (Sprint 4)

- Semua perubahan stok harus lewat `InventoryLedgerService`. Mengisi tabel langsung (termasuk di seeder) membuat saldo tidak cocok dengan ledger; `ledger:reconcile` akan melaporkannya.
- Waktu baris audit ikut di-hash, jadi kolom `created_at` audit berpresisi mikrodetik dan nilai JSON dinormalkan sebelum di-hash (PHP menyimpan `0.0` sebagai `0`).
- `as` kata terpesan PHP; jangan dipakai sebagai nama fungsi helper di tes.
- `APP_DEBUG=true` di lokal menampilkan stack trace pada galat 403/500. Di produksi wajib `false`.
- Relasi yang dimuat sebagian (`warehouse:id,code,name`) bisa membuat policy salah menilai karena kolom `manager_id` tidak ikut. Sertakan kolom yang dipakai policy.
- Di Inertia, `assertInertia` menegaskan berkas komponen halaman ada; tulis halamannya dulu sebelum tesnya dijalankan.
- **Pekerja antrean (Horizon) dan scheduler berjalan lama dan tidak membaca ulang `.env`.** Setelah mengubah `.env` (misalnya token Telegram), jalankan `docker compose restart horizon scheduler`. Gejalanya: job menganggap bot nonaktif padahal token sudah diisi.
- Channel private diotorisasi di `/api/broadcasting/auth` (grup `api` + Sanctum), memakai cookie sesi atau token Bearer. Echo mengirim token CSRF dari `<meta name="csrf-token">` di `app.blade.php`; jangan dihapus.
- Di tes, `Http::fake()` kedua **tidak** menimpa yang pertama (stub pertama yang cocok menang). Ganti total dengan `Http::swap(new Factory)` dulu.
- Di tes, channel broadcast terdaftar pada driver bawaan saat boot (`null` di `phpunit.xml`). Untuk menguji otorisasi channel, ganti driver lalu `require base_path('routes/channels.php')` lagi.
- Nama hak berisi titik (`dispatch.create`) tidak bisa dipakai di jalur `where('a.b.c')` pada tes Inertia; bandingkan lewat closure.

## Merek dan tampilan: IGI (Info Gempa Indonesia)

Nama situs adalah **IGI**. `APP_NAME=IGI` di `.env`. Nama aplikasi ikut membentuk nama cookie sesi, awalan Redis, awalan cache, dan awalan Horizon, jadi awalan lama dikunci di `.env` (`SESSION_COOKIE`, `REDIS_PREFIX`, `CACHE_PREFIX`, `HORIZON_PREFIX`). **Jangan dihapus**, atau sesi login dan antrean yang sedang berjalan terputus. Setelah mengubah `.env`, jalankan `docker compose restart horizon scheduler`.

**Tampilan dirombak (24 Okt 2026, malam)** agar terasa seperti aplikasi dan nyaman untuk semua usia. Aturan anti-slop tidak lagi dipakai pada UI atas permintaan pemilik; audit lamanya di `anti-slop/` tinggal arsip.

- **Sistem desain** ada di `resources/css/app.css`: huruf Plus Jakarta Sans (swa-host lewat `@fontsource-variable`), warna merek biru `brand` dengan kuning `sun` dan koral `coral`, sudut bulat besar, bayangan lembut. Mengubah nilai di blok `@theme` mengubah seluruh aplikasi, termasuk konsol petugas dan aplikasi lapangan, karena semua halaman memakai nama warna yang sama.
- **Kerangka**: bilah atas kaca buram, **bilah navigasi bawah di ponsel** (Beranda, Peta, Berita, Panduan, Masuk/Ruang kerja), dan tombol **Teks normal/besar** (tersimpan di perangkat) untuk pembaca yang lebih nyaman dengan huruf besar.
- **Gerak**: hero dengan gelombang dan riak bergerak (`HeroWaves.vue`), kartu yang muncul halus saat digulir (direktif `v-reveal`), angka yang menghitung naik (`CountUp.vue`), kartu yang terangkat saat disorot. Semuanya mati bila pengguna memilih "kurangi gerakan".
- **Video di beranda**: `FeaturedVideo.vue` memutar video resmi BMKG otomatis tanpa suara saat terlihat (suara dari pemutarnya). Bila pengguna memilih "kurangi gerakan" atau mode hemat data, video baru dimuat setelah tombol putar ditekan. Video di Panduan memakai gambar sampul dan dimuat saat diketuk.
- **Komponen bersama**: `PageHero.vue` (kepala halaman bergradasi), `Icon.vue` (ikon buatan sendiri), `BrandMark.vue`.
- **Ringkasan "Keadaan hari ini"** di beranda memberi satu kata (Relatif tenang, Waspada, Siaga) dari data 24 jam terakhir. Itu ringkasan IGI, bukan peringatan resmi.
- **Lainnya**: seismogram interaktif, penjelajah peta, skala magnitudo, tas siaga, "Dekat Anda", tab Panduan (bisa dengan panah papan ketik), peta pantai Natural Earth statis, ilustrasi SVG dan animasi gelombang buatan sendiri, ikon aplikasi dari lambang yang sama.
- **Video** dari kanal YouTube **Info BMKG** (delapan ID sudah diperiksa pemiliknya dan bisa disematkan); mengganti video: ubah `VIDEOS` di `Pages/Panduan.vue` atau `id` di `Pages/Beranda.vue`. Tidak ada foto bencana: foto butuh lisensi yang jelas.

## Masuk pertama kali (akun utama)

Akun demo lokal dibuat otomatis oleh seeder (`php artisan db:seed`) dan **hanya ada di lingkungan lokal**. Semuanya berkata sandi `password`:

| Email | Peran | Boleh apa |
|---|---|---|
| `admin@bencana.test` | Super admin | Segalanya, termasuk menyetujui pendaftar sebagai Operator BPBD |
| `operator@bencana.test` | Operator BPBD | Meninjau pendaftaran (peran lapangan dan gudang), pengumuman, stok, jejak audit |
| `gudang@bencana.test` | Pengelola gudang | Stok dan pengiriman |
| `koordinator@bencana.test` | Koordinator posko | Laporan dan kebutuhan posko |
| `relawan@bencana.test` | Relawan | Laporan lapangan |

**Mengapa pendaftar baru tidak langsung bisa masuk.** Akun baru dari `/daftar` berstatus *pending* tanpa peran. Itu disengaja: tanpa persetujuan, siapa pun bisa memberi dirinya akses ke data logistik. Tidak ada email konfirmasi (belum ada pengirim email); yang menyetujui adalah operator di menu **Pendaftaran**.

**Ayam dan telur (akun pertama).** Pendaftar pertama butuh penyetuju yang sudah ada. Jalannya: masuk sebagai `admin@bencana.test`, buka **Pendaftaran**, setujui akun Anda sebagai *Operator BPBD* (hanya super admin yang bisa memberi peran ini). Atau buat akun utama sendiri tanpa menunggu siapa pun:

```bash
docker compose exec -u sail laravel.test php artisan user:create emailanda@contoh.id "Nama Anda" --role=super-admin
```

Kata sandinya ditanyakan di layar. **Di server sungguhan, jangan pakai akun demo** (kata sandinya `password`); buat akun sendiri dengan `user:create` dan hapus atau ganti kata sandi akun demo lewat halaman Akun.

## Lupa dan ganti kata sandi

- **Ganti sendiri:** halaman **Akun**, bagian *Ganti kata sandi* (butuh kata sandi saat ini; minimal 10 karakter, huruf dan angka). Perangkat dan token lain ikut keluar; perangkat yang dipakai tetap masuk.
- **Lupa kata sandi:** operator membuka **Pendaftaran**, bagian *Petugas aktif*, lalu **Atur ulang kata sandi**. Kata sandi sementara (12 karakter) tampil **sekali**; sampaikan lewat jalur aman. Petugas wajib menggantinya saat masuk (halaman lain diblokir sampai itu selesai), dan semua sesi serta token lamanya diputus.
- **Siapa boleh mengatur ulang siapa:** operator untuk petugas lapangan dan gudang; hanya super admin untuk operator; super admin hanya lewat terminal (`user:create` untuk akun baru, atau `php artisan tinker`). Tidak ada yang mengatur ulang dirinya sendiri lewat jalur ini.
- Pembatas laju kini memakai kunci terpisah per rute (`throttle:6,1,gantisandi`, dan seterusnya). Pembatas bentuk angka di Laravel berbagi satu hitungan untuk semua rute pengguna yang sama, sehingga batas 6 per menit dulu terhitung dari semua panggilan API lain.
- Bahasa pesan validasi: `APP_LOCALE=id` dan `lang/id/validation.php` (aturan yang tak tercantum jatuh ke bahasa Inggris).

## Akun petugas: mendaftar, menyetujui, membuat

- **Mendaftar** di `/daftar` (tautan juga ada di halaman Masuk dan kaki situs). Pendaftar memilih peran yang *diminta* (relawan, koordinator posko, pengelola gudang), tetapi akun lahir **tanpa peran dan berstatus pending**, dan tidak bisa masuk sampai disetujui. Email yang sudah terdaftar dijawab sama persis (supaya formulir tidak bisa dipakai menebak siapa yang punya akun), kata sandi minimal 10 karakter dengan huruf dan angka, ada pembatas 6 pengajuan per jam per alamat dan jebakan robot.
- **Menyetujui** di konsol, menu **Pendaftaran** (`/logistik/petugas`), oleh operator BPBD atau super admin. Lencana di menu menunjukkan jumlah yang menunggu. Operator menetapkan peran sendiri (bukan yang diminta) dan tempat tugas: relawan wajib memilih posko, koordinator posko yang belum punya koordinator, pengelola gudang yang belum punya pengelola. Hanya super admin yang bisa mengangkat operator BPBD. Menolak wajib memakai alasan. Tidak ada email otomatis; pendaftar perlu dikabari operator.
- **Membuat langsung** (untuk admin; satu-satunya cara membuat operator atau super admin dari terminal):

```bash
docker compose exec -u sail laravel.test php artisan user:create email@contoh.id "Nama Lengkap" --role=bpbd-operator
```

Kata sandi ditanyakan di layar (tidak lewat argumen supaya tidak tersimpan di riwayat shell).

## Fitur publik tambahan

- **Interaksi di beranda**: seismogram bisa digeser untuk membaca waktu dan gempa (rentang 24 jam atau 7 hari, tombol Putar ulang); penjelajah peta menyaring menurut waktu dan kekuatan, menampilkan rincian dan posko dalam 300 km dari gempa yang dipilih, dan bisa dijelajahi dengan papan ketik (Tab, lalu Enter); skala magnitudo yang bisa digeser (kelas dan frekuensi mengikuti USGS); daftar centang tas siaga yang tersimpan di perangkat (localStorage, tidak dikirim ke server). Di ponsel peta dibuat selebar 760 px dan digeser mendatar agar lingkaran gempa cukup besar untuk diketuk.
- **Dekat Anda** (beranda): tombol "Cek gempa dan posko dekat saya" membaca lokasi hanya setelah ditekan, menghitung jarak di peramban, dan menampilkan gempa dalam 300 km (7 hari) dan tiga posko terdekat. Lokasi tidak dikirim ke server.
- **Tentang data** (`/tentang`): daftar sumber, kapan terakhir berhasil diambil (Normal atau Tertunda), hak cipta, dan batas data. Catatan keberhasilan diisi oleh `ingest:bmkg`, `ingest:usgs`, dan `news:fetch`.
- **Umpan Atom** (`/feed.xml`): 50 gempa terbaru dalam 7 hari, untuk pembaca RSS.
- **Aturan tampilan**: audit anti-slop ada di `anti-slop/audit-001-2026-10-24.md`. Target ketuk kontrol minimal 44 px di layar sentuh/sempit, angka magnitudo di latar terang memakai `magnitudeInk` (bukan `magnitudeColor`), dan tidak memakai em dash.

## Situs publik: beranda, berita, panduan, pengumuman

| Alamat | Isi |
|---|---|
| `/` | Beranda: gempa terbaru (siaran langsung), angka ringkas (gempa 24 jam/7 hari, M 5+, posko aktif, pengungsi tercatat), pengumuman, daftar berita terkini |
| `/peta` | Peta gempa dan posko (dulu di `/`) |
| `/berita` | Buletin kejadian dengan filter rentang waktu, kekuatan minimum, sumber, dan halaman |
| `/berita/{id}` | Satu kejadian: rincian, posko dalam radius dampak, gempa lain dalam 150 km dan ±3 hari |
| `/panduan` | Langkah keselamatan dan nomor darurat (112, 115, 117) |

"Berita" dibentuk otomatis dari data BMKG/USGS yang sudah tercatat (judul dan ringkasan disusun dari angka sumber di `resources/js/lib/berita.js`); tidak ada artikel tulisan tangan dan tidak ada sumber berita lain. Kabar yang ditulis manusia masuk lewat **pengumuman**.

**Pengumuman** diterbitkan operator BPBD (izin `announcement.manage`) di konsol, menu "Pengumuman" (`/logistik/pengumuman`): judul, isi, tingkat (info/penting/darurat), dan masa berlaku. Yang kedaluwarsa otomatis hilang dari situs publik. API: `GET /api/v1/announcements` dan `GET /api/v1/stats` (publik), `POST/DELETE /api/v1/announcements` dan `GET /api/v1/announcements/manage` (operator).

### Kabar dari BMKG dan BNPB

Bagian "Kabar dari BMKG dan BNPB" di `/berita` (dan 4 butir di beranda) berasal dari dua sumber resmi. Yang disimpan hanya judul, ringkasan satu kalimat, tanggal, dan tautan; isi lengkap tidak disalin. Setiap butir menampilkan lencana lembaga dan tautan "Baca selengkapnya" ke situs aslinya (tab baru), plus catatan atribusi di bawah daftar.

| Sumber | Cara | Jadwal | Masa tayang |
|---|---|---|---|
| BMKG, peringatan dini cuaca | RSS resmi `https://www.bmkg.go.id/alerts/nowcast/id` | tiap 15 menit | 24 jam (cepat basi) |
| BNPB, berita | Halaman daftar `https://www.bnpb.go.id/berita` dibaca (HTML) | tiap jam | 30 hari |

Perintah manual: `php artisan news:fetch` (atau `--source=bmkg` / `--source=bnpb`). Matikan satu sumber lewat `.env`: `NEWS_BMKG_ENABLED=false`, `NEWS_BNPB_ENABLED=false`.

Hal yang perlu diketahui:

- **BNPB tidak punya RSS yang bisa diakses program.** `bnpb.go.id` (tanpa `www`) memasang tantangan Cloudflare yang sengaja tidak dilewati; yang dipakai adalah daftar berita di `www.bnpb.go.id`. Ini membaca tampilan halaman, jadi **rapuh**: bila desain situsnya berubah, `news:fetch` melaporkan kegagalan (bukan "tidak ada berita") dan berita lama tetap tampil dengan penanda "mungkin tidak mutakhir" setelah 6 jam.
- **`robots.txt` BMKG melarang `/alerts` untuk crawler umum**, sedangkan RSS itu justru ditawarkan portal data terbuka BMKG (data.bmkg.go.id, "Peringatan Dini Cuaca") dan bertanda `public domain`. Diperlakukan sebagai endpoint data, diambil jarang dengan identitas jelas (`MonitoringBencanaAlam/0.1`). Bila BMKG meminta berhenti, set `NEWS_BMKG_ENABLED=false`.
- **Tautan hanya diterima bila `https` dan berada di host sumbernya** (`www.bmkg.go.id`, `www.bnpb.go.id`); yang lain dibuang dan dicatat.
- **Zona waktu**: sebagian butir RSS BMKG berlabel zona yang tidak konsisten. Waktu terbit disimpan sebagai UTC dan tidak boleh melewati saat pengambilan.
- Tautan peringatan BMKG mengarah ke berkas CAP XML resmi per peringatan (itu yang ditautkan feed-nya), bukan halaman web biasa.
- Setelah memperbarui kode di lingkungan yang sudah berjalan: `php artisan migrate`, `npm run build`, lalu `docker compose restart scheduler` (scheduler tidak membaca ulang jadwal sendiri).

Setelah memperbarui kode ke versi situs publik di lingkungan yang sudah berjalan: `php artisan migrate` dan `php artisan db:seed --class=RoleSeeder` (menambah izin baru), lalu `npm run build` bila memakai build produksi.

## Aplikasi lapangan offline (Sprint 6)

Halaman `/lapangan` (PWA, dirancang untuk ponsel) dipakai koordinator dan relawan posko. Login sama dengan konsol; setelah masuk, koordinator/relawan diarahkan ke sini. Bisa dipasang ke layar utama (tombol pasang muncul bila peramban mendukung).

Yang bisa dicatat: jumlah pengungsi, kondisi posko (aman/waspada/darurat), permintaan kebutuhan, dan penerimaan kiriman bantuan (dengan GPS).

**Alur offline.** Setiap laporan langsung disimpan di perangkat (IndexedDB, antrean `outbox`) dengan ID UUIDv7 buatan perangkat, lalu dikirim ke `POST /api/v1/sync/field-logs` begitu ada koneksi. Status "Offline" dan jumlah laporan menunggu tampil di bagian atas. Keluar akun menghapus antrean lokal dan cache (ada konfirmasi bila masih ada laporan belum terkirim).

**Semantik API sinkronisasi** (maks. 100 operasi per permintaan, tiap operasi diproses sendiri):

| Status | Arti | Perangkat |
|---|---|---|
| `applied` | diterima dan diterapkan | hapus dari antrean |
| `applied_stale` | diterima dan dicatat, tetapi ada laporan lebih baru dari posko yang sama sehingga tidak menimpa angka | hapus |
| `duplicate` | ID sudah pernah diterima (jawaban sebelumnya hilang di jalan) | hapus |
| `rejected` | ditolak permanen (bukan petugas posko itu, data tidak sah, terlalu tua) | hapus, tampilkan alasan |
| `retry` | galat sementara di server | coba lagi dengan jeda bertahap |

Aturan: laporan jumlah pengungsi memakai "yang terbaru menang" menurut jam perangkat (resolusi detik). Jam perangkat yang maju lebih dari 10 menit dianggap sekarang; laporan lebih tua dari 14 hari ditolak. Kondisi "darurat" menyiarkan peringatan ke operator hanya bila laporannya masih segar.

**Menugaskan relawan ke posko** (belum ada antarmuka, hanya perintah):

```bash
docker compose exec -T -u sail laravel.test php artisan camp:assign-volunteer PSK-LMJ-01 relawan@bencana.test
docker compose exec -T -u sail laravel.test php artisan camp:assign-volunteer PSK-LMJ-01 relawan@bencana.test --remove
```

Koordinator posko otomatis boleh melapor untuk posko yang dikoordinasikannya.

**Batas laju** diatur lewat `.env`: `RATE_LIMIT_PUBLIC` (60/menit), `RATE_LIMIT_AUTHENTICATED` (120), `RATE_LIMIT_LOGIN` (5).

**Tes.** `php artisan test` untuk PHP, `npm run test:sw` untuk logika service worker (12 tes Node).

**Uji beban.** Lihat `loadtest/README.md`.

### Jebakan Sprint 6

- Service worker (`public/sw.js`) hanya didaftarkan pada build produksi (`npm run build`), tidak pada `npm run dev`. Reload offline lewat service worker **belum terbukti di peramban sungguhan**: panel peramban bawaan Claude menolak pendaftarannya. Logika service worker diuji lewat `tests/js/sw.test.mjs`; uji manual di Chrome/Android tetap perlu sebelum dipakai di lapangan.
- Sinkronisasi hanya berjalan selama halaman/aplikasi terbuka; belum ada Background Sync saat aplikasi tertutup.
- Menghapus IndexedDB Dexie lalu aplikasi Inertia tetap memegang modulnya: setelah `db.delete()` wajib `db.open()` lagi (sudah ditangani di `wipeLocalData()`).
- Hasil per operasi di `SyncController` disimpan per urutan kiriman, bukan per ID, karena ID yang sama bisa muncul dua kali dalam satu kiriman.
- Jam perangkat dipercaya (dengan pembatas); perangkat dengan jam salah bisa menang atas laporan yang benar di rentang 10 menit.
