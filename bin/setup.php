<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use ChambreRose\Database;
use ChambreRose\DatabaseMigrator;
use ChambreRose\Seeder;

try {
    $pdo = Database::connection();
    $migrator = new DatabaseMigrator($pdo);
    $migrator->migrate(true);
    (new Seeder($pdo))->run();
    fwrite(STDOUT, $migrator->changed()
        ? "Banco atualizado e seeds verificados.\n"
        : "Banco ja estava atualizado; seeds verificados.\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Falha no setup: {$exception->getMessage()}\n");
    exit(1);
}
