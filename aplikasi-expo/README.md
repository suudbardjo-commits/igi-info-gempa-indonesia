# IGI (Expo) — aplikasi native yang memakai API Laravel

React Native/Expo di sini **hanya klien**: layar Pantauan (daftar, peta Leaflet di WebView, posko), Cuaca (cari tempat atau pilih per wilayah), Peringatan dini, dan Berita mengambil seluruh datanya dari API Laravel (`/api/v1`, lihat docs/SUMBER-DATA-API.md). Tab "Situs" membuka situs lengkap untuk login petugas, logistik, dan laporan lapangan. Tidak ada data atau logika bisnis di aplikasi.

Pengganti `../aplikasi-android` (Capacitor), yang dibiarkan sebagai cadangan.

## Syarat
- IGI berjalan di **alamat https publik** (APK yang dibagikan akan kosong tanpa itu).
- Akun Expo (boleh yang sama dengan Lab Detektif Sains).

## Mencoba dulu di HP (Expo Go, alamat lokal)
Expo Go SDK 57, HP satu Wi-Fi dengan PC:
```powershell
$env:EXPO_PUBLIC_IGI_URL = "http://192.168.100.182:8000"; npx expo start
```
Alamat http hanya diterima pada mode pengembangan; build rilis menolaknya dan menampilkan layar "Alamat server belum diatur".

## Membangun untuk dibagikan
1. Ubah `EXPO_PUBLIC_IGI_URL` di `eas.json` (profil `preview` dan `production`) ke alamat https IGI. Ini alamat publik, bukan rahasia.
2. Masuk dan hubungkan proyek (sekali):
   ```powershell
   npx eas-cli login
   npx eas-cli init
   ```
3. APK untuk dibagikan lewat tautan/QR:
   ```powershell
   npx eas-cli build -p android --profile preview
   ```
   EAS memberi halaman unduh; kirim tautannya ke siapa pun.
4. AAB untuk Play Store: `npx eas-cli build -p android --profile production`, lalu `eas submit`.

Kunci penandatangan dibuat dan disimpan EAS (jawab "ya" saat ditanya). Jaga akun Expo Anda; kunci terikat padanya.

## Isi
- `App.tsx`: WebView, tombol kembali Android, tarik-untuk-muat-ulang, halaman galat "Coba lagi", tautan luar (BMKG/BNPB) dan unduhan CSV dibuka di peramban, telepon/surel di aplikasi bawaan.
- Izin: hanya lokasi perkiraan (dipakai saat pengguna menekan "Gunakan lokasi perangkat"); mikrofon dan lokasi presisi diblokir.
- ID paket `id.igi.gempa` sama dengan APK Capacitor, jadi **kunci penandatangan berbeda**: HP yang sudah memasang APK lama harus menghapusnya dulu.

## Diperiksa
`tsc --noEmit` bersih, `expo-doctor` 21/21, `expo export --platform android` berhasil. Belum diuji di HP atau lewat `eas build` (butuh login Anda dan alamat publik).
