# Menyalakan seluruh server IGI setelah komputer dihidupkan: Docker Desktop, kontainer, lalu terowongan publik.
# Terowongan: ngrok dengan domain statis (alamat TETAP) bila D:\Cache\ngrok-domain.txt ada dan ngrok sudah login;
# selain itu terowongan Cloudflare sementara (alamat berganti tiap menyala).
# Alamat publik dicetak, disalin ke clipboard, dan disimpan di D:\Cache\alamat-server.txt.
#
#   powershell -ExecutionPolicy Bypass -File scripts\nyalakan-server.ps1
$ErrorActionPreference = 'Stop'
$proyek = Split-Path -Parent $PSScriptRoot
$docker = 'D:\Docker\Docker Desktop.exe'
$cf = 'D:\Cache\cloudflared\cloudflared.exe'
$log = 'D:\Cache\tunnel.log'
$simpan = 'D:\Cache\alamat-server.txt'
$ngrok = 'D:\Cache\ngrok\ngrok.exe'
$berkasDomain = 'D:\Cache\ngrok-domain.txt' # satu baris: domain statis gratis ngrok, mis. nama-anda.ngrok-free.dev
$logNgrok = 'D:\Cache\ngrok.log'

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

$alamat = $null

if ((Test-Path $berkasDomain) -and (Test-Path $ngrok)) {
    Write-Host '4/4 Terowongan ngrok (alamat tetap)...'
    $domain = ((Get-Content $berkasDomain -Raw).Trim() -replace '^https?://', '') -replace '/.*$', ''
    if (-not (Get-Process ngrok -ErrorAction SilentlyContinue)) {
        Start-Process $ngrok -ArgumentList 'http', "--url=$domain", '8000' -WindowStyle Hidden -RedirectStandardOutput $logNgrok -RedirectStandardError ($logNgrok + '.err')
    }
    $hdr = @{ 'ngrok-skip-browser-warning' = '1' }
    if (Tunggu 'ngrok' { (Invoke-WebRequest "https://$domain/api/v1/ping" -UseBasicParsing -TimeoutSec 8 -Headers $hdr).StatusCode -eq 200 } 90) {
        $alamat = "https://$domain"
    } else {
        Write-Host 'ngrok gagal (belum login, domain salah, atau akun dipakai di perangkat lain). Memakai terowongan Cloudflare sementara.' -ForegroundColor Yellow
    }
}

if (-not $alamat) {
    Write-Host '4/4 Terowongan Cloudflare (alamat sementara)...'
    if (-not (Test-Path $cf)) { throw "cloudflared tidak ada di $cf" }
    if (-not (Get-Process cloudflared -ErrorAction SilentlyContinue)) {
        if (Test-Path $log) { Remove-Item $log -Force }
        # Keluaran cloudflared ada di stderr, jadi dialihkan ke berkas untuk dibaca alamatnya.
        Start-Process $cf -ArgumentList 'tunnel', '--no-autoupdate', '--url', 'http://localhost:8000' -WindowStyle Hidden -RedirectStandardError $log
    }
    for ($i = 0; $i -lt 20 -and -not $alamat; $i++) {
        Start-Sleep -Seconds 3
        if (Test-Path $log) {
            $m = Select-String -Path $log -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' | Select-Object -Last 1
            if ($m) { $alamat = $m.Matches[0].Value }
        }
    }
}
if (-not $alamat) { Write-Host 'GAGAL: alamat terowongan tidak ditemukan.' -ForegroundColor Red; exit 1 }

Set-Content -Path $simpan -Value $alamat -Encoding ascii
Set-Clipboard -Value $alamat

# Alamat berbeda dari yang tertanam di aplikasi: tanam alamat baru dan kirim lewat EAS Update. HP mengambilnya sendiri saat
# aplikasi dibuka (butuh sudah login EAS di komputer ini; bila belum, langkah ini dilewati dan alamat bisa diketik manual).
$eas = Join-Path $proyek 'aplikasi-expo\eas.json'
$appJson = Join-Path $proyek 'aplikasi-expo\app.json'
if ((Test-Path $eas) -and (Test-Path $appJson)) {
    $isi = [IO.File]::ReadAllText($eas)
    $isiApp = [IO.File]::ReadAllText($appJson)
    if (($isi -notmatch [regex]::Escape($alamat)) -or ($isiApp -notmatch [regex]::Escape($alamat))) {
        Write-Host 'Alamat berbeda dari yang ada di aplikasi: mengirim pembaruan ke HP (sekitar 1 menit)...'
        # eas.json hanya berlaku untuk build APK; app.json extra.igiUrl yang ikut pembaruan OTA (variabel EAS "preview" kosong).
        $baru = [regex]::Replace($isi, '("EXPO_PUBLIC_IGI_URL":\s*")[^"]+(")', ('${1}' + $alamat + '${2}'))
        [IO.File]::WriteAllText($eas, $baru)
        $baruApp = [regex]::Replace($isiApp, '("igiUrl":\s*")[^"]+(")', ('${1}' + $alamat + '${2}'))
        [IO.File]::WriteAllText($appJson, $baruApp)
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
Write-Host 'Di aplikasi HP: bila tidak tersambung, Beranda > Ubah alamat server > tempel alamat ini.'
