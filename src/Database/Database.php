<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        [$dsn, $username, $password] = self::settings();

        try {
            self::$connection = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 10,
            ]);
            $driver = self::$connection->getAttribute(PDO::ATTR_DRIVER_NAME);
            self::$connection->exec($driver === 'mysql' ? "SET time_zone = '+00:00'" : "SET TIME ZONE 'UTC'");
        } catch (PDOException $exception) {
            error_log('[Chambre Rose API] Database connection failed: ' . $exception->getMessage());

            throw new ApiException(503, 'Database connection is unavailable.');
        }

        return self::$connection;
    }

    /** @return array{string, string, string} */
    private static function settings(): array
    {
        $url = Config::first(['DATABASE_URL', 'SPRING_DATASOURCE_URL']);
        $username = Config::first(['DB_USERNAME', 'SPRING_DATASOURCE_USERNAME'], '') ?? '';
        $password = Config::first(['DB_PASSWORD', 'SPRING_DATASOURCE_PASSWORD'], '') ?? '';

        if ($url !== null) {
            $url = preg_replace('/^jdbc:/', '', $url) ?? $url;
            if (str_starts_with($url, 'mysql://')) {
                $parts = parse_url($url);
                if ($parts === false || !isset($parts['host'])) {
                    throw new RuntimeException('DATABASE_URL is invalid.');
                }

                if (isset($parts['user'])) {
                    $username = rawurldecode($parts['user']);
                }
                if (isset($parts['pass'])) {
                    $password = rawurldecode($parts['pass']);
                }

                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $parts['host'],
                    (int) ($parts['port'] ?? 3306),
                    ltrim($parts['path'] ?? '/', '/')
                );

                return [$dsn, $username, $password];
            }

            if (str_starts_with($url, 'postgresql://') || str_starts_with($url, 'postgres://')) {
                $parts = parse_url($url);
                if ($parts === false || !isset($parts['host'])) {
                    throw new RuntimeException('DATABASE_URL is invalid.');
                }

                if (isset($parts['user'])) {
                    $username = rawurldecode($parts['user']);
                }
                if (isset($parts['pass'])) {
                    $password = rawurldecode($parts['pass']);
                }

                parse_str($parts['query'] ?? '', $query);
                $dsn = sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
                    $parts['host'],
                    (int) ($parts['port'] ?? 5432),
                    ltrim($parts['path'] ?? '/postgres', '/'),
                    $query['sslmode'] ?? 'require'
                );

                return [$dsn, $username, $password];
            }
        }

        $host = Config::get('DB_HOST');
        if ($host === null) {
            throw new RuntimeException('Configure DATABASE_URL, SPRING_DATASOURCE_URL or DB_HOST.');
        }

        $driver = strtolower(Config::get('DB_DRIVER', 'mysql') ?? 'mysql');
        if ($driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $host,
                Config::int('DB_PORT', 3306),
                Config::get('DB_NAME', ''),
                Config::get('DB_CHARSET', 'utf8mb4')
            );

            return [$dsn, $username, $password];
        }

        if ($driver !== 'pgsql' && $driver !== 'postgres' && $driver !== 'postgresql') {
            throw new RuntimeException('DB_DRIVER must be mysql or pgsql.');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $host,
            Config::int('DB_PORT', 5432),
            Config::get('DB_NAME', 'postgres'),
            Config::get('DB_SSLMODE', 'require')
        );

        return [$dsn, $username, $password];
    }
}
