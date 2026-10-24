<?php

declare(strict_types=1);

namespace ChambreRose;

final class AdminUserService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ?MailService $mail = null,
        private readonly ?ProfessionalProfileRepository $profiles = null,
        private readonly ?IdentityVerificationService $identityVerification = null,
        private readonly ?CompanyVerificationService $companyVerification = null
    ) {
    }

    /** @return array{items:list<array<string,mixed>>,page:int,pageSize:int,total:int,totalPages:int} */
    public function list(
        ?string $email,
        ?string $name,
        string $sort,
        ?string $approvalStatus = null,
        ?string $role = null,
        int $page = 1,
        int $pageSize = 25
    ): array {
        $email = self::clean($email);
        $name = self::clean($name);
        $approvalStatus = self::enum($approvalStatus, ['PENDING', 'APPROVED', 'REJECTED'], 'approvalStatus');
        $role = self::enum($role, ['ADMIN', 'VISITOR', 'ESCORT', 'STORE'], 'role');
        $result = $this->users->paginate(
            $email,
            $name,
            $sort,
            $approvalStatus,
            $role,
            max(1, $page),
            max(10, min(100, $pageSize))
        );
        $professionalIds = array_values(array_map(
            static fn (array $user): int => (int) $user['id'],
            array_filter(
                $result['items'],
                static fn (array $user): bool => in_array($user['role'], ['ESCORT', 'STORE'], true)
            )
        ));
        $professionalProfiles = $this->profiles?->findAdminSummariesByUserIds($professionalIds) ?? [];
        $verificationIds = array_values(array_map(
            static fn (array $user): int => (int) $user['id'],
            array_filter(
                $result['items'],
                static fn (array $user): bool => in_array($user['role'], ['VISITOR', 'ESCORT'], true)
            )
        ));
        $identityVerifications = $this->identityVerification?->summariesByUserIds($verificationIds) ?? [];
        $storeIds = array_values(array_map(
            static fn (array $user): int => (int) $user['id'],
            array_filter($result['items'], static fn (array $user): bool => $user['role'] === 'STORE')
        ));
        $companyVerifications = $this->companyVerification?->summariesByUserIds($storeIds) ?? [];
        $result['items'] = array_map(function (array $user) use ($professionalProfiles, $identityVerifications, $companyVerifications): array {
            $summary = self::summary($user);
            $userId = (int) $user['id'];
            if (isset($professionalProfiles[$userId])) {
                $summary['professionalProfile'] = $professionalProfiles[$userId];
            }
            if (isset($identityVerifications[$userId])) {
                $summary['identityVerification'] = $identityVerifications[$userId];
            }
            if (isset($companyVerifications[$userId])) {
                $summary['companyVerification'] = $companyVerifications[$userId];
            }

            return $summary;
        }, $result['items']);

        return $result;
    }

    /** @return array<string, mixed> */
    public function updateVip(int $id, bool $active): array
    {
        return self::summary($this->users->setVip($id, $active));
    }

    /** @return array<string, mixed> */
    public function updateApproval(int $id, string $status, ?string $reason): array
    {
        $user = $this->users->setApproval($id, $status, $reason);
        if ($this->profiles !== null && $user['role'] === 'ESCORT') {
            $this->profiles->setVerifiedForUser((int) $user['id'], $user['approvalStatus'] === 'APPROVED');
        }
        $emailStatus = 'FAILED';
        if ($this->mail !== null) {
            try {
                $frontend = rtrim(Config::get('APP_FRONTEND_URL', 'http://localhost:4200') ?? 'http://localhost:4200', '/');
                $emailStatus = $this->mail->send(
                    $user['email'],
                    $user['approvalStatus'] === 'APPROVED' ? 'account_approved' : 'account_rejected',
                    $user['locale'],
                    [
                        'name' => $user['firstName'],
                        'url' => $frontend . ($user['approvalStatus'] === 'APPROVED' ? '/auth/login' : '/contenu/contact'),
                    ]
                );
            } catch (\Throwable $exception) {
                // Approval is the source of truth. A mail outage must not
                // roll back or hide the decision already made by the admin.
                error_log('[Chambre Rose API] Approval email could not be queued: ' . $exception->getMessage());
            }
        }

        $summary = self::summary($user);
        $summary['approvalEmailStatus'] = $emailStatus;

        return $summary;
    }

    /** @return array{name:string,contentType:string,bytes:string} */
    public function identityFile(int $userId, string $kind): array
    {
        if ($this->users->find($userId) === null) {
            throw new ApiException(404, 'User not found.');
        }
        if ($this->identityVerification === null) {
            throw new ApiException(503, 'Private identity verification is temporarily unavailable.');
        }

        return $this->identityVerification->file($userId, $kind);
    }

    /** @return array{name:string,contentType:string,bytes:string} */
    public function companyRegistrationFile(int $userId): array
    {
        $user = $this->users->find($userId);
        if ($user === null) {
            throw new ApiException(404, 'User not found.');
        }
        if ($user['role'] !== 'STORE') {
            throw new ApiException(404, 'Company registration document not found.');
        }
        if ($this->companyVerification === null) {
            throw new ApiException(503, 'Private company verification is temporarily unavailable.');
        }

        return $this->companyVerification->file($userId);
    }

    /** @return array<string, mixed> */
    public function updateRole(int $id, string $role): array
    {
        $user = $this->users->setRole($id, $role);
        if ($this->profiles !== null && in_array($user['role'], ['ESCORT', 'STORE'], true)) {
            $this->profiles->synchronizeType((int) $user['id'], $user['role']);
        }

        return self::summary($user);
    }

    public function deleteUser(int $targetId, int $actorId): void
    {
        if ($targetId === $actorId) {
            throw new ApiException(403, 'Administrators cannot delete their own account.');
        }

        if ($this->users->find($targetId) === null) {
            throw new ApiException(404, 'User account not found.');
        }

        $this->users->delete($targetId);
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    /** @param list<string> $allowed */
    private static function enum(?string $value, array $allowed, string $field): ?string
    {
        $value = self::clean($value);
        if ($value === null) {
            return null;
        }
        $value = strtoupper($value);
        if (!in_array($value, $allowed, true)) {
            throw new ApiException(400, 'Invalid administrator filter.', [
                $field => 'contains an unsupported value',
            ]);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private static function summary(array $user): array
    {
        return [
            'id' => $user['id'],
            'firstName' => $user['firstName'],
            'lastName' => $user['lastName'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'address' => $user['address'],
            'city' => $user['city'],
            'country' => $user['country'],
            'postalCode' => $user['postalCode'],
            'locationRegion' => $user['region'],
            'role' => $user['role'],
            'approvalStatus' => $user['approvalStatus'],
            'approvalReason' => $user['approvalReason'],
            'reviewDeadline' => $user['reviewDeadline'],
            'vipActive' => $user['vipActive'],
            'vipSince' => $user['vipSince'],
            'vipUntil' => $user['vipUntil'],
            'createdAt' => $user['createdAt'],
            'updatedAt' => $user['updatedAt'],
        ];
    }
}
