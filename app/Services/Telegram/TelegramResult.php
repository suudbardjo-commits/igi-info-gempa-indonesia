<?php

namespace App\Services\Telegram;

final readonly class TelegramResult
{
    public function __construct(
        public TelegramOutcome $outcome,
        public ?int $retryAfter = null,
        public ?string $error = null,
    ) {}

    public function ok(): bool
    {
        return $this->outcome === TelegramOutcome::Sent;
    }
}
