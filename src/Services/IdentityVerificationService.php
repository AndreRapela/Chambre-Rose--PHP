<?php

declare(strict_types=1);

namespace ChambreRose;

final class IdentityVerificationService
{
    private const MAX_FILE_BYTES = 8 * 1024 * 1024;
    private const ACCOUNT_TYPES = ['VISITOR', 'ESCORT'];
    private const DOCUMENT_TYPES = ['IDENTITY_CARD', 'PASSPORT'];
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly IdentityVerificationRepository $repository,
        private readonly PrivateIdentityFileCipher $cipher
    ) {
    }

    /**
     * @return array{
     *   documentType:string,
     *   document:array{name:string,contentType:string,bytes:string},
     *   selfie:array{name:string,contentType:string,bytes:string}
     * }|null
     */
    public function validateRegistration(
        string $accountType,
        mixed $documentType,
        ?UploadedFile $document,
        ?UploadedFile $selfie
    ): ?array {
        if (!in_array($accountType, self::ACCOUNT_TYPES, true)) {
            return null;
        }
        $normalizedType = strtoupper(trim(is_scalar($documentType) ? (string) $documentType : ''));
        $fields = [];
        if (!in_array($normalizedType, self::DOCUMENT_TYPES, true)) {
            $fields['identityDocumentType'] = 'must be IDENTITY_CARD or PASSPORT';
        }
        if ($document === null || $document->isEmpty()) {
            $fields['identityDocument'] = 'is required';
        }
        if ($selfie === null || $selfie->isEmpty()) {
            $fields['identitySelfie'] = 'is required';
        }
        if ($fields !== []) {
            throw new ApiException(400, 'An identity document and a selfie holding it are required.', $fields);
        }

        return [
            'documentType' => $normalizedType,
            'document' => $this->validatedImage($document, 'identityDocument', 'identity document'),
            'selfie' => $this->validatedImage($selfie, 'identitySelfie', 'identity selfie'),
        ];
    }

    /**
     * @param array{
     *   documentType:string,
     *   document:array{name:string,contentType:string,bytes:string},
     *   selfie:array{name:string,contentType:string,bytes:string}
     * } $verification
     */
    public function store(int $userId, array $verification): void
    {
        $this->repository->create($userId, $verification['documentType'], [
            'name' => $verification['document']['name'],
            'contentType' => $verification['document']['contentType'],
            'data' => $this->cipher->encrypt($verification['document']['bytes'], $userId, 'DOCUMENT'),
        ], [
            'name' => $verification['selfie']['name'],
            'contentType' => $verification['selfie']['contentType'],
            'data' => $this->cipher->encrypt($verification['selfie']['bytes'], $userId, 'SELFIE'),
        ]);
    }

    /**
     * @param list<int> $userIds
     * @return array<int, array{documentType:string, submittedAt:string}>
     */
    public function summariesByUserIds(array $userIds): array
    {
        return $this->repository->summariesByUserIds($userIds);
    }

    /** @return array{name:string,contentType:string,bytes:string} */
    public function file(int $userId, string $kind): array
    {
        $kind = strtoupper(trim($kind));
        if (!in_array($kind, ['DOCUMENT', 'SELFIE'], true)) {
            throw new ApiException(404, 'Identity verification file not found.');
        }
        $row = $this->repository->find($userId);
        if ($row === null) {
            throw new ApiException(404, 'Identity verification file not found.');
        }
        $prefix = $kind === 'DOCUMENT' ? 'document' : 'selfie';
        $sealed = $row[$prefix . '_data'];
        if (is_resource($sealed)) {
            $sealed = stream_get_contents($sealed);
        }
        if (!is_string($sealed)) {
            throw new ApiException(500, 'Unable to read the private identity file.');
        }

        return [
            'name' => (string) $row[$prefix . '_name'],
            'contentType' => (string) $row[$prefix . '_content_type'],
            'bytes' => $this->cipher->decrypt($sealed, $userId, $kind),
        ];
    }

    /** @return array{name:string,contentType:string,bytes:string} */
    private function validatedImage(UploadedFile $file, string $field, string $label): array
    {
        if ($file->size > self::MAX_FILE_BYTES) {
            throw new ApiException(413, 'Each private identity image cannot exceed 8 MB.', [$field => 'cannot exceed 8 MB']);
        }
        $bytes = $file->bytes();
        if (strlen($bytes) > self::MAX_FILE_BYTES) {
            throw new ApiException(413, 'Each private identity image cannot exceed 8 MB.', [$field => 'cannot exceed 8 MB']);
        }
        $contentType = $file->detectedContentType();
        if (!in_array($contentType, self::IMAGE_TYPES, true)) {
            throw new ApiException(400, 'The ' . $label . ' must be a JPG, PNG or WebP image.', [
                $field => 'must be a JPG, PNG or WebP image',
            ]);
        }

        return [
            'name' => $file->name,
            'contentType' => $contentType,
            'bytes' => $bytes,
        ];
    }
}
