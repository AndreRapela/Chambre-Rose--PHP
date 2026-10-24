<?php

declare(strict_types=1);

namespace ChambreRose;

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
