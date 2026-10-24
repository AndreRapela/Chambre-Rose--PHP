<?php

declare(strict_types=1);

namespace ChambreRose;

use RuntimeException;

final class PrivateIdentityFileCipher
{
    private const PREFIX = "CRID1";
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;
    private readonly string $key;

    public function __construct(?string $secret = null)
    {
        $secret ??= Config::first(['IDENTITY_DOCUMENT_SECRET', 'JWT_SECRET']);
        if ($secret === null || strlen($secret) < 32) {
            throw new RuntimeException('IDENTITY_DOCUMENT_SECRET or JWT_SECRET must contain at least 32 characters.');
        }
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            throw new RuntimeException('The OpenSSL extension is required for private identity documents.');
        }

        $this->key = hash('sha256', $secret, true);
    }

    public function encrypt(string $bytes, int $userId, string $kind): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $encrypted = openssl_encrypt(
            $bytes,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $this->additionalData($userId, $kind),
            self::TAG_BYTES
        );
        if (!is_string($encrypted) || strlen($tag) !== self::TAG_BYTES) {
            throw new RuntimeException('Unable to encrypt the private identity file.');
        }

        return self::PREFIX . $nonce . $tag . $encrypted;
    }

    public function decrypt(string $sealed, int $userId, string $kind): string
    {
        $headerBytes = strlen(self::PREFIX) + self::NONCE_BYTES + self::TAG_BYTES;
        if (strlen($sealed) < $headerBytes || !str_starts_with($sealed, self::PREFIX)) {
            throw new RuntimeException('The private identity file is invalid.');
        }
        $offset = strlen(self::PREFIX);
        $nonce = substr($sealed, $offset, self::NONCE_BYTES);
        $offset += self::NONCE_BYTES;
        $tag = substr($sealed, $offset, self::TAG_BYTES);
        $encrypted = substr($sealed, $offset + self::TAG_BYTES);
        $bytes = openssl_decrypt(
            $encrypted,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $this->additionalData($userId, $kind)
        );
        if (!is_string($bytes)) {
            throw new RuntimeException('Unable to decrypt the private identity file.');
        }

        return $bytes;
    }

    private function additionalData(int $userId, string $kind): string
    {
        return 'chambre-rose:identity:' . $userId . ':' . strtoupper($kind);
    }
}
