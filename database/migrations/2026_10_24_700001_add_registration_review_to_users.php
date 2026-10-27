<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Akun yang sudah ada tetap 'approved'. Pendaftar baru 'pending' tanpa peran apa pun sampai ditinjau operator.
            $table->string('account_status', 10)->default('approved')->after('password');
            $table->string('requested_role', 30)->nullable();
            $table->string('organization', 120)->nullable();
            $table->text('request_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('reject_reason', 200)->nullable();

            $table->index('account_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropIndex(['account_status']);
            $table->dropColumn(['account_status', 'requested_role', 'organization', 'request_note', 'reviewed_at', 'reject_reason']);
        });
    }
};
