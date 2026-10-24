<?php

declare(strict_types=1);

namespace ChambreRose;

use RuntimeException;

final class ApiException extends RuntimeException
{
    /**
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $fields = [],
        public readonly array $headers = []
    ) {
        parent::__construct($message);
    }
}
