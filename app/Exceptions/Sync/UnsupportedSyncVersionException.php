<?php

namespace App\Exceptions\Sync;

use RuntimeException;

class UnsupportedSyncVersionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly array $compatibility,
    ) {
        parent::__construct($message);
    }
}
