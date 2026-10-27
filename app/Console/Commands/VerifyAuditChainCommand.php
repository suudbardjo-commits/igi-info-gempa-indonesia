<?php

namespace App\Console\Commands;

use App\Services\Logistics\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('audit:verify')]
#[Description('Periksa keutuhan rantai hash jejak audit logistik')]
class VerifyAuditChainCommand extends Command
{
    public function handle(AuditLogger $audit): int
    {
        $total = DB::table('logistics_audit_logs')->count();
        $broken = $audit->verify();

        if ($broken !== null) {
            $this->error("Rantai audit rusak mulai dari baris id {$broken}.");

            return self::FAILURE;
        }

        $this->info("Rantai audit utuh ({$total} baris).");

        return self::SUCCESS;
    }
}
