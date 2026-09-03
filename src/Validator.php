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
        self::requiredString($data, 'firstName', 2, 80, $errors);
        self::requiredString($data, 'lastName', 2, 80, $errors);
        self::requiredString($data, 'email', 1, 160, $errors);
        self::email($data, 'email', $errors);
        self::requiredString($data, 'phone', 8, 40, $errors);
        self::optionalString($data, 'address', 160, $errors);
        self::optionalString($data, 'city', 80, $errors);
        self::optionalString($data, 'country', 80, $errors);
        self::optionalString($data, 'postalCode', 20, $errors);
        if ($includePassword) {
            self::validatePassword($data['password'] ?? null, $errors);
        }
        self::throwIfInvalid($errors);

        $result = [];
        foreach (['firstName', 'lastName', 'email', 'phone', 'address', 'city', 'country', 'postalCode'] as $field) {
            $result[$field] = trim((string) ($data[$field] ?? ''));
        }
        if ($includePassword) {
            $result['password'] = (string) $data['password'];
        }

        return $result;
    }

    public static function accountType(array $data): string
    {
        $value = strtoupper(trim((string) ($data['accountType'] ?? $data['role'] ?? 'VISITOR')));
        if ($value === 'USER') {
            $value = 'VISITOR';
        }
        if (!in_array($value, ['VISITOR', 'ESCORT', 'STORE'], true)) {
            throw new ApiException(400, 'Invalid request data.', [
                'accountType' => 'must be VISITOR, ESCORT or STORE',
            ]);
        }

        return $value;
    }

    public static function locale(array $data): string
    {
        $locale = strtolower(substr(trim((string) ($data['locale'] ?? 'fr')), 0, 2));

        return in_array($locale, ['fr', 'en', 'pt'], true) ? $locale : 'fr';
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
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

    /** @param array<string,string> $errors */
    public static function validatePassword(mixed $password, array &$errors): void
    {
        if (!is_string($password) || strlen($password) < 8 || strlen($password) > 20) {
            $errors['password'] = 'must contain between 8 and 20 characters';

            return;
        }
        if (!preg_match('/[a-z]/', $password)
            || !preg_match('/[A-Z]/', $password)
            || !preg_match('/\d/', $password)
            || !preg_match('/[^A-Za-z0-9]/', $password)
        ) {
            $errors['password'] = 'must include an uppercase letter, a lowercase letter, a number and a special character';
        }
    }

    public static function password(string $password): string
    {
        $errors = [];
        self::validatePassword($password, $errors);
        self::throwIfInvalid($errors);

        return $password;
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
        if (isset($data[$field]) && is_string($data[$field]) && filter_var(trim($data[$field]), FILTER_VALIDATE_EMAIL) === false) {
            $errors[$field] = 'must be a well-formed email address';
        }
    }

    /** @param array<string, mixed> $data @param array<string, string> $errors */
    private static function number(
        array $data,
        string $field,
        bool $required,
        float $min,
        float $max,
        array &$errors
    ): void {
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
