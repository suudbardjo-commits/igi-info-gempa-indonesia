<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            // Hanya dihitung untuk yang berhak meninjau; dipakai untuk lencana jumlah di menu konsol.
            'pendingRegistrations' => fn () => $user?->can('user.review') ? \App\Models\User::where('account_status', 'pending')->count() : null,
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'roles' => $user->getRoleNames()->values(),
                    'must_change_password' => (bool) $user->must_change_password,
                    // Hanya hak yang dipakai untuk menampilkan/menyembunyikan tombol; server tetap memeriksa ulang.
                    'can' => collect(['dispatch.create', 'warehouse.manage-stock', 'demand.view-all', 'audit.view', 'dispatch.override-geofence', 'field-report.create', 'announcement.manage', 'user.review'])
                        ->mapWithKeys(fn ($p) => [$p => $user->can($p)])
                        ->all(),
                ] : null,
            ],
        ];
    }
}
