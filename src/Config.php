<?php

declare(strict_types=1);

namespace ChambreRose;

final class Config
{
    /** @param list<string> $paths */
    public static function loadEnvironment(array $paths): void
    {
        foreach ($paths as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                if ($name === '' || getenv($name) !== false || array_key_exists($name, $_ENV)) {
                    continue;
                }

                $value = trim($value);
                if (strlen($value) >= 2) {
                    $first = $value[0];
                    $last = $value[strlen($value) - 1];
                    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                        $value = substr($value, 1, -1);
                    }
                }

                $_ENV[$name] = $value;
                putenv($name . '=' . $value);
            }
        }
    }

    public static function get(string $name, ?string $default = null): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return $value === false || $value === '' ? $default : (string) $value;
    }

    /** @param list<string> $names */
    public static function first(array $names, ?string $default = null): ?string
    {
        foreach ($names as $name) {
            $value = self::get($name);
            if ($value !== null) {
                return $value;
            }
        }

        return $default;
    }

    public static function bool(string $name, bool $default = false): bool
    {
        $value = self::get($name);
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public static function int(string $name, int $default): int
    {
        $value = self::get($name);

        return $value !== null && filter_var($value, FILTER_VALIDATE_INT) !== false
            ? (int) $value
            : $default;
    }
}
