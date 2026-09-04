<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/IntegrationDataCleanup.php';

use ChambreRose\Database;
use ChambreRose\Tests\IntegrationDataCleanup;

$pdo = Database::connection();
IntegrationDataCleanup::cleanupLegacyFixtures($pdo);

fwrite(STDOUT, "Legacy integration fixtures removed.\n");
