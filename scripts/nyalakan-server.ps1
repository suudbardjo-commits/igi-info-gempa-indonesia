# Menyalakan seluruh server IGI setelah komputer dihidupkan: Docker Desktop, kontainer, lalu terowongan Cloudflare.
# Alamat publik dicetak, disalin ke clipboard, dan disimpan di D:\Cache\alamat-server.txt.
#
#   powershell -ExecutionPolicy Bypass -File scripts\nyalakan-server.ps1
#
# Alamat terowongan GRATIS berganti setiap komputer dinyalakan ulang. Untuk alamat tetap lihat docs\ALAMAT-TETAP.md.
$ErrorActionPreference = 'Stop'
$proyek = Split-Path -Parent $PSScriptRoot
$docker = 'D:\Docker\Docker Desktop.exe'
$cf = 'D:\Cache\cloudflared\cloudflared.exe'
$log = 'D:\Cache\tunnel.log'
$simpan = 'D:\Cache\alamat-server.txt'

function Tunggu($nama, [scriptblock]$siap, $detik) {
    for ($i = 0; $i -lt $detik / 3; $i++) {
        try { if (& $siap) { return $true } } catch { }
        Start-Sleep -Seconds 3
    }
    Write-Host "GAGAL: $nama belum siap setelah $detik detik." -ForegroundColor Red
    return $false
}

Write-Host '1/4 Docker Desktop...'
if (-not (Get-Process 'Docker Desktop' -ErrorAction SilentlyContinue)) {
    if (-not (Test-Path $docker)) { throw "Docker Desktop tidak ada di $docker" }
    Start-Process $docker
}
if (-not (Tunggu 'Docker' { docker info *> $null; $LASTEXITCODE -eq 0 } 240)) { exit 1 }

Write-Host '2/4 Kontainer IGI...'
Push-Location $proyek
docker compose up -d
Pop-Location

Write-Host '3/4 Menunggu situs menjawab di localhost:8000...'
if (-not (Tunggu 'Situs' { (Invoke-WebRequest 'http://localhost:8000/api/v1/ping' -UseBasicParsing -TimeoutSec 5).StatusCode -eq 200 } 240)) { exit 1 }

Write-Host '4/4 Terowongan Cloudflare...'
if (-not (Test-Path $cf)) { throw "cloudflared tidak ada di $cf" }
$jalan = Get-Process cloudflared -ErrorAction SilentlyContinue
if (-not $jalan) {
    if (Test-Path $log) { Remove-Item $log -Force }
    # Keluaran cloudflared ada di stderr, jadi dialihkan ke berkas untuk dibaca alamatnya.
    Start-Process $cf -ArgumentList 'tunnel', '--no-autoupdate', '--url', 'http://localhost:8000' -WindowStyle Hidden -RedirectStandardError $log
}
$alamat = $null
for ($i = 0; $i -lt 20 -and -not $alamat; $i++) {
    Start-Sleep -Seconds 3
    if (Test-Path $log) {
        $m = Select-String -Path $log -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' | Select-Object -Last 1
        if ($m) { $alamat = $m.Matches[0].Value }
    }
}
if (-not $alamat) { Write-Host 'GAGAL: alamat terowongan tidak ditemukan di log.' -ForegroundColor Red; exit 1 }

Set-Content -Path $simpan -Value $alamat -Encoding ascii
Set-Clipboard -Value $alamat

# Alamat berganti: tanam alamat baru di aplikasi dan kirim lewat EAS Update. HP mengambilnya sendiri saat aplikasi dibuka
# (butuh sudah login EAS di komputer ini; bila belum, langkah ini dilewati dan alamat bisa diketik manual di aplikasi).
$eas = Join-Path $proyek 'aplikasi-expo\eas.json'
if (Test-Path $eas) {
    $isi = [IO.File]::ReadAllText($eas)
    if ($isi -notmatch [regex]::Escape($alamat)) {
        Write-Host 'Alamat berubah: mengirim pembaruan aplikasi ke HP (sekitar 1 menit)...'
        [IO.File]::WriteAllText($eas, [regex]::Replace($isi, 'https://[a-z0-9-]+\.trycloudflare\.com', $alamat))
        Push-Location (Join-Path $proyek 'aplikasi-expo')
        npx.cmd --yes eas-cli update --branch preview --environment preview --message "Alamat server otomatis" --non-interactive
        $ok = ($LASTEXITCODE -eq 0)
        Pop-Location
        if ($ok) { Write-Host 'Pembaruan aplikasi terkirim. Buka aplikasi di HP; alamat baru terpakai otomatis.' -ForegroundColor Green }
        else { Write-Host 'Pembaruan aplikasi GAGAL (belum login EAS atau tidak ada internet). Ketik alamat di aplikasi secara manual.' -ForegroundColor Yellow }
    }
}
Write-Host ''
Write-Host 'SERVER IGI MENYALA' -ForegroundColor Green
Write-Host "Alamat: $alamat"
Write-Host '(sudah disalin ke clipboard; tersimpan di D:\Cache\alamat-server.txt)'
Write-Host 'Di aplikasi HP: Beranda > Ubah alamat server > tempel alamat ini.'
