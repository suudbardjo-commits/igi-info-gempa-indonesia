<?php

namespace App\Console\Commands;

use App\Services\Logistics\LedgerReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ledger:reconcile')]
#[Description('Cocokkan saldo stok gudang dan posko dengan ledger; tampilkan selisihnya')]
class ReconcileLedgerCommand extends Command
{
    public function handle(LedgerReconciler $reconciler): int
    {
        $mismatches = $reconciler->mismatches();

        if (! $mismatches) {
            $this->info('Semua saldo stok cocok dengan ledger.');

            return self::SUCCESS;
        }

        $this->error(count($mismatches).' saldo tidak cocok dengan ledger:');
        $this->table(
            ['Lingkup', 'Barang', 'Kolom', 'Saldo', 'Menurut ledger'],
            array_map(fn ($m) => [$m['scope'], $m['label'], $m['field'], $m['balance'], $m['ledger']], $mismatches),
        );

        return self::FAILURE;
    }
}
