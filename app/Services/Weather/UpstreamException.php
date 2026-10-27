<?php

namespace App\Services\Weather;

use RuntimeException;

class UpstreamException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
