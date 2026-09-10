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

final class DatabaseMigrator
{
    private const LOCK_NAME = 'chambre_rose_schema_migrations';
    private const POSTGRES_LOCK_ID = 2407202601;
    private const CHECK_CACHE_SECONDS = 60;
    private bool $changed = false;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function migrate(bool $forceCheck = false): bool
    {
        $this->changed = false;
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $migrations = $this->migrations($driver);
        $cacheFile = $this->cacheFile($driver, $migrations);
        if (!$forceCheck && $this->hasFreshCheckCache($cacheFile)) {
            return false;
        }

        $timestampType = $driver === 'mysql'
            ? 'TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)'
            : 'TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP';
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS chambre_rose_schema_migrations (' .
            'version VARCHAR(80) PRIMARY KEY, applied_at ' . $timestampType . ')'
        );

        if (!$this->hasPendingMigration($migrations)) {
            $this->writeCheckCache($cacheFile);

            return false;
        }

        $baseSchemaCreated = false;
        $this->acquireLock($driver);

        try {
            foreach ($migrations as $migration) {
                if ($this->isApplied($migration['version'])) {
                    continue;
                }
                $this->apply($driver, $migration);
                $this->changed = true;
                $baseSchemaCreated = $baseSchemaCreated || $migration['base'];
            }
        } finally {
            $this->releaseLock($driver);
        }

        $this->writeCheckCache($cacheFile);

        return $this->changed;
    }

    public function changed(): bool
    {
        return $this->changed;
    }

    /** @return list<array{version: string, file: string, base: bool, ignoreDuplicateIndex: bool, resumable?: bool}> */
    private function migrations(string $driver): array
    {
        $suffix = $driver === 'mysql' ? 'mysql' : 'pgsql';

        return [
            [
                'version' => $driver === 'mysql'
                    ? 'php-mysql-3-user-photo-files'
                    : 'php-3-user-photo-files',
                'file' => dirname(__DIR__) . '/database/' . ($driver === 'mysql' ? 'schema.mysql.sql' : 'schema.sql'),
                'base' => true,
                'ignoreDuplicateIndex' => false,
            ],
            [
                'version' => 'php-' . $suffix . '-4-user-list-index',
                'file' => dirname(__DIR__) . '/database/migrations/004-user-list.' . $suffix . '.sql',
                'base' => false,
                'ignoreDuplicateIndex' => true,
            ],
            [
                'version' => 'php-' . $suffix . '-5-neutral-seed-copy',
                'file' => dirname(__DIR__) . '/database/migrations/005-neutral-seed-copy.' . $suffix . '.sql',
                'base' => false,
                'ignoreDuplicateIndex' => false,
            ],
            [
                'version' => 'php-' . $suffix . '-6-marketplace',
                'file' => dirname(__DIR__) . '/database/migrations/006-marketplace.' . $suffix . '.sql',
                'base' => false,
                'ignoreDuplicateIndex' => false,
                'resumable' => true,
            ],
            [
                'version' => 'php-' . $suffix . '-7-commerce',
                'file' => dirname(__DIR__) . '/database/migrations/007-commerce.' . $suffix . '.sql',
                'base' => false,
                'ignoreDuplicateIndex' => false,
                'resumable' => true,
            ],
            [
                'version' => 'php-' . $suffix . '-8-product-reviews',
                'file' => dirname(__DIR__) . '/database/migrations/008-product-reviews.' . $suffix . '.sql',
                'base' => false,
                'ignoreDuplicateIndex' => false,
                'resumable' => true,
            ],
            [
                'version' => 'php-' . $suffix . '-9-profile-experience',
                'file' => dirname(__DIR__) . '/database/migrations/009-profile-experience.' . $suffix . '.sql',
                'base' => false,
                'ignoreDuplicateIndex' => false,
                'resumable' => true,
            ],
            [
                'version' => 'php-' . $suffix . '-10-auth-security',
                'file' => dirname(__DIR__) . '/database/migrations/010-auth-security.' . $suffix . '.sql',
                'base' => false,
                'ignoreDuplicateIndex' => false,
                'resumable' => true,
            ],
            [
                'version' => 'php-' . $suffix . '-11-seed-history',
                'file' => dirname(__DIR__) . '/database/migrations/011-seed-history.' . $suffix . '.sql',
                'base' => false,
                'ignoreDuplicateIndex' => false,
                'resumable' => true,
            ],
            [
                'version' => 'php-' . $suffix . '-12-notifications',
                'file' => dirname(__DIR__) . '/database/migrations/012-notifications.' . $suffix . '.sql',
                'base' => false,
                'ignoreDuplicateIndex' => false,
                'resumable' => true,
            ],
            [
                'version' => 'php-' . $suffix . '-13-push-outbox',
                'file' => dirname(__DIR__) . '/database/migrations/013-push-outbox.' . $suffix . '.sql',
                'base' => false,
                'ignoreDuplicateIndex' => false,
                'resumable' => true,
            ],
            [
                'version' => 'php-' . $suffix . '-14-realtime-events',
                'file' => dirname(__DIR__) . '/database/migrations/014-realtime-events.' . $suffix . '.sql',
                'base' => false,
                'ignoreDuplicateIndex' => false,
                'resumable' => true,
            ],
            [
                'version' => 'php-' . $suffix . '-15-notification-retention',
                'file' => dirname(__DIR__) . '/database/migrations/015-notification-retention.' . $suffix . '.sql',
                'base' => false,
                'ignoreDuplicateIndex' => false,
                'resumable' => true,
            ],
            [
                'version' => 'php-' . $suffix . '-16-notification-preferences',
                'file' => dirname(__DIR__) . '/database/migrations/016-notification-preferences.' . $suffix . '.sql',
                'base' => false,
                'ignoreDuplicateIndex' => false,
                'resumable' => true,
            ],
            [
                'version' => 'php-' . $suffix . '-17-localized-notifications',
                'file' => dirname(__DIR__) . '/database/migrations/017-localized-notifications.' . $suffix . '.sql',
                'base' => false,
                'ignoreDuplicateIndex' => false,
                'resumable' => true,
            ],
            [
                'version' => 'php-' . $suffix . '-18-responsive-images',
                'file' => dirname(__DIR__) . '/database/migrations/018-responsive-images.' . $suffix . '.sql',
                'base' => false,
                'ignoreDuplicateIndex' => false,
                'resumable' => true,
            ],
            [
                'version' => 'php-' . $suffix . '-19-profile-price-options',
                'file' => dirname(__DIR__) . '/database/migrations/019-profile-price-options.' . $suffix . '.sql',
                'base' => false,
                'ignoreDuplicateIndex' => false,
                'resumable' => true,
            ],
            [
                'version' => 'php-' . $suffix . '-20-profile-location-ranking',
                'file' => dirname(__DIR__) . '/database/migrations/020-profile-location-ranking.' . $suffix . '.sql',
                'base' => false,
                'ignoreDuplicateIndex' => false,
                'resumable' => true,
            ],
            [
                'version' => 'php-' . $suffix . '-21-password-reset-codes',
                'file' => dirname(__DIR__) . '/database/migrations/021-password-reset-codes.' . $suffix . '.sql',
                'base' => false,
                'ignoreDuplicateIndex' => false,
                'resumable' => true,
            ],
        ];
    }

    /** @param list<array{version: string, file: string, base: bool, ignoreDuplicateIndex: bool, resumable?: bool}> $migrations */
    private function hasPendingMigration(array $migrations): bool
    {
        foreach ($migrations as $migration) {
            if (!$this->isApplied($migration['version'])) {
                return true;
            }
        }

        return false;
    }

    private function isApplied(string $version): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM chambre_rose_schema_migrations WHERE version = :version'
        );
        $statement->execute(['version' => $version]);

        return (bool) $statement->fetchColumn();
    }

    /** @param array{version: string, file: string, base: bool, ignoreDuplicateIndex: bool, resumable?: bool} $migration */
    private function apply(string $driver, array $migration): void
    {
        $sql = file_get_contents($migration['file']);
        if ($sql === false) {
            throw new RuntimeException('Unable to load database migration: ' . basename($migration['file']) . '.');
        }

        $transactionalDdl = $driver !== 'mysql';
        if ($transactionalDdl) {
            $this->pdo->beginTransaction();
        }

        try {
            try {
                if ($driver === 'mysql' && ($migration['resumable'] ?? false)) {
                    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                        try {
                            $this->pdo->exec($statement);
                        } catch (PDOException $exception) {
                            $code = (int) ($exception->errorInfo[1] ?? 0);
                            if (!in_array($code, [1050, 1060, 1061, 1826], true)) {
                                throw $exception;
                            }
                            $message = strtolower($exception->getMessage());
                            if ($code === 1050) {
                                $expected = str_starts_with(strtolower(ltrim($statement)), 'create table');
                            } elseif ($code === 1060) {
                                $expected = preg_match('/^alter\s+table\s+[a-z0-9_]+\s+add\s+column/i', ltrim($statement)) === 1;
                            } elseif ($code === 1061) {
                                $expected = str_starts_with(strtolower(ltrim($statement)), 'create index');
                            } else {
                                $expected = str_contains($message, 'duplicate foreign key constraint');
                            }
                            if (!$expected) {
                                throw $exception;
                            }
                        }
                    }
                } else {
                    $this->pdo->exec($sql);
                }
            } catch (PDOException $exception) {
                $duplicateIndex = $driver === 'mysql'
                    && $migration['ignoreDuplicateIndex']
                    && (int) ($exception->errorInfo[1] ?? 0) === 1061;
                if (!$duplicateIndex) {
                    throw $exception;
                }
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO chambre_rose_schema_migrations (version) VALUES (:version)'
            );
            $insert->execute(['version' => $migration['version']]);
            if ($transactionalDdl) {
                $this->pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($transactionalDdl && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function acquireLock(string $driver): void
    {
        if ($driver === 'mysql') {
            $statement = $this->pdo->prepare('SELECT GET_LOCK(:name, 10)');
            $statement->execute(['name' => self::LOCK_NAME]);
            if ((int) $statement->fetchColumn() !== 1) {
                throw new ApiException(503, 'Database migration is temporarily busy.');
            }

            return;
        }

        $this->pdo->query('SELECT pg_advisory_lock(' . self::POSTGRES_LOCK_ID . ')');
    }

    private function releaseLock(string $driver): void
    {
        try {
            if ($driver === 'mysql') {
                $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
                $statement->execute(['name' => self::LOCK_NAME]);

                return;
            }
            $this->pdo->query('SELECT pg_advisory_unlock(' . self::POSTGRES_LOCK_ID . ')');
        } catch (\Throwable $exception) {
            error_log('[Chambre Rose API] Unable to release database migration lock: ' . $exception->getMessage());
        }
    }

    /**
     * @param list<array{version: string, file: string, base: bool, ignoreDuplicateIndex: bool}> $migrations
     */
    private function cacheFile(string $driver, array $migrations): string
    {
        $databaseIdentity = Config::first(['DATABASE_URL', 'SPRING_DATASOURCE_URL'])
            ?? implode('|', [
                Config::get('DB_HOST', ''),
                Config::get('DB_PORT', ''),
                Config::get('DB_NAME', ''),
            ]);
        $versions = array_column($migrations, 'version');
        $fingerprint = hash('sha256', implode('|', [
            $driver,
            $databaseIdentity,
            dirname(__DIR__),
            ...$versions,
        ]));

        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
            . 'chambre-rose-schema-' . $fingerprint . '.ok';
    }

    private function hasFreshCheckCache(string $file): bool
    {
        $modifiedAt = is_file($file) ? filemtime($file) : false;

        return $modifiedAt !== false && $modifiedAt >= time() - self::CHECK_CACHE_SECONDS;
    }

    private function writeCheckCache(string $file): void
    {
        if (@file_put_contents($file, (string) time(), LOCK_EX) === false) {
            error_log('[Chambre Rose API] Unable to cache database migration status.');
        }
    }
}
