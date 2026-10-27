<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Setelah operator mengatur ulang, kata sandi sementara hanya boleh dipakai untuk menggantinya.
            $table->boolean('must_change_password')->default(false)->after('account_status');
            $table->timestampTz('password_reset_at')->nullable();
            $table->foreignId('password_reset_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('password_reset_by');
            $table->dropColumn(['must_change_password', 'password_reset_at']);
        });
    }
};
