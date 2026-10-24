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

    $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    $directories = [
        __DIR__ . '/src',
        __DIR__ . '/src/Core',
        __DIR__ . '/src/Database',
        __DIR__ . '/src/Http',
        __DIR__ . '/src/Media',
        __DIR__ . '/src/Notifications',
        __DIR__ . '/src/Repositories',
        __DIR__ . '/src/Routes',
        __DIR__ . '/src/Security',
        __DIR__ . '/src/Services',
    ];
    foreach ($directories as $directory) {
        $file = $directory . '/' . $relative;
        if (is_file($file)) {
            require $file;

            return;
        }
    }
}, true, true);

use ChambreRose\Config;

Config::loadEnvironment([
    dirname(__DIR__) . '/.env',
    __DIR__ . '/.env',
]);

date_default_timezone_set('UTC');
