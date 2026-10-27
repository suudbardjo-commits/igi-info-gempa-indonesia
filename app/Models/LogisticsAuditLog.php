<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Baris audit hanya boleh ditambah (dijaga trigger database) dan dibuat lewat AuditLogger.
 */
class LogisticsAuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'logistics_audit_logs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
