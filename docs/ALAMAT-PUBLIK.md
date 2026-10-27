# Alamat publik sementara (Cloudflare Tunnel)

Memberi IGI alamat https gratis tanpa akun: `https://<kata-acak>.trycloudflare.com`. Hanya yang memegang tautannya yang bisa membukanya (halaman memakai `X-Robots-Tag: none`, tidak diindeks mesin pencari).

Menyalakan (PC dan Docker harus hidup):
```powershell
D:\Cache\cloudflared\cloudflared.exe tunnel --no-autoupdate --url http://localhost:8000
```
Alamatnya tercetak di keluaran (baris `https://....trycloudflare.com`).

Batasan:
- **Alamat berubah setiap terowongan dimulai ulang** (PC restart, jendela ditutup). Tautan dan APK lama lalu mati.
- Situs hanya hidup selama PC ini menyala dan terowongan berjalan. Layanan gratis tanpa jaminan ketersediaan.
- Siaran gempa langsung (WebSocket/Reverb di port 8080) tidak ikut terowongan; di akses jarak jauh status "Siaran terputus", data tetap muncul lewat muat ulang.
- Alamat tetap butuh akun Cloudflare gratis + domain milik sendiri (terowongan bernama).

Keamanan yang sudah diterapkan sebelum dibuka: `APP_DEBUG=false`; 5 akun demo `*@bencana.test` dinonaktifkan (status `pending`, token dicabut; aktifkan lagi dengan `account_status='approved'` hanya untuk uji lokal); `trustProxies` agar cookie dan tautan memakai https.
