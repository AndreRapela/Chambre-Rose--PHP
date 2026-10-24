<?php

declare(strict_types=1);

namespace ChambreRose;

final class CompanyVerificationService
{
    private const MAX_FILE_BYTES = 8 * 1024 * 1024;
    private const FILE_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly CompanyVerificationRepository $repository,
        private readonly PrivateIdentityFileCipher $cipher
    ) {
    }

    /** @return array{companyNumber:string,registration:array{name:string,contentType:string,bytes:string}}|null */
    public function validateRegistration(
        string $accountType,
        mixed $companyNumber,
        ?UploadedFile $registration
    ): ?array {
        if ($accountType !== 'STORE') {
            return null;
        }
        $number = trim(is_scalar($companyNumber) ? (string) $companyNumber : '');
        $fields = [];
        if ($number === '') {
            $fields['companyNumber'] = 'is required';
        } elseif (strlen($number) > 80) {
            $fields['companyNumber'] = 'cannot exceed 80 characters';
        }
        if ($registration === null || $registration->isEmpty()) {
            $fields['companyRegistration'] = 'is required';
        }
        if ($fields !== []) {
            throw new ApiException(400, 'A company number and company registration document are required.', $fields);
        }

        return [
            'companyNumber' => $number,
            'registration' => $this->validatedFile($registration),
        ];
    }

    /** @param array{companyNumber:string,registration:array{name:string,contentType:string,bytes:string}} $verification */
    public function store(int $userId, array $verification): void
    {
        $registration = $verification['registration'];
        $this->repository->create($userId, $verification['companyNumber'], [
            'name' => $registration['name'],
            'contentType' => $registration['contentType'],
            'data' => $this->cipher->encrypt($registration['bytes'], $userId, 'COMPANY_REGISTRATION'),
        ]);
    }

    /**
     * @param list<int> $userIds
     * @return array<int, array{companyNumber:string, submittedAt:string}>
     */
    public function summariesByUserIds(array $userIds): array
    {
        return $this->repository->summariesByUserIds($userIds);
    }

    /** @return array{name:string,contentType:string,bytes:string} */
    public function file(int $userId): array
    {
        $row = $this->repository->find($userId);
        if ($row === null) {
            throw new ApiException(404, 'Company registration document not found.');
        }
        $sealed = $row['registration_data'];
        if (is_resource($sealed)) {
            $sealed = stream_get_contents($sealed);
        }
        if (!is_string($sealed)) {
            throw new ApiException(500, 'Unable to read the private company registration document.');
        }

        return [
            'name' => (string) $row['registration_name'],
            'contentType' => (string) $row['registration_content_type'],
            'bytes' => $this->cipher->decrypt($sealed, $userId, 'COMPANY_REGISTRATION'),
        ];
    }

    /** @return array{name:string,contentType:string,bytes:string} */
    private function validatedFile(UploadedFile $file): array
    {
        if ($file->size > self::MAX_FILE_BYTES) {
            throw new ApiException(413, 'The company registration document cannot exceed 8 MB.', [
                'companyRegistration' => 'cannot exceed 8 MB',
            ]);
        }
        $bytes = $file->bytes();
        if (strlen($bytes) > self::MAX_FILE_BYTES) {
            throw new ApiException(413, 'The company registration document cannot exceed 8 MB.', [
                'companyRegistration' => 'cannot exceed 8 MB',
            ]);
        }
        $contentType = $file->detectedContentType();
        if (!in_array($contentType, self::FILE_TYPES, true)) {
            throw new ApiException(400, 'The company registration document must be a PDF, JPG, PNG or WebP file.', [
                'companyRegistration' => 'must be a PDF, JPG, PNG or WebP file',
            ]);
        }

        return ['name' => $file->name, 'contentType' => $contentType, 'bytes' => $bytes];
    }
}
