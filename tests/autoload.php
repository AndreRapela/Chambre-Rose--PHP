<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$loaded = array_filter(get_declared_classes(), static fn (string $class): bool => str_starts_with($class, 'ChambreRose\\'));
if (array_values($loaded) !== [ChambreRose\Config::class]) {
    throw new RuntimeException('Bootstrap must not eagerly load unrelated application classes.');
}

$assertions = 1;
$sourceRoot = dirname(__DIR__) . '/src';
$sources = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS));
foreach ($sources as $source) {
    if (!$source instanceof SplFileInfo || $source->getExtension() !== 'php') {
        continue;
    }
    $relative = substr($source->getPathname(), strlen($sourceRoot) + 1, -4);
    $class = 'ChambreRose\\' . str_replace(['/', '\\'], '\\', $relative);
    if (!class_exists($class) && !interface_exists($class) && !trait_exists($class) && !enum_exists($class)) {
        throw new RuntimeException('Source does not provide its PSR-4 declaration: ' . $relative);
    }
    $assertions++;
    $reflection = new ReflectionClass($class);
    if ($reflection->getFileName() !== $source->getRealPath()) {
        throw new RuntimeException('Application declaration resolved from an unexpected file: ' . $class);
    }
    $assertions++;
}
if (class_exists('ChambreRose\\MissingApplicationClass') || class_exists('UnknownVendor\\MissingClass')) {
    throw new RuntimeException('Unknown classes must not resolve to an application source.');
}
$assertions += 2;
echo 'OK - ' . $assertions . " autoload assertions\n";
