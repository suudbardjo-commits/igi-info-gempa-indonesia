<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Rules\StrongPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class PasswordController extends Controller
{
    /** Ganti kata sandi sendiri. Perangkat dan token lain ikut keluar; yang dipakai sekarang tetap masuk. */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', new StrongPassword],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Kata sandi saat ini salah.']);
        }

        $user->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();

        // Sesi peramban lain terputus (AuthenticateSession membandingkan hash kata sandi di sesi); sesi ini diperbarui
        // supaya tidak ikut terlempar.
        if ($request->hasSession() && Auth::guard('web')->check()) {
            Auth::guard('web')->logoutOtherDevices($data['password']);
        }

        // Token aplikasi lapangan di perangkat lain dicabut. Bila permintaan ini sendiri memakai token, token itu dipertahankan.
        $current = $user->currentAccessToken();
        $user->tokens()->when($current instanceof PersonalAccessToken, fn ($q) => $q->where('id', '!=', $current->id))->delete();

        return response()->json(['message' => 'Kata sandi sudah diganti. Perangkat lain yang memakai akun ini harus masuk lagi.']);
    }
}
