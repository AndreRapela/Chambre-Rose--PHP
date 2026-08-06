<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;
use PDOException;
use Throwable;

final class AuthService
{
    private const MAX_ESTABLISHMENT_PHOTO_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly UserRepository $users,
        private readonly Jwt $jwt,
        private readonly EstablishmentPhotoStorage $photos,
        private readonly Mailer $mailer
    ) {
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function login(array $input): array
    {
        $data = Validator::login($input);
        $user = $this->users->findByEmail(strtolower($data['email']));
        if ($user === null || !password_verify($data['password'], $user['passwordHash'])) {
            throw new ApiException(401, 'Invalid email or password.');
        }
        return $this->authenticationResponse($user);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function register(array $input, ?UploadedFile $establishmentPhoto = null): array
    {
        $data = Validator::register($input);
        $photoInfo = $this->validateEstablishmentPhoto($establishmentPhoto);
        $data['email'] = strtolower($data['email']);
        if ($this->users->emailExists($data['email'])) {
            throw new ApiException(409, 'Email is already registered.');
        }
        $user = null;
        $storageKey = null;
        try {
            $user = $this->users->create($data, password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]), 'USER');
            $storageKey = $this->photos->store($user['id'], $establishmentPhoto, $photoInfo['contentType']);
            $this->users->saveEstablishmentPhoto($user['id'], $storageKey, $establishmentPhoto->name,
                $photoInfo['contentType'], $photoInfo['size']);
            $user = $this->users->find($user['id']) ?? throw new ApiException(500, 'Unable to load the new account.');
        } catch (PDOException $exception) {
            $this->cleanupFailedRegistration($user, $storageKey);
            if (in_array($exception->getCode(), ['23000', '23505'], true)) {
                throw new ApiException(409, 'Email is already registered.');
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->cleanupFailedRegistration($user, $storageKey);
            throw $exception;
        }
        try {
            $this->mailer->sendRegistrationConfirmation($user['email'], $user['firstName']);
        } catch (Throwable $exception) {
            error_log('[Chambre Rose API] Registration confirmation email failed: ' . $exception->getMessage());
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

    /** @param array<string, mixed> $input @return array<string, mixed> */
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
        return $this->authenticationResponse($updated);
    }

    /** @param array<string, mixed> $user @return array<string, mixed> */
    public static function profileFromUser(array $user): array
    {
        return ['id'=>$user['id'],'firstName'=>$user['firstName'],'lastName'=>$user['lastName'],
            'email'=>$user['email'],'phone'=>$user['phone'],'address'=>$user['address'],'city'=>$user['city'],
            'country'=>$user['country'],'postalCode'=>$user['postalCode'],'role'=>$user['role'],
            'vipActive'=>$user['vipActive'],'vipSince'=>$user['vipSince'],'vipUntil'=>$user['vipUntil'],
            'establishmentPhotoUrl'=>$user['establishmentPhotoUrl'] ?? null];
    }

    /** @return array{contentType: string, size: int} */
    private function validateEstablishmentPhoto(?UploadedFile $photo): array
    {
        if ($photo === null || $photo->isEmpty()) {
            throw new ApiException(400, 'An establishment photo is required.', ['establishmentPhoto' => 'is required']);
        }
        if ($photo->size > self::MAX_ESTABLISHMENT_PHOTO_BYTES || $photo->actualSize() > self::MAX_ESTABLISHMENT_PHOTO_BYTES) {
            throw new ApiException(413, 'The optimized establishment photo cannot exceed 2 MB.');
        }
        $mime = $photo->detectedContentType();
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new ApiException(400, 'The establishment photo must be a JPG, PNG or WebP image.');
        }
        return ['contentType' => $mime, 'size' => $photo->actualSize()];
    }

    /** @param array<string, mixed>|null $user */
    private function cleanupFailedRegistration(?array $user, ?string $storageKey): void
    {
        $this->photos->delete($storageKey);
        if ($user !== null && isset($user['id'])) {
            $this->users->delete((int) $user['id']);
        }
    }

    /** @param array<string, mixed> $user @return array<string, mixed> */
    private function authenticationResponse(array $user): array
    {
        return ['token'=>$this->jwt->generate($user['email'], $user['role']),'userEmail'=>$user['email'],
            'role'=>$user['role'],'profile'=>self::profileFromUser($user)];
    }
}

final class ProductService
{
    private const MAX_IMAGE_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly PDO $pdo, private readonly ProductRepository $products)
    {
    }

    /** @param array<string, mixed> $input */
    public function createJson(array $input): array
    {
        $data = Validator::product($input, true);
        $data['purchaseCount'] = 0;
        $data['rating'] = 0;
        return $this->products->create($data);
    }

    /** @param array<string, mixed> $input */
    public function updateJson(int $id, array $input): array
    {
        $data = Validator::product($input, true);
        return $this->transaction(function () use ($id, $data): array {
            $existing = $this->requiredForUpdate($id);
            $data['purchaseCount'] = max(0, (int) ($existing['purchaseCount'] ?? 0));
            $data['rating'] = $existing['rating'];
            return $this->products->update($id, $data);
        });
    }

    /** @param array<string, mixed> $fields @param array<string, UploadedFile> $files */
    public function createForm(array $fields, array $files): array
    {
        $data = Validator::product($fields, false);
        $main = $files['mainImage'] ?? null;
        if ($data['imageUrl'] === null && ($main === null || $main->isEmpty())) {
            throw new ApiException(400, 'Main image is required.');
        }
        $this->validateOptionalImage($main);
        $this->validateOptionalImage($files['secondaryImage'] ?? null);
        $data['imageUrl'] ??= 'pending-image-upload';
        $data['purchaseCount'] = 0;
        $data['rating'] = 0;
        return $this->transaction(function () use ($data, $files): array {
            $product = $this->products->create($data);
            $this->storeImages($product['id'], $files);
            return $this->products->update($product['id'], $this->applyStoredImageUrls($product));
        });
    }

    /** @param array<string, mixed> $fields @param array<string, UploadedFile> $files */
    public function updateForm(int $id, array $fields, array $files): array
    {
        $data = Validator::product($fields, false);
        $this->validateOptionalImage($files['mainImage'] ?? null);
        $this->validateOptionalImage($files['secondaryImage'] ?? null);
        return $this->transaction(function () use ($id, $data, $files): array {
            $existing = $this->requiredForUpdate($id);
            $data['imageUrl'] ??= $existing['imageUrl'];
            $data['secondaryImageUrl'] ??= $existing['secondaryImageUrl'];
            $data['purchaseCount'] = max(0, (int) ($existing['purchaseCount'] ?? 0));
            $data['rating'] = $existing['rating'];
            $product = $this->products->update($id, $data);
            $this->storeImages($id, $files);
            return $this->products->update($id, $this->applyStoredImageUrls($product));
        });
    }

    private function requiredForUpdate(int $id): array
    {
        return $this->products->findForUpdate($id) ?? throw new ApiException(404, 'Product not found.');
    }

    /** @param array<string, UploadedFile> $files */
    private function storeImages(int $id, array $files): void
    {
        foreach (['mainImage' => 'MAIN', 'secondaryImage' => 'SECONDARY'] as $field => $role) {
            $file = $files[$field] ?? null;
            if ($file === null || $file->isEmpty()) {
                continue;
            }
            $name = trim(preg_replace('/[\x00-\x1F\x7F"]/', '', basename(str_replace('\\', '/', $file->name))) ?? '')
                ?: 'product-image';
            $this->products->upsertImage($id, $role, $name, $file->detectedContentType(), $file->bytes());
        }
    }

    /** @param array<string, mixed> $product @return array<string, mixed> */
    private function applyStoredImageUrls(array $product): array
    {
        if ($this->products->hasImage($product['id'], 'MAIN')) {
            $product['imageUrl'] = "/api/products/{$product['id']}/images/main";
        }
        if ($this->products->hasImage($product['id'], 'SECONDARY')) {
            $product['secondaryImageUrl'] = "/api/products/{$product['id']}/images/secondary";
        }
        return $product;
    }

    private function validateOptionalImage(?UploadedFile $file): void
    {
        if ($file === null || $file->isEmpty()) {
            return;
        }
        if ($file->size > self::MAX_IMAGE_BYTES || $file->actualSize() > self::MAX_IMAGE_BYTES) {
            throw new ApiException(400, 'Product image cannot exceed 8MB.');
        }
        if (!in_array($file->detectedContentType(), ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new ApiException(400, 'Product image must be a JPG, PNG or WebP image.');
        }
    }

    private function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}

final class AdminUserService
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $email, ?string $name, string $sort): array
    {
        return array_map([self::class, 'summary'],
            $this->users->list(self::clean($email), self::clean($name), $sort));
    }

    public function updateVip(int $id, bool $active): array
    {
        return self::summary($this->users->setVip($id, $active));
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);
        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $user @return array<string, mixed> */
    private static function summary(array $user): array
    {
        return ['id'=>$user['id'],'firstName'=>$user['firstName'],'lastName'=>$user['lastName'],
            'email'=>$user['email'],'role'=>$user['role'],'vipActive'=>$user['vipActive'],
            'vipSince'=>$user['vipSince'],'vipUntil'=>$user['vipUntil'],'createdAt'=>$user['createdAt'],
            'updatedAt'=>$user['updatedAt'],'establishmentPhotoUrl'=>$user['establishmentPhotoUrl'] ?? null];
    }
}

