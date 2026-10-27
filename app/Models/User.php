<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone_number', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** Hanya akun yang disetujui boleh masuk. Status tidak ada di $fillable, jadi tidak bisa diisi lewat request. */
    public function isApproved(): bool
    {
        return $this->account_status === self::APPROVED;
    }

    /** Pesan yang ditampilkan setelah kata sandi benar tetapi akun belum boleh masuk. */
    public function statusMessage(): string
    {
        return $this->account_status === self::REJECTED
            ? 'Pendaftaran akun ini tidak disetujui. Hubungi operator BPBD bila ini keliru.'
            : 'Pendaftaran akun ini belum disetujui. Operator BPBD akan meninjaunya; coba masuk lagi setelah dikabari.';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'password_reset_at' => 'datetime',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
