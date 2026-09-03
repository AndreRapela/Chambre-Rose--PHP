<?php

declare(strict_types=1);

namespace ChambreRose;

use JsonException;
use RuntimeException;

final class ApiException extends RuntimeException
{
    /** @param array<string, string> $fields */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $fields = []
    ) {
        parent::__construct($message);
    }
}

final class UploadedFile
{
    private ?string $cachedBytes = null;
    private ?string $cachedContentType = null;

    public function __construct(
        public readonly string $name,
        public readonly string $contentType,
        public readonly int $size,
        private readonly ?string $temporaryPath = null,
        private readonly ?string $contents = null,
        public readonly int $error = UPLOAD_ERR_OK
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->error === UPLOAD_ERR_NO_FILE
            || ($this->error === UPLOAD_ERR_OK && $this->size === 0);
    }

    public function bytes(): string
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            if (in_array($this->error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new ApiException(413, 'Uploaded file is too large.');
            }

            throw new ApiException(400, 'Unable to read uploaded image.');
        }

        if ($this->cachedBytes !== null) {
            return $this->cachedBytes;
        }

        if ($this->contents !== null) {
            return $this->cachedBytes = $this->contents;
        }

        if ($this->temporaryPath === null) {
            throw new ApiException(400, 'Unable to read uploaded image.');
        }

        $bytes = file_get_contents($this->temporaryPath);
        if ($bytes === false) {
            throw new ApiException(400, 'Unable to read uploaded image.');
        }

        return $this->cachedBytes = $bytes;
    }

    public function actualSize(): int
    {
        return strlen($this->bytes());
    }

    public function detectedContentType(): string
    {
        if ($this->cachedContentType !== null) {
            return $this->cachedContentType;
        }
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($this->bytes());

        return $this->cachedContentType = is_string($type) && $type !== ''
            ? strtolower(trim($type))
            : 'application/octet-stream';
    }
}

final class Request
{
    private const MAX_BODY_BYTES = 30 * 1024 * 1024;

    private ?string $rawBody = null;
    /** @var array<string, mixed>|null */
    private ?array $parsedJson = null;
    /** @var array{fields: array<string, string>, files: array<string, UploadedFile>}|null */
    private ?array $parsedMultipart = null;
    public readonly string $requestId;

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly array $query,
        ?string $requestId = null
    ) {
        $this->requestId = $requestId ?? bin2hex(random_bytes(8));
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() ?: [] as $name => $value) {
                $headers[strtolower((string) $name)] = (string) $value;
            }
        }

        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $header = strtolower(str_replace('_', '-', substr($name, 5)));
                $headers[$header] ??= (string) $value;
            }
        }

        $authorization = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? null;
        if (is_string($authorization) && $authorization !== '') {
            $headers['authorization'] = $authorization;
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $headers,
            $_GET
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function contentType(): string
    {
        return strtolower(trim(explode(';', $this->header('content-type') ?? '')[0]));
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->parsedJson !== null) {
            return $this->parsedJson;
        }

        $raw = $this->body();
        if (trim($raw) === '') {
            throw new ApiException(400, 'Malformed JSON request.');
        }

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException(400, 'Malformed JSON request.');
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ApiException(400, 'JSON request must be an object.');
        }

        return $this->parsedJson = $decoded;
    }

    /** @return array{fields: array<string, string>, files: array<string, UploadedFile>} */
    public function multipart(): array
    {
        if ($this->parsedMultipart !== null) {
            return $this->parsedMultipart;
        }

        $contentLength = (int) ($this->header('content-length') ?? 0);
        if ($contentLength > self::MAX_BODY_BYTES) {
            throw new ApiException(413, 'Multipart request cannot exceed 30MB.');
        }

        if ($this->method === 'POST' && $contentLength > 0 && $_POST === [] && $_FILES === []) {
            $postMax = self::iniBytes((string) ini_get('post_max_size'));
            if ($postMax > 0 && $contentLength > $postMax) {
                throw new ApiException(413, 'The upload exceeds the server post_max_size limit.');
            }
        }

        if ($this->method === 'POST') {
            $files = [];
            foreach ($_FILES as $field => $file) {
                if (!is_array($file) || is_array($file['name'] ?? null)) {
                    continue;
                }
                $files[$field] = new UploadedFile(
                    (string) ($file['name'] ?? ''),
                    (string) ($file['type'] ?? 'application/octet-stream'),
                    (int) ($file['size'] ?? 0),
                    isset($file['tmp_name']) ? (string) $file['tmp_name'] : null,
                    null,
                    (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)
                );
            }

            $fields = [];
            foreach ($_POST as $key => $value) {
                if (is_scalar($value)) {
                    $fields[(string) $key] = (string) $value;
                }
            }

            return $this->parsedMultipart = ['fields' => $fields, 'files' => $files];
        }

        return $this->parsedMultipart = MultipartParser::parse(
            $this->body(),
            $this->header('content-type') ?? ''
        );
    }

    public function body(): string
    {
        if ($this->rawBody === null) {
            $contentLength = (int) ($this->header('content-length') ?? 0);
            if ($contentLength > self::MAX_BODY_BYTES) {
                throw new ApiException(413, 'Request body cannot exceed 30MB.');
            }

            $body = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
            $this->rawBody = $body === false ? '' : $body;
            if (strlen($this->rawBody) > self::MAX_BODY_BYTES) {
                throw new ApiException(413, 'Request body cannot exceed 30MB.');
            }
        }

        return $this->rawBody;
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $number = (float) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => (int) ($number * 1024 * 1024 * 1024),
            'm' => (int) ($number * 1024 * 1024),
            'k' => (int) ($number * 1024),
            default => (int) $number,
        };
    }
}

final class MultipartParser
{
    /** @return array{fields: array<string, string>, files: array<string, UploadedFile>} */
    public static function parse(string $body, string $contentType): array
    {
        if (!preg_match('/boundary=(?:"([^"]+)"|([^;]+))/i', $contentType, $match)) {
            throw new ApiException(400, 'Invalid multipart request.');
        }

        $boundary = $match[1] !== '' ? $match[1] : trim($match[2]);
        if ($boundary === '' || strlen($boundary) > 200) {
            throw new ApiException(400, 'Invalid multipart boundary.');
        }

        $fields = [];
        $files = [];
        foreach (explode('--' . $boundary, $body) as $part) {
            $part = ltrim($part, "\r\n");
            if ($part === '' || $part === '--' || str_starts_with($part, '--\r\n')) {
                continue;
            }

            $part = preg_replace('/\r\n$/', '', $part) ?? $part;
            $separator = strpos($part, "\r\n\r\n");
            if ($separator === false) {
                continue;
            }

            $headerBlock = substr($part, 0, $separator);
            $contents = substr($part, $separator + 4);
            $contents = preg_replace('/\r\n$/', '', $contents) ?? $contents;

            if (!preg_match('/Content-Disposition:\s*form-data;([^\r\n]+)/i', $headerBlock, $disposition)) {
                continue;
            }
            if (!preg_match('/\bname="([^"]+)"/i', $disposition[1], $nameMatch)) {
                continue;
            }

            $name = $nameMatch[1];
            if (preg_match('/\bfilename="([^"]*)"/i', $disposition[1], $fileMatch)) {
                $fileName = self::safeFileName($fileMatch[1]);
                $type = 'application/octet-stream';
                if (preg_match('/Content-Type:\s*([^\r\n]+)/i', $headerBlock, $typeMatch)) {
                    $type = trim($typeMatch[1]);
                }
                $files[$name] = new UploadedFile($fileName, $type, strlen($contents), null, $contents);
            } else {
                $fields[$name] = $contents;
            }
        }

        return ['fields' => $fields, 'files' => $files];
    }

    private static function safeFileName(string $name): string
    {
        $normalized = str_replace('\\', '/', $name);

        return trim(str_replace('"', '', basename($normalized))) ?: 'profile-media';
    }
}

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly array $headers = []
    ) {
    }

    /** @param mixed $data @param array<string, string> $headers */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        try {
            $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new ApiException(500, 'Unable to encode API response.');
        }

        return new self($status, $body, ['Content-Type' => 'application/json; charset=utf-8'] + $headers);
    }

    /** @param array<string, string> $fields */
    public static function error(int $status, string $message, string $path, array $fields = []): self
    {
        $reasons = [
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
            404 => 'Not Found', 405 => 'Method Not Allowed', 409 => 'Conflict',
            413 => 'Payload Too Large', 415 => 'Unsupported Media Type',
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
        return new self($this->status, $this->body, $headers + $this->headers);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if (!in_array($this->status, [204, 304], true)) {
            echo $this->body;
        }
    }
}
