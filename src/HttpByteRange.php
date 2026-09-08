<?php

declare(strict_types=1);

namespace ChambreRose;

final class HttpByteRange
{
    private function __construct(
        public readonly int $start,
        public readonly int $end
    ) {
    }

    public function length(): int
    {
        return $this->end - $this->start + 1;
    }

    public static function parse(?string $header, int $representationSize): ?self
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $header = trim($header);
        if ($representationSize <= 0
            || !preg_match('/^bytes=(\d*)-(\d*)$/i', $header, $matches)
            || ($matches[1] === '' && $matches[2] === '')
        ) {
            self::reject($representationSize);
        }

        if ($matches[1] === '') {
            $suffixLength = (int) $matches[2];
            if ($suffixLength <= 0) {
                self::reject($representationSize);
            }

            $length = min($suffixLength, $representationSize);

            return new self($representationSize - $length, $representationSize - 1);
        }

        $start = (int) $matches[1];
        if ($start >= $representationSize) {
            self::reject($representationSize);
        }

        $end = $matches[2] === ''
            ? $representationSize - 1
            : min((int) $matches[2], $representationSize - 1);
        if ($end < $start) {
            self::reject($representationSize);
        }

        return new self($start, $end);
    }

    private static function reject(int $representationSize): never
    {
        throw new ApiException(
            416,
            'The requested byte range cannot be satisfied.',
            [],
            [
                'Accept-Ranges' => 'bytes',
                'Content-Range' => 'bytes */' . max(0, $representationSize),
            ]
        );
    }
}
