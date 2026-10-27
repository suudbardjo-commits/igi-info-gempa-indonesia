<?php

namespace App\Services\Telegram;

enum TelegramOutcome: string
{
    case Sent = 'sent';
    /** Pengguna memblokir bot atau chat tidak ada lagi; tautan sebaiknya dicabut. */
    case Blocked = 'blocked';
    /** Kena batas laju (429) atau galat sementara; layak dicoba lagi. */
    case RetryLater = 'retry_later';
    case Failed = 'failed';
    /** Token belum diisi: notifikasi Telegram dimatikan. */
    case Disabled = 'disabled';
}
