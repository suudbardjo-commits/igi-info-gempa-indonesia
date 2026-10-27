<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public static function forReservation(string $requested, string $free): self
    {
        return new self("Stok bebas tidak cukup: diminta {$requested}, tersedia {$free}.");
    }

    public static function forDispatch(string $requested): self
    {
        return new self("Jumlah dikirim ({$requested}) melebihi stok yang direservasi untuk pengiriman ini.");
    }
}
