<?php

namespace App\Services\Logistics;

/**
 * Usulan memenuhi satu permintaan posko dari satu atau lebih gudang.
 *
 * @phpstan-type Line array{inventory_id: string, batch_number: string, expiry_date: ?string, quantity: string}
 * @phpstan-type Source array{warehouse_id: string, warehouse_code: string, warehouse_name: string, distance_km: string, lines: list<Line>, quantity: string}
 */
final readonly class AllocationPlan
{
    /** @param list<array<string, mixed>> $sources */
    public function __construct(
        public string $demandId,
        public string $itemId,
        public string $outstanding,
        public string $allocated,
        public string $shortfall,
        public array $sources,
    ) {}

    public function isComplete(): bool
    {
        return bccomp($this->shortfall, '0', 2) === 0;
    }

    public function isEmpty(): bool
    {
        return $this->sources === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'demand_id' => $this->demandId,
            'item_id' => $this->itemId,
            'outstanding' => $this->outstanding,
            'allocated' => $this->allocated,
            'shortfall' => $this->shortfall,
            'complete' => $this->isComplete(),
            'distance_basis' => 'garis lurus (bukan rute jalan)',
            'sources' => $this->sources,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['demand_id'], $data['item_id'], $data['outstanding'],
            $data['allocated'], $data['shortfall'], $data['sources'],
        );
    }
}
