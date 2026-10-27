# IGI untuk Android

Pembungkus Capacitor untuk situs IGI (Info Gempa Indonesia). Aplikasi ini **memuat situs dari server**; tidak berisi data atau logika sendiri. Karena itu APK hanya berfungsi selama HP bisa menjangkau server IGI.

## Membangun APK uji

Dari folder ini (PowerShell):

```powershell
npm install        # sekali saja
npm run apk        # menghasilkan igi-debug.apk
```

Bawaannya memuat `http://192.168.100.182:8000` (PC pengembang di Wi-Fi yang sama). Alamat lain:

```powershell
$env:IGI_URL = "http://192.168.1.20:8000"; npm run apk
```

`npm run apk` memakai JDK 17 dan Android SDK yang sudah ada di PC, dan menyimpan cache Gradle di `D:\Cache\gradle` (drive C kecil). Capacitor versi 6 dipilih karena versi 7 ke atas butuh JDK 21.

## Memasang ke HP

1. Salin `igi-debug.apk` ke HP (kabel USB atau kirim lewat aplikasi pesan).
2. Buka berkasnya di HP, izinkan "pasang dari sumber ini", lalu pasang.
3. Atau lewat USB bila sudah ada otorisasi debugging: `adb install -r igi-debug.apk`.

Pastikan server IGI berjalan dan HP satu jaringan dengan PC. Bila server tidak terjangkau, aplikasi menampilkan halaman cadangan berbahasa Indonesia dengan tombol "Coba lagi".

## Memperbarui aplikasi yang sudah terpasang

Aplikasi ini memuat situs dari server, jadi ada dua jenis pembaruan:

| Yang berubah | Perlu APK baru? | Caranya |
|---|---|---|
| Tampilan, halaman, fitur, teks, data (semua yang ada di Laravel dan Vue) | **Tidak** | Di server: `npm run build` (dalam kontainer). Di HP, tutup lalu buka IGI lagi; nama berkas aset berubah tiap build sehingga versi terbaru langsung dimuat |
| Ikon, nama aplikasi, layar pembuka, warna bilah status, alamat server, plugin Capacitor, izin | **Ya** | `npm run apk`, lalu pasang APK baru **di atas** yang lama (tanpa menghapus) |

Memasang di atas yang lama: `adb install -r igi-debug.apk`, atau buka berkas APK-nya di HP dan ketuk Pasang ("Perbarui"). Data aplikasi di HP (sesi masuk dan laporan yang belum terkirim) ikut bertahan.

Syarat agar Android menerimanya sebagai pembaruan:
- **`applicationId` sama** (`id.igi.gempa`).
- **Nomor versi lebih tinggi.** `npm run apk` mengisinya otomatis (menit sejak 1970), jadi selalu naik. Versi terpasang dilihat dengan `adb shell dumpsys package id.igi.gempa`.
- **Tanda tangan sama.** Kunci debug ada di `C:\Users\USER\.android\debug.keystore` (sidik jari SHA-256 `fb650b8b...`). Cadangannya di `D:\Cache\kunci-apk\debug.keystore`. Bila kunci hilang atau APK dibangun di PC lain dengan kunci berbeda, Android menolak ("aplikasi tidak dipasang" atau `INSTALL_FAILED_UPDATE_INCOMPATIBLE`) dan satu-satunya jalan adalah menghapus aplikasi dulu, yang **menghapus laporan yang belum terkirim**. Untuk rilis, buat kunci rilis sendiri, simpan di tempat aman dengan cadangan, dan jangan pernah ganti.

## Sudah diuji di perangkat

Infinix X6837, Android 13 (24 Okt 2026): terpasang lewat `adb install`, terbuka di Beranda, memuat situs dari PC lewat Wi-Fi, ikon dan layar pembuka tampil. Halaman cadangan ("IGI belum bisa dibuka" dengan tombol "Coba lagi") tampil pada galat jaringan yang cepat. Bila server mati dan firewall menjatuhkan paket diam-diam, layar biru polos bisa bertahan sampai batas waktu WebView habis (puluhan detik) sebelum halaman cadangan muncul.

Catatan perangkat: HP ini punya ruang kedua (XSpace, pengguna 999), jadi perintah `adb shell pm` perlu `--user 0`. Tangkapan layar diambil dengan `adb shell screencap` lalu `adb pull` (mengalihkan keluaran `adb exec-out` lewat PowerShell merusak berkas biner).

## Batas yang perlu diketahui

- **Hanya untuk uji.** APK ini bertanda tangan kunci debug dan memakai lalu lintas `http` polos ke alamat jaringan lokal. Itu tidak boleh dirilis. Build rilis wajib memakai alamat `https` (opsi `cleartext` mati otomatis) dan kunci tanda tangan sendiri.
- **Service worker.** Pada alamat `http://` biasa, WebView tidak mengaktifkan service worker, sehingga memuat ulang halaman tanpa sinyal tidak bisa menampilkan halaman Lapangan. Laporan yang dibuat saat offline tetap tersimpan di HP (IndexedDB) dan terkirim sendiri setelah tersambung, selama halaman Lapangan sudah terbuka. Untuk offline penuh, server perlu domain HTTPS.
- **Siaran langsung.** WebSocket diarahkan ke `localhost` oleh build situs; dari HP ia tidak tersambung. Tidak memengaruhi laporan.
- **Alamat berubah.** Bila IP PC berubah, bangun ulang APK dengan `IGI_URL` yang baru.
- **Lambang.** Ikon dan layar pembuka dibuat dari lambang IGI (`scripts` tidak menyimpannya; dibuat sekali dan hasilnya ada di `android/app/src/main/res`).

## Isi folder

- `capacitor.config.js`: alamat server dan pengaturan aplikasi.
- `scripts/build-www.mjs`: membuat halaman cadangan di `www/`.
- `scripts/build-apk.mjs`: sinkronisasi dan Gradle.
- `android/`: proyek Android hasil Capacitor (`android/app/build` dan `igi-debug.apk` tidak perlu disimpan di git).

## Rilis untuk dibagikan (APK + AAB bertanda tangan)

Syarat: IGI harus berjalan di **alamat https publik** (domain + sertifikat), karena aplikasi hanya membungkus situs; alamat Wi-Fi/IP lokal tidak bisa dibuka orang lain.

```powershell
$env:IGI_URL = "https://igi.contoh.id"; npm run apk:release
```

Hasil: `igi-release.apk` (dibagikan langsung) dan `igi-release.aab` (unggah ke Play Store). Skrip menolak alamat non-https.

Kunci rilis: `D:\Cache\kunci-apk\igi-release.jks` dengan sandi di `igi-release.properties` (di luar repositori; dibaca lewat variabel `IGI_SIGNING_PROPS`). **Cadangkan kedua berkas ke tempat aman (mis. flash disk, pengelola sandi).** Bila hilang, aplikasi tidak bisa diperbarui di atas yang terpasang dan tidak bisa diperbarui di Play Store. Jangan kirim berkas itu ke siapa pun. Sidik jari SHA-256 sertifikat: `CB:DC:33:E1:E3:F0:B9:08:C5:C4:8F:83:71:E0:FC:03:2D:AE:A0:D2:76:3D:2C:DF:FA:7B:DE:8F:61:A1:CC:45`.

APK rilis memakai kunci berbeda dari APK debug, jadi HP yang sudah memasang `igi-debug.apk` harus menghapusnya dulu (laporan offline yang belum terkirim hilang).
