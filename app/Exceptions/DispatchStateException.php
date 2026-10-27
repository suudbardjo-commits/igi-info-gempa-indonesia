<?php

namespace App\Exceptions;

use RuntimeException;

class DispatchStateException extends RuntimeException
{
    public static function cannot(string $action, string $currentStatus): self
    {
        return new self("Pengiriman tidak bisa {$action} karena statusnya \"{$currentStatus}\".");
    }
}
