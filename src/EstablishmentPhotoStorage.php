<?php

declare(strict_types=1);

namespace ChambreRose;

use RuntimeException;

final class EstablishmentPhotoStorage
{
    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $configured = $directory ?? Config::get('PHOTO_STORAGE_PATH');
        $this->directory = rtrim($configured ?? dirname(__DIR__) . '/storage/establishment-photos', '/\\');
    }

    public function store(int $userId, UploadedFile $photo, ?string $contentType = null): string
    {
        $this->ensureDirectory();
        $extension = match ($contentType ?? $photo->detectedContentType()) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', default => 'webp',
        };
        $storageKey = sprintf('user-%d-%s.%s', $userId, bin2hex(random_bytes(12)), $extension);
        $destination = $this->path($storageKey);
        $temporary = $destination . '.tmp';
        if (file_put_contents($temporary, $photo->bytes(), LOCK_EX) === false || !rename($temporary, $destination)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to store establishment photo.');
        }
        return $storageKey;
    }

    public function read(string $storageKey): ?string
    {
        $path = $this->path($storageKey);
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        return $contents === false ? null : $contents;
    }

    public function exists(string $storageKey): bool
    {
        $path = $this->path($storageKey);
        return is_file($path) && is_readable($path);
    }

    public function delete(?string $storageKey): void
    {
        if ($storageKey === null || $storageKey === '') {
            return;
        }
        $path = $this->path($storageKey);
        if (is_file($path) && !@unlink($path)) {
            error_log('[Chambre Rose API] Unable to remove establishment photo: ' . $storageKey);
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create establishment photo directory.');
        }
    }

    private function path(string $storageKey): string
    {
        if ($storageKey === '' || basename($storageKey) !== $storageKey) {
            throw new RuntimeException('Invalid establishment photo key.');
        }
        return $this->directory . DIRECTORY_SEPARATOR . $storageKey;
    }
}

