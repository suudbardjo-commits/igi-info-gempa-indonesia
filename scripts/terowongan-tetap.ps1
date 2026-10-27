# Menyiapkan terowongan Cloudflare BERNAMA (alamat tetap) untuk IGI.
# Jalankan SETELAH: (1) domain Anda aktif di akun Cloudflare gratis, (2) `cloudflared tunnel login` selesai (Anda yang masuk di peramban).
#
#   powershell -ExecutionPolicy Bypass -File scripts\terowongan-tetap.ps1 -Hostname igi.contoh.id
#   powershell -ExecutionPolicy Bypass -File scripts\terowongan-tetap.ps1 -Hostname igi.contoh.id -Jalankan   # langsung dinyalakan
param(
    [Parameter(Mandatory = $true)][ValidatePattern('^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$')][string]$Hostname,
    [string]$Nama = 'igi',
    [string]$Layanan = 'http://localhost:8000',
    [switch]$Jalankan
)

$ErrorActionPreference = 'Stop'
$cf = 'D:\Cache\cloudflared\cloudflared.exe'
$home_cf = Join-Path $env:USERPROFILE '.cloudflared'
if (-not (Test-Path $cf)) { throw "cloudflared tidak ada di $cf" }
if (-not (Test-Path (Join-Path $home_cf 'cert.pem'))) {
    throw "Belum login. Jalankan dulu:  $cf tunnel login   (pilih domain Anda di peramban yang terbuka)"
}

# 1. Buat terowongan bernama (lewati bila sudah ada).
$ada = & $cf tunnel list --output json | ConvertFrom-Json | Where-Object { $_.name -eq $Nama }
if (-not $ada) { & $cf tunnel create $Nama | Out-Host; $ada = & $cf tunnel list --output json | ConvertFrom-Json | Where-Object { $_.name -eq $Nama } }
$id = $ada.id
$kredensial = Join-Path $home_cf "$id.json"
if (-not (Test-Path $kredensial)) { throw "Berkas kredensial terowongan tidak ditemukan: $kredensial" }

# 2. Arahkan nama domain ke terowongan (membuat CNAME di Cloudflare).
& $cf tunnel route dns --overwrite-dns $Nama $Hostname | Out-Host

# 3. Tulis konfigurasi. Berkas kredensial TIDAK disalin; hanya dirujuk dari lokasi aslinya.
$config = Join-Path $home_cf 'config.yml'
@"
tunnel: $id
credentials-file: $kredensial
no-autoupdate: true
ingress:
  - hostname: $Hostname
    service: $Layanan
  - service: http_status:404
"@ | Set-Content -Encoding ascii $config
Write-Host "Konfigurasi ditulis: $config"
Write-Host "Alamat tetap IGI: https://$Hostname"

# 4. Isi alamat di pengaturan aplikasi (tanpa rahasia).
$envFile = 'D:\Davin Project\Web\Monitoring Bencana Alam\.env'
if (Test-Path $envFile) {
    (Get-Content $envFile) -replace '^APP_URL=.*', "APP_URL=https://$Hostname" | Set-Content -Encoding utf8 $envFile
    Write-Host 'APP_URL di .env diperbarui.'
}

if ($Jalankan) { & $cf tunnel --config $config run $Nama }
else { Write-Host "Nyalakan dengan:  $cf tunnel --config `"$config`" run $Nama" }
