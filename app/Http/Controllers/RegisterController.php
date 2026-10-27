<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use App\Rules\StrongPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengajuan akun petugas. Pendaftar tidak pernah memilih hak aksesnya sendiri: akun lahir tanpa peran dan berstatus
 * "pending", dan baru bisa masuk setelah operator BPBD menyetujui dan menetapkan perannya.
 */
class RegisterController extends Controller
{
    /** Peran yang boleh diminta lewat formulir. Operator dan admin hanya dibuat lewat perintah artisan. */
    public const REQUESTABLE = [Role::FieldVolunteer, Role::CampCoordinator, Role::WarehouseManager];

    public function create(): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/');
        }

        return Inertia::render('Daftar', [
            'roles' => collect(self::REQUESTABLE)->map(fn (Role $r) => ['value' => $r->value, 'label' => $r->label()])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:150'],
            'phone_number' => ['nullable', 'string', 'regex:/^\+?[0-9 ()-]{8,20}$/'],
            'password' => ['required', 'confirmed', new StrongPassword],
            'requested_role' => ['required', Rule::in(array_map(fn (Role $r) => $r->value, self::REQUESTABLE))],
            'organization' => ['required', 'string', 'min:3', 'max:120'],
            'request_note' => ['nullable', 'string', 'max:500'],
            // Kolom jebakan untuk robot: manusia tidak melihatnya. Diisi = pura-pura berhasil, tanpa membuat apa pun.
            'website' => ['nullable', 'string'],
        ]);

        $email = mb_strtolower(trim($data['email']));

        // Email yang sudah terdaftar dijawab sama persis seperti yang baru: formulir ini tidak boleh dipakai untuk
        // menebak siapa saja yang punya akun.
        if (blank($data['website'] ?? null) && ! User::where('email', $email)->exists()) {
            $user = new User([
                'name' => trim($data['name']),
                'email' => $email,
                'phone_number' => $data['phone_number'] ?? null,
                'password' => $data['password'],
            ]);

            // Isian status memang tidak bisa diisi massal; ditetapkan di sini, dan selalu "pending".
            $user->forceFill([
                'account_status' => User::PENDING,
                'requested_role' => $data['requested_role'],
                'organization' => trim($data['organization']),
                'request_note' => $data['request_note'] ?? null,
            ])->save();
        }

        return redirect()->route('daftar.terkirim')->with('registered', true);
    }

    public function done(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('registered')) {
            return redirect()->route('daftar');
        }

        return Inertia::render('Daftar/Terkirim');
    }
}
