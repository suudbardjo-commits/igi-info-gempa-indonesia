# Alamat tetap untuk IGI (Cloudflare gratis + domain)

Hasil akhirnya: `https://igi.domainanda.id` yang tidak berubah, sehingga APK dan tautan yang dibagikan tetap hidup.
Yang gratis: akun Cloudflare, terowongan, dan sertifikat https. Yang berbayar: **domain** (nama seperti `domainanda.id`), biasanya puluhan ribu rupiah per tahun (cek harga di registrar).

## A. Langkah yang harus Anda lakukan sendiri (saya tidak membuat akun atau memasukkan sandi)
1. **Beli domain** di registrar mana pun (mis. domain `.my.id`/`.id`/`.com`). Domain murah cukup.
2. **Buat akun Cloudflare gratis**: https://dash.cloudflare.com/sign-up
3. Di Cloudflare pilih **Add a domain**, masukkan domain Anda, pilih paket **Free**.
4. Cloudflare memberi dua *nameserver*. Di registrar, ganti nameserver domain ke dua alamat itu. Tunggu hingga Cloudflare menyatakan domain **Active** (menit sampai beberapa jam).
5. Di PowerShell, login sekali (peramban terbuka, pilih domain Anda lalu Authorize):
   ```powershell
   D:\Cache\cloudflared\cloudflared.exe tunnel login
   ```

## B. Langkah yang sudah saya siapkan (satu perintah)
```powershell
cd "D:\Davin Project\Web\Monitoring Bencana Alam"
powershell -ExecutionPolicy Bypass -File scripts\terowongan-tetap.ps1 -Hostname igi.domainanda.id -Jalankan
```
Skrip: membuat terowongan bernama `igi`, membuat catatan DNS `igi.domainanda.id`, menulis `~\.cloudflared\config.yml` (menuju `http://localhost:8000`), mengisi `APP_URL`, lalu menyalakan terowongan. Berkas kredensial terowongan (`~\.cloudflared\<id>.json`) rahasia: jangan dikirim atau dimasukkan ke repositori.

Setelah itu, jalankan `docker compose exec -u sail laravel.test php artisan config:clear`.

## C. Menyala otomatis saat PC hidup (opsional)
Sebagai administrator:
```powershell
D:\Cache\cloudflared\cloudflared.exe service install
```
Layanan Windows memakai `config.yml` di profil pengguna; bila gagal membaca, salin `config.yml` dan berkas `<id>.json` ke `C:\Windows\System32\config\systemprofile\.cloudflared\` dan ubah jalur `credentials-file` di dalamnya. Docker Desktop juga harus menyala otomatis (pengaturan Docker: *Start when you sign in*), lalu `docker compose up -d` (kontainer sudah `restart: unless-stopped` bila diatur).

## D. Setelah alamat tetap ada
1. Uji: buka `https://igi.domainanda.id/cuaca` dari HP dengan data seluler.
2. Isi `EXPO_PUBLIC_IGI_URL` di `aplikasi-expo/eas.json` (profil `preview` dan `production`) dengan alamat itu, lalu `npx eas-cli build -p android --profile preview`.
3. Batasan yang tetap: situs hanya hidup selama PC ini menyala dan terhubung internet; WebSocket siaran langsung (port 8080) tidak ikut terowongan kecuali ditambahkan ingress terpisah.
4. Jangan aktifkan lagi akun demo `*@bencana.test` selama situs terbuka ke internet.
