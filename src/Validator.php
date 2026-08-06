<?php

declare(strict_types=1);

namespace ChambreRose;

final class Validator
{
    /** @param array<string, mixed> $data @return array<string, string> */
    public static function login(array $data): array
    {
        $errors = [];
        self::requiredString($data, 'email', 1, 160, $errors);
        self::email($data, 'email', $errors);
        self::requiredString($data, 'password', 6, 120, $errors);
        self::throwIfInvalid($errors);
        return ['email' => trim((string) $data['email']), 'password' => (string) $data['password']];
    }

    /** @param array<string, mixed> $data @return array<string, string> */
    public static function register(array $data, bool $includePassword = true): array
    {
        $errors = [];
        foreach ([['firstName', 2, 80], ['lastName', 2, 80], ['email', 1, 160], ['phone', 8, 40],
            ['address', 4, 160], ['city', 2, 80], ['country', 2, 80], ['postalCode', 4, 20]] as [$field, $min, $max]) {
            self::requiredString($data, $field, $min, $max, $errors);
        }
        self::email($data, 'email', $errors);
        if ($includePassword) {
            self::requiredString($data, 'password', 6, 120, $errors);
        }
        self::throwIfInvalid($errors);
        $result = [];
        foreach (['firstName', 'lastName', 'email', 'phone', 'address', 'city', 'country', 'postalCode'] as $field) {
            $result[$field] = trim((string) $data[$field]);
        }
        if ($includePassword) {
            $result['password'] = (string) $data['password'];
        }
        return $result;
    }

    /** @param array<string, mixed> $data */
    public static function emailOnly(array $data): string
    {
        $errors = [];
        self::requiredString($data, 'email', 1, 160, $errors);
        self::email($data, 'email', $errors);
        self::throwIfInvalid($errors);
        return strtolower(trim((string) $data['email']));
    }

    /** @param array<string, mixed> $data @return array{token: string, password: string} */
    public static function passwordReset(array $data): array
    {
        $errors = [];
        self::requiredString($data, 'token', 32, 512, $errors);
        self::requiredString($data, 'password', 8, 120, $errors);
        self::throwIfInvalid($errors);
        return ['token' => trim((string) $data['token']), 'password' => (string) $data['password']];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function product(array $data, bool $imageUrlRequired): array
    {
        $errors = [];
        self::requiredString($data, 'name', 1, 120, $errors);
        self::requiredString($data, 'category', 1, 60, $errors);
        self::number($data, 'price', true, 0.01, 99999999.99, $errors);
        self::number($data, 'originalPrice', false, 0.01, 99999999.99, $errors);
        if ($imageUrlRequired) {
            self::requiredString($data, 'imageUrl', 1, 500, $errors);
        } else {
            self::optionalString($data, 'imageUrl', 500, $errors);
        }
        foreach (['secondaryImageUrl' => 500, 'tag' => 40, 'saleLabel' => 40, 'description' => 1500,
            'storeName' => 120, 'storeAddress' => 160, 'storeCity' => 80, 'storeSegment' => 80,
            'storeHours' => 80, 'productType' => 80, 'material' => 160, 'availableSizes' => 120,
            'colorOptions' => 120, 'stockStatus' => 80, 'shippingNote' => 160, 'careInstructions' => 160] as $field => $max) {
            self::optionalString($data, $field, $max, $errors);
        }
        self::integer($data, 'rating', false, 0, 10, $errors);
        self::integer($data, 'reviews', false, 0, 2147483647, $errors);
        self::integer($data, 'purchaseCount', false, 0, 2147483647, $errors);
        self::throwIfInvalid($errors);
        $result = [];
        foreach (['name', 'category', 'imageUrl', 'secondaryImageUrl', 'tag', 'saleLabel', 'description',
            'storeName', 'storeAddress', 'storeCity', 'storeSegment', 'storeHours', 'productType', 'material',
            'availableSizes', 'colorOptions', 'stockStatus', 'shippingNote', 'careInstructions'] as $field) {
            $value = isset($data[$field]) ? trim((string) $data[$field]) : '';
            $result[$field] = $value === '' ? null : $value;
        }
        $result['name'] = trim((string) $data['name']);
        $result['category'] = strtolower(trim((string) $data['category']));
        $result['price'] = (float) $data['price'];
        $result['originalPrice'] = self::nullableFloat($data['originalPrice'] ?? null);
        $result['reviews'] = self::nullableInt($data['reviews'] ?? null) ?? 0;
        $result['rating'] = self::nullableInt($data['rating'] ?? null);
        $result['purchaseCount'] = self::nullableInt($data['purchaseCount'] ?? null);
        return $result;
    }

    /** @param array<string, mixed> $data */
    public static function vipActive(array $data): bool
    {
        if (!array_key_exists('vipActive', $data)) {
            return false;
        }
        if (!is_bool($data['vipActive'])) {
            throw new ApiException(400, 'Invalid request data.', ['vipActive' => 'must be true or false']);
        }
        return $data['vipActive'];
    }

    /** @param array<string, mixed> $data */
    public static function accountStatus(array $data): string
    {
        $status = strtoupper(trim((string) ($data['accountStatus'] ?? '')));
        if (!in_array($status, ['APPROVED', 'REJECTED'], true)) {
            throw new ApiException(400, 'Invalid request data.',
                ['accountStatus' => 'must be APPROVED or REJECTED']);
        }
        return $status;
    }

    /** @param array<string, mixed> $data @param array<string, string> $errors */
    private static function requiredString(array $data, string $field, int $min, int $max, array &$errors): void
    {
        if (!isset($data[$field]) || !is_string($data[$field]) || trim($data[$field]) === '') {
            $errors[$field] = 'must not be blank';
            return;
        }
        if (!self::isValidUtf8($data[$field])) {
            $errors[$field] = 'must be valid UTF-8';
            return;
        }
        $length = self::length($data[$field]);
        if ($length < $min || $length > $max) {
            $errors[$field] = "size must be between {$min} and {$max}";
        }
    }

    /** @param array<string, mixed> $data @param array<string, string> $errors */
    private static function optionalString(array $data, string $field, int $max, array &$errors): void
    {
        if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
            return;
        }
        if (!is_string($data[$field]) || !self::isValidUtf8($data[$field]) || self::length($data[$field]) > $max) {
            $errors[$field] = "size must be between 0 and {$max}";
        }
    }

    /** @param array<string, mixed> $data @param array<string, string> $errors */
    private static function email(array $data, string $field, array &$errors): void
    {
        if (isset($data[$field]) && is_string($data[$field])
            && filter_var(trim($data[$field]), FILTER_VALIDATE_EMAIL) === false) {
            $errors[$field] = 'must be a well-formed email address';
        }
    }

    /** @param array<string, mixed> $data @param array<string, string> $errors */
    private static function number(array $data, string $field, bool $required, float $min, float $max, array &$errors): void
    {
        $value = $data[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $errors[$field] = 'must not be null';
            }
            return;
        }
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < $min || (float) $value > $max) {
            $errors[$field] = "must be between {$min} and {$max}";
        }
    }

    /** @param array<string, mixed> $data @param array<string, string> $errors */
    private static function integer(array $data, string $field, bool $required, int $min, ?int $max, array &$errors): void
    {
        $value = $data[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $errors[$field] = 'must not be null';
            }
            return;
        }
        $valid = filter_var($value, FILTER_VALIDATE_INT);
        if ($valid === false || $valid < $min || ($max !== null && $valid > $max)) {
            $errors[$field] = $max === null ? "must be greater than or equal to {$min}" : "must be between {$min} and {$max}";
        }
    }

    /** @param array<string, string> $errors */
    private static function throwIfInvalid(array $errors): void
    {
        if ($errors !== []) {
            throw new ApiException(400, 'Invalid request data.', $errors);
        }
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private static function isValidUtf8(string $value): bool
    {
        return !function_exists('mb_check_encoding') || mb_check_encoding($value, 'UTF-8');
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
