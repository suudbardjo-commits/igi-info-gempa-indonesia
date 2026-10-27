<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\TemporaryPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Petugas aktif dan pengaturan ulang kata sandi oleh operator. */
class StaffController extends Controller
{
    /**
     * Siapa yang boleh diatur ulang kata sandinya oleh siapa. Admin tidak pernah lewat sini (hanya lewat terminal);
     * operator BPBD hanya oleh super admin; semua petugas lain oleh operator; tidak ada yang mengatur ulang dirinya
     * sendiri (itu lewat halaman Akun, dengan kata sandi lama).
     */
    private function allowed(User $reviewer, User $target): bool
    {
        if ($target->is($reviewer) || $target->account_status !== User::APPROVED) {
            return false;
        }

        if ($target->hasRole(Role::SuperAdmin->value)) {
            return false;
        }

        if ($target->hasRole(Role::BpbdOperator->value)) {
            return $reviewer->hasRole(Role::SuperAdmin->value);
        }

        return true;
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('user.review');

        $reviewer = $request->user();

        return response()->json([
            'data' => User::query()->where('account_status', User::APPROVED)->orderBy('name')->limit(300)->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'organization' => $u->organization,
                'roles' => $u->getRoleNames()->values(),
                'must_change_password' => (bool) $u->must_change_password,
                'password_reset_at' => $u->password_reset_at?->toIso8601String(),
                'can_reset' => $this->allowed($reviewer, $u),
            ])->values(),
        ]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        Gate::authorize('user.review');

        if (! $this->allowed($request->user(), $user)) {
            throw ValidationException::withMessages(['user' => 'Anda tidak bisa mengatur ulang kata sandi akun ini.']);
        }

        $temporary = TemporaryPassword::make();

        DB::transaction(function () use ($request, $user, $temporary) {
            $user->forceFill([
                'password' => $temporary,
                'must_change_password' => true,
                'password_reset_at' => now(),
                'password_reset_by' => $request->user()->id,
            ])->save();

            // Token aplikasi lapangan lama dicabut. Sesi peramban lama terputus sendiri karena hash kata sandinya berubah.
            $user->tokens()->delete();
        });

        // Kata sandi sementara sengaja tidak dicatat di log; hanya siapa mengatur ulang siapa.
        Log::info('Kata sandi petugas diatur ulang oleh operator', ['user' => $user->id, 'by' => $request->user()->id]);

        return response()->json([
            'temporary_password' => $temporary,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }
}
