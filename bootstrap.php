<?php

declare(strict_types=1);

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'ChambreRose\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Related small classes are grouped to keep this backend easy to deploy.
require_once __DIR__ . '/src/Http.php';
require_once __DIR__ . '/src/Repositories.php';
require_once __DIR__ . '/src/Messaging.php';
require_once __DIR__ . '/src/Services.php';

use ChambreRose\Config;

Config::loadEnvironment([
    dirname(__DIR__) . '/.env',
    __DIR__ . '/.env',
]);

date_default_timezone_set('UTC');

