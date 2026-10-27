<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Hash valid untuk pembanding saat email tidak ada, supaya waktu proses dan respons tidak membocorkan email terdaftar. */
    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(40));
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();

        $valid = Hash::check($data['password'], $user->password ?? self::dummyHash());

        if (! $user || ! $valid) {
            throw ValidationException::withMessages(['email' => 'Email atau kata sandi salah.']);
        }

        if (! $user->isApproved()) {
            throw ValidationException::withMessages(['email' => $user->statusMessage()]);
        }

        return response()->json([
            'token' => $user->createToken($data['device_name'])->plainTextToken,
            'user' => $this->present($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->present($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Anda sudah keluar.']);
    }

    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'roles' => $user->getRoleNames()->values(),
            'must_change_password' => (bool) $user->must_change_password,
        ];
    }
}
