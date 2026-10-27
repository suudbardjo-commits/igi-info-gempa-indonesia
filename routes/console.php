<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// BMKG: permintaan murah karena memakai ETag (304 kalau data belum berubah).
Schedule::command('ingest:bmkg --feed=auto')->everyMinute()->withoutOverlapping()->runInBackground();
Schedule::command('ingest:bmkg --feed=terkini')->everyTwoMinutes()->withoutOverlapping()->runInBackground();

// USGS: pembanding global, cukup tiap 5 menit.
Schedule::command('ingest:usgs')->everyFiveMinutes()->withoutOverlapping()->runInBackground();

// Berita lembaga resmi. Peringatan cuaca cepat basi sehingga lebih sering; daftar berita BNPB cukup tiap jam
// (halaman HTML, jangan dibebani).
Schedule::command('news:fetch --source=bmkg')->everyFifteenMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('news:fetch --source=bnpb')->hourly()->withoutOverlapping()->runInBackground();

// Baca pesan masuk ke bot Telegram (tautan akun). Tidak melakukan apa-apa bila token belum diisi.
Schedule::command('telegram:poll')->everyMinute()->withoutOverlapping()->runInBackground();

// Pemeriksaan integritas harian: rantai audit dan kecocokan saldo stok dengan ledger.
// Kegagalan dicatat sebagai log kritis supaya terlihat di pemantauan.
foreach (['audit:verify' => 'Rantai audit logistik rusak', 'ledger:reconcile' => 'Saldo stok tidak cocok dengan ledger'] as $command => $message) {
    Schedule::command($command)->dailyAt('02:00')->onFailure(fn () => Log::critical($message));
}

// Antrean Horizon: ambil snapshot metrik untuk grafik dasbor.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Menjaga cache peringatan dini BMKG tetap hangat supaya permintaan pengguna jarang menunggu penyedia.
Schedule::command('weather:alerts')->everyThreeMinutes()->withoutOverlapping()->runInBackground();
