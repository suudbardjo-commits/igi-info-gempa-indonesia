<?php

use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CampController;
use App\Http\Controllers\Api\DemandController;
use App\Http\Controllers\Api\DisasterController;
use App\Http\Controllers\Api\DispatchController;
use App\Http\Controllers\Api\FieldController;
use App\Http\Controllers\Api\FieldReportController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\LogisticsSummaryController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\RegistrationReviewController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\TelegramLinkController;
use App\Http\Controllers\Api\DisasterExportController;
use App\Http\Controllers\Api\WarehouseController;
use App\Http\Controllers\Api\WeatherController;
use App\Http\Controllers\Api\WarehouseStockController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('ping', fn () => ['status' => 'ok', 'time' => now()->toIso8601String()]);

    // Data publik, hanya baca.
    Route::middleware('throttle:'.config('disaster.rate_limits.public').',1')->group(function () {
        Route::get('disasters', [DisasterController::class, 'index']);
        Route::get('disasters/{id}', [DisasterController::class, 'show'])->whereUuid('id');
        Route::get('camps', [CampController::class, 'index']);
        Route::get('stats', StatsController::class);
        Route::get('announcements', [AnnouncementController::class, 'index']);
        Route::get('sources/status', [WeatherController::class, 'sources']);
        Route::get('news', \App\Http\Controllers\Api\NewsController::class);
        Route::get('disasters-export', DisasterExportController::class)->middleware('throttle:10,1,ekspor');

        // Cuaca dan peringatan dini: memanggil penyedia luar lewat cache, jadi diberi batas sendiri yang lebih ketat.
        Route::middleware('throttle:40,1,cuaca')->group(function () {
            Route::get('locations', [WeatherController::class, 'locations']);
            Route::get('areas', \App\Http\Controllers\Api\AreaController::class);
            Route::get('weather', [WeatherController::class, 'weather']);
            Route::get('weather-alerts', [WeatherController::class, 'alerts']);
        });
    });

    Route::post('auth/token', [AuthController::class, 'login'])->middleware('throttle:'.config('disaster.rate_limits.login').',1,apilogin');

    // Perlu login (token Sanctum). Hak akses tiap aksi ditegakkan oleh policy di controller.
    Route::middleware(['auth:sanctum', 'throttle:'.config('disaster.rate_limits.authenticated').',1', RequirePasswordChange::class])->group(function () {
        // Satu-satunya yang boleh dilakukan akun berkata sandi sementara: melihat dirinya, keluar, dan mengganti kata sandi.
        Route::get('auth/me', [AuthController::class, 'me'])->withoutMiddleware(RequirePasswordChange::class);
        Route::delete('auth/token', [AuthController::class, 'logout'])->withoutMiddleware(RequirePasswordChange::class);
        Route::post('me/password', [PasswordController::class, 'update'])->middleware('throttle:6,1,gantisandi')->withoutMiddleware(RequirePasswordChange::class);

        // Atur ulang kata sandi petugas oleh operator
        Route::get('staff', [StaffController::class, 'index']);
        Route::post('staff/{user}/reset-password', [StaffController::class, 'resetPassword'])->whereNumber('user')->middleware('throttle:20,1,aturulang');

        Route::get('field/overview', [FieldController::class, 'overview']);

        // Sinkronisasi operasi yang dicatat perangkat lapangan saat offline (idempoten per operasi)
        Route::post('sync/field-logs', [SyncController::class, 'store']);
        Route::get('field-reports', [FieldReportController::class, 'index']);

        // Posko yang diampu pengguna dan peringatan untuknya
        Route::get('me/overview', [MeController::class, 'overview']);

        // Notifikasi Telegram milik pengguna sendiri
        Route::get('telegram', [TelegramLinkController::class, 'show']);
        Route::post('telegram/link-code', [TelegramLinkController::class, 'issueCode'])->middleware('throttle:6,1,kodetelegram');
        Route::delete('telegram', [TelegramLinkController::class, 'destroy']);

        // Kebutuhan posko
        Route::get('demands', [DemandController::class, 'index']);
        Route::post('camps/{camp}/demands', [DemandController::class, 'store'])->whereUuid('camp');
        Route::get('demands/{demand}/allocation-plan', [DemandController::class, 'plan'])->whereUuid('demand');
        Route::post('demands/{demand}/dispatches', [DispatchController::class, 'store'])->whereUuid('demand');

        // Peninjauan pendaftaran akun petugas
        Route::get('registrations', [RegistrationReviewController::class, 'index']);
        Route::post('registrations/{user}/approve', [RegistrationReviewController::class, 'approve'])->whereNumber('user');
        Route::post('registrations/{user}/reject', [RegistrationReviewController::class, 'reject'])->whereNumber('user');

        // Pengumuman: operator menerbitkan dan menarik
        Route::get('announcements/manage', [AnnouncementController::class, 'manage']);
        Route::post('announcements', [AnnouncementController::class, 'store']);
        Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy'])->whereUuid('announcement');

        // Data untuk konsol logistik
        Route::get('logistics/summary', LogisticsSummaryController::class);
        Route::get('items', [ItemController::class, 'index']);
        Route::get('warehouses', [WarehouseController::class, 'index']);
        Route::get('audit-logs', [AuditLogController::class, 'index']);
        Route::get('audit-logs/integrity', [AuditLogController::class, 'integrity']);

        // Stok gudang
        Route::get('warehouses/{warehouse}/stock', [WarehouseStockController::class, 'index'])->whereUuid('warehouse');
        Route::post('warehouses/{warehouse}/stock', [WarehouseStockController::class, 'store'])->whereUuid('warehouse');

        // Pengiriman
        Route::get('dispatches', [DispatchController::class, 'index']);
        Route::get('dispatches/{dispatch}', [DispatchController::class, 'show'])->whereUuid('dispatch');
        Route::post('dispatches/{dispatch}/send', [DispatchController::class, 'send'])->whereUuid('dispatch');
        Route::post('dispatches/{dispatch}/cancel', [DispatchController::class, 'cancel'])->whereUuid('dispatch');
        Route::post('dispatches/{dispatch}/receive', [DispatchController::class, 'receive'])->whereUuid('dispatch');
    });
});
