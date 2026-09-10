<?php

declare(strict_types=1);

namespace ChambreRose;

use PDOException;

final class AuthService
{
    private const MAX_REGISTRATION_PHOTO_BYTES = 8 * 1024 * 1024;
    private const DUMMY_PASSWORD_HASH = '$2y$12$QUdNK6i4Nykerj8gfpyc9u7pRhWnZ/eWluu5l7vwDEnnZ9O8R4OHG';

    public function __construct(
        private readonly UserRepository $users,
        private readonly Jwt $jwt,
        private readonly MarketplaceService $marketplace,
        private readonly PasswordResetRepository $passwordResets,
        private readonly MailService $mail,
        private readonly ?UserNotificationService $notifications = null
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function login(array $input): array
    {
        $data = Validator::login($input);
        $email = strtolower($data['email']);
        $user = $this->users->findByEmail($email);
        $passwordMatches = password_verify(
            $data['password'],
            $user['passwordHash'] ?? self::DUMMY_PASSWORD_HASH
        );
        if ($user === null || !$passwordMatches) {
            throw new ApiException(401, 'Invalid email or password.');
        }
        if ($user['approvalStatus'] === 'PENDING') {
            throw new ApiException(403, 'Your account is awaiting administrator approval. Reviews are completed within 24 hours.', [
                'approvalStatus' => 'PENDING',
                'reviewDeadline' => $user['reviewDeadline'],
            ]);
        }
        if ($user['approvalStatus'] === 'REJECTED') {
            throw new ApiException(403, 'Your account was not approved. Contact support for assistance.', ['approvalStatus' => 'REJECTED']);
        }
        if (array_key_exists('locale', $input)) {
            $locale = strtolower(trim((string) $input['locale']));
            if (in_array($locale, ['en', 'fr'], true) && $locale !== $user['locale']) {
                $user = $this->users->updateLocale((int) $user['id'], $locale);
            }
        }

        return $this->authenticationResponse($user);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function register(array $input, ?UploadedFile $registrationPhoto = null): array
    {
        $data = Validator::register($input);
        $type = Validator::accountType($input);
        $locale = Validator::locale($input);
        $data['email'] = strtolower($data['email']);
        if ($this->users->emailExists($data['email'])) {
            throw new ApiException(409, 'Email is already registered.');
        }
        $photoInfo = $this->validateRegistrationPhoto($registrationPhoto);
        if (in_array($type, ['ESCORT', 'STORE'], true) && $photoInfo === null) {
            throw new ApiException(400, 'A profile photo is required for professional accounts.', [
                'establishmentPhoto' => 'is required',
            ]);
        }
        $professionalProfile = null;
        if (in_array($type, ['ESCORT', 'STORE'], true)) {
            $professionalProfile = $input['profile'] ?? $input;
            if (is_string($professionalProfile)) {
                $decoded = json_decode($professionalProfile, true);
                $professionalProfile = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($professionalProfile)) {
                throw new ApiException(400, 'Invalid professional profile data.', ['profile' => 'must be an object']);
            }
            // Reject invalid professional data before an account row is inserted.
            $this->marketplace->validateProfileInput($type, $professionalProfile, $data);
        }

        $user = null;

        try {
            $user = $this->users->create(
                $data,
                password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]),
                $type,
                in_array($type, ['ESCORT', 'STORE'], true) ? 'PENDING' : 'APPROVED',
                $locale
            );
            if (in_array($type, ['ESCORT', 'STORE'], true)) {
                $this->marketplace->saveProfile((int) $user['id'], $professionalProfile ?? []);
                if ($registrationPhoto !== null) {
                    $this->marketplace->upload((int) $user['id'], $registrationPhoto, 0);
                }
            }
            $user = $this->users->find($user['id'])
                ?? throw new ApiException(500, 'Unable to load the new account.');
        } catch (PDOException $exception) {
            $this->cleanupFailedRegistration($user);
            if (in_array($exception->getCode(), ['23000', '23505'], true)) {
                throw new ApiException(409, 'Email is already registered.');
            }

            throw $exception;
        } catch (\Throwable $exception) {
            $this->cleanupFailedRegistration($user);

            throw $exception;
        }
        if (in_array($type, ['ESCORT', 'STORE'], true)) {
            $emailStatus = 'FAILED';
            try {
                $emailStatus = $this->mail->send($user['email'], 'account_created', $locale, [
                    'name' => $user['firstName'],
                ]);
            } catch (\Throwable $exception) {
                // A transient mail/database problem must not turn a valid
                // registration into a misleading server error. The account
                // remains pending and the failure is available in the log.
                error_log('[Chambre Rose API] Registration email could not be queued: ' . $exception->getMessage());
            }

            $message = match ($locale) {
                'pt' => 'Cadastro recebido. Sua conta foi criada, mas permanece inativa até o administrador aprovar seu perfil. A análise será concluída em até 24 horas e você receberá um email com a decisão.',
                'en' => 'Registration received. Your account was created, but it remains inactive until an administrator approves your profile. The review will be completed within 24 hours and you will receive an email with the decision.',
                default => 'Inscription reçue. Votre compte a été créé, mais reste inactif jusqu’à la validation de votre profil par un administrateur. L’examen sera terminé sous 24 heures et vous recevrez un e-mail avec la décision.',
            };

            return [
                'pendingApproval' => true,
                'approvalStatus' => 'PENDING',
                'reviewDeadline' => $user['reviewDeadline'],
                'role' => $user['role'],
                'message' => $message,
                'emailStatus' => $emailStatus,
                'profile' => self::profileFromUser($user),
            ];
        }

        return $this->authenticationResponse($user);
    }

    /** @return array<string, mixed> */
    public function profile(string $email): array
    {
        $user = $this->users->findByEmail(strtolower(trim($email)));
        if ($user === null) {
            throw new ApiException(404, 'User profile not found.');
        }

        return self::profileFromUser($user);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateProfile(string $currentEmail, array $input): array
    {
        $data = Validator::register($input, false);
        $data['email'] = strtolower($data['email']);
        $user = $this->users->findByEmail(strtolower(trim($currentEmail)));
        if ($user === null) {
            throw new ApiException(404, 'User profile not found.');
        }
        if ($this->users->emailExists($data['email'], $user['id'])) {
            throw new ApiException(409, 'Email is already registered.');
        }

        try {
            $updated = $this->users->updateProfile($user['id'], $data);
        } catch (PDOException $exception) {
            if (in_array($exception->getCode(), ['23000', '23505'], true)) {
                throw new ApiException(409, 'Email is already registered.');
            }

            throw $exception;
        }

        $this->notifications?->notify(
            (int) $updated['id'],
            UserNotificationService::ACCOUNT,
            'PROFILE_UPDATED',
            '/espace-prive/perfil',
            null
        );

        return $this->authenticationResponse($updated);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateLocale(string $currentEmail, array $input): array
    {
        $locale = strtolower(trim((string) ($input['locale'] ?? '')));
        if (!in_array($locale, ['en', 'fr'], true)) {
            throw new ApiException(400, 'Locale must be en or fr.', ['locale' => 'must be en or fr']);
        }
        $user = $this->users->findByEmail(strtolower(trim($currentEmail)));
        if ($user === null) {
            throw new ApiException(404, 'User profile not found.');
        }

        return self::profileFromUser($this->users->updateLocale((int) $user['id'], $locale));
    }

    /**
     * Permanently removes the authenticated account and all data owned by it.
     * The database foreign keys cascade dependent profile, media, messaging and
     * notification rows while preserving historical orders where appropriate.
     */
    public function deleteAccount(string $currentEmail): void
    {
        $user = $this->users->findByEmail(strtolower(trim($currentEmail)));
        if ($user === null) {
            throw new ApiException(404, 'User account not found.');
        }

        $this->users->delete((int) $user['id']);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function profileFromUser(array $user): array
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
            'role' => $user['role'],
            'approvalStatus' => $user['approvalStatus'],
            'approvalReason' => $user['approvalReason'],
            'reviewDeadline' => $user['reviewDeadline'],
            'approvedAt' => $user['approvedAt'],
            'locale' => $user['locale'],
            'vipActive' => $user['vipActive'],
            'vipSince' => $user['vipSince'],
            'vipUntil' => $user['vipUntil'],
        ];
    }

    /** @return array{contentType: string, size: int}|null */
    private function validateRegistrationPhoto(?UploadedFile $photo): ?array
    {
        if ($photo === null || $photo->isEmpty()) {
            return null;
        }
        if ($photo->size > self::MAX_REGISTRATION_PHOTO_BYTES) {
            throw new ApiException(413, 'A profile photo cannot exceed 8 MB.');
        }
        $size = $photo->actualSize();
        if ($size > self::MAX_REGISTRATION_PHOTO_BYTES) {
            throw new ApiException(413, 'A profile photo cannot exceed 8 MB.');
        }

        $mime = $photo->detectedContentType();
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new ApiException(400, 'A profile photo must be a JPG, PNG or WebP image.');
        }

        return ['contentType' => $mime, 'size' => $size];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function forgotPassword(array $input): array
    {
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $challenge = $this->passwordResets->anonymousChallenge();
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $user = $this->users->findByEmail($email);
            if ($user !== null) {
                $issued = $this->passwordResets->issueCode((int) $user['id']);
                $challenge = $issued['challenge'];
                $this->mail->send($user['email'], 'password_reset', Validator::locale($input + ['locale' => $user['locale']]), [
                    'name' => $user['firstName'],
                    'code' => $issued['code'],
                ]);
                $this->notifications?->notify(
                    (int) $user['id'],
                    UserNotificationService::SECURITY,
                    'PASSWORD_RESET_REQUESTED',
                    '/contenu/contact',
                    null
                );
            }
        }

        return [
            'message' => 'If the account exists, a password reset verification code will be sent.',
            'challenge' => $challenge,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function verifyPasswordResetCode(array $input): array
    {
        $challenge = is_string($input['challenge'] ?? null) ? trim($input['challenge']) : '';
        $code = is_string($input['code'] ?? null) ? trim($input['code']) : '';
        if (strlen($challenge) < 32 || !preg_match('/^\d{6}$/', $code)) {
            throw new ApiException(400, 'Invalid or expired verification code.');
        }
        if (!$this->passwordResets->verifyCode($challenge, $code)) {
            throw new ApiException(400, 'Invalid or expired verification code.');
        }

        return ['message' => 'Verification code accepted.'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function resetPassword(array $input): array
    {
        $token = is_string($input['token'] ?? ($input['challenge'] ?? null))
            ? trim((string) ($input['token'] ?? $input['challenge']))
            : '';
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        if (strlen($token) < 32) {
            throw new ApiException(400, 'Invalid or expired password reset request.');
        }
        Validator::password($password);
        $userId = $this->passwordResets->consume($token);
        if ($userId === null) {
            throw new ApiException(400, 'Invalid or expired password reset request.');
        }
        $this->users->updatePassword($userId, password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]));
        $this->notifications?->notify(
            $userId,
            UserNotificationService::SECURITY,
            'PASSWORD_CHANGED',
            '/contenu/contact',
            null
        );

        return ['message' => 'Password updated successfully.'];
    }

    /** @param array<string, mixed>|null $user */
    private function cleanupFailedRegistration(?array $user): void
    {
        if ($user !== null && isset($user['id'])) {
            $this->users->delete((int) $user['id']);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function authenticationResponse(array $user): array
    {
        $profile = self::profileFromUser($user);

        return [
            'token' => $this->jwt->generate($user['email'], $user['role']),
            'userEmail' => $user['email'],
            'role' => $user['role'],
            'profile' => $profile,
        ];
    }
}

final class AdminUserService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ?MailService $mail = null,
        private readonly ?ProfessionalProfileRepository $profiles = null
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
        $result['items'] = array_map(function (array $user) use ($professionalProfiles): array {
            $summary = self::summary($user);
            $userId = (int) $user['id'];
            if (isset($professionalProfiles[$userId])) {
                $summary['professionalProfile'] = $professionalProfiles[$userId];
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
                        'url' => $frontend . '/auth/login',
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
