<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(
            fn (User $user) => $user->hasRole(Role::SuperAdmin->value) ? true : null,
        );

        // Aplikasi lapangan: koordinator posko dan relawan.
        Gate::define('field-work', fn (User $user) => $user->can('field-report.create'));

        // Konsol logistik: pengelola gudang dan petugas koordinasi.
        Gate::define('access-logistics', fn (User $user) => $user->can('demand.view-all') || $user->can('dispatch.create'));
    }
}
