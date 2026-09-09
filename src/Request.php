<?php

declare(strict_types=1);

namespace ChambreRose;

use JsonException;

final class Request
{
    private const MAX_BODY_BYTES = 30 * 1024 * 1024;

    private ?string $rawBody = null;
    /** @var array<string, mixed>|null */
    private ?array $parsedJson = null;
    /** @var array{fields: array<string, string>, files: array<string, UploadedFile>}|null */
    private ?array $parsedMultipart = null;
    public readonly string $requestId;
    public readonly string $clientIp;

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly array $query,
        ?string $requestId = null,
        ?string $clientIp = null
    ) {
        $this->requestId = $requestId ?? bin2hex(random_bytes(8));
        $this->clientIp = filter_var($clientIp, FILTER_VALIDATE_IP) !== false
            ? (string) $clientIp
            : 'unknown';
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
            $_GET,
            null,
            self::resolveClientIp(
                is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : null,
                $headers['x-forwarded-for'] ?? null,
                Config::get('APP_TRUSTED_PROXIES', '') ?? ''
            )
        );
    }

    public static function resolveClientIp(?string $remoteAddress, ?string $forwardedFor, string $trustedProxies): string
    {
        $remoteAddress = filter_var($remoteAddress, FILTER_VALIDATE_IP) !== false
            ? (string) $remoteAddress
            : 'unknown';
        $trusted = array_values(array_filter(array_map('trim', explode(',', $trustedProxies))));
        if ($remoteAddress === 'unknown' || !self::isTrustedProxy($remoteAddress, $trusted)) {
            return $remoteAddress;
        }

        $forwarded = array_values(array_filter(array_map('trim', explode(',', $forwardedFor ?? ''))));
        for ($index = count($forwarded) - 1; $index >= 0; $index--) {
            $candidate = $forwarded[$index];
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (!self::isTrustedProxy($candidate, $trusted)) {
                return $candidate;
            }
        }

        return $remoteAddress;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function cookie(string $name): ?string
    {
        $header = $this->header('cookie');
        if ($header === null || $name === '') {
            return null;
        }

        foreach (explode(';', $header) as $cookie) {
            [$candidate, $value] = array_pad(explode('=', trim($cookie), 2), 2, '');
            if ($candidate === $name) {
                return rawurldecode($value);
            }
        }

        return null;
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

    /** @param list<string> $trusted */
    private static function isTrustedProxy(string $ip, array $trusted): bool
    {
        foreach ($trusted as $network) {
            if (!str_contains($network, '/')) {
                if (hash_equals($network, $ip)) {
                    return true;
                }
                continue;
            }

            [$address, $prefixText] = array_pad(explode('/', $network, 2), 2, '');
            $packedIp = inet_pton($ip);
            $packedAddress = inet_pton($address);
            if ($packedIp === false || $packedAddress === false || strlen($packedIp) !== strlen($packedAddress)) {
                continue;
            }
            $maximumPrefix = strlen($packedIp) * 8;
            if (filter_var($prefixText, FILTER_VALIDATE_INT) === false) {
                continue;
            }
            $prefix = (int) $prefixText;
            if ($prefix < 0 || $prefix > $maximumPrefix) {
                continue;
            }

            $fullBytes = intdiv($prefix, 8);
            $remainingBits = $prefix % 8;
            if (substr($packedIp, 0, $fullBytes) !== substr($packedAddress, 0, $fullBytes)) {
                continue;
            }
            if ($remainingBits === 0) {
                return true;
            }
            $mask = (0xff << (8 - $remainingBits)) & 0xff;
            if ((ord($packedIp[$fullBytes]) & $mask) === (ord($packedAddress[$fullBytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
