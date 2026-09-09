<?php

declare(strict_types=1);

namespace ChambreRose;

use Closure;
use JsonException;

final class Response
{
    /**
     * @param array<string, string> $headers
     * @param Closure(): void|null $stream
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly array $headers = [],
        private readonly ?Closure $stream = null
    ) {
    }

    /**
     * @param Closure(): void $stream
     * @param array<string, string> $headers
     */
    public static function stream(Closure $stream, array $headers = [], int $status = 200): self
    {
        return new self($status, '', $headers, $stream);
    }

    /**
     * @param mixed $data
     * @param array<string, string> $headers
     */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        try {
            $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new ApiException(500, 'Unable to encode API response.');
        }

        return new self($status, $body, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Length' => (string) strlen($body),
        ] + $headers);
    }

    /** @param array<string, string> $fields */
    public static function error(int $status, string $message, string $path, array $fields = []): self
    {
        $reasons = [
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
            404 => 'Not Found', 405 => 'Method Not Allowed', 409 => 'Conflict',
            413 => 'Payload Too Large', 415 => 'Unsupported Media Type',
            416 => 'Range Not Satisfiable', 429 => 'Too Many Requests',
            500 => 'Internal Server Error', 503 => 'Service Unavailable',
        ];

        return self::json([
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'status' => $status,
            'error' => $reasons[$status] ?? 'Error',
            'message' => $message,
            'path' => $path,
            'fields' => (object) $fields,
        ], $status, ['Cache-Control' => 'no-store']);
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, $this->body, $headers + $this->headers, $this->stream);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->stream !== null) {
            ($this->stream)();

            return;
        }
        if (!in_array($this->status, [204, 304], true)) {
            echo $this->body;
        }
    }
}
