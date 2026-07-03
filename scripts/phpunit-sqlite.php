<?php

$phpBinary = PHP_BINARY ?: 'php';
$phpunit = dirname(__DIR__).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'phpunit';
$arguments = array_slice($_SERVER['argv'] ?? [], 1);

$command = [$phpBinary];

foreach (['pdo_sqlite', 'sqlite3'] as $extension) {
    if (!extension_loaded($extension)) {
        $command[] = '-d';
        $command[] = "extension={$extension}";
    }
}

$command[] = $phpunit;

foreach ($arguments as $argument) {
    $command[] = $argument;
}

$escapedCommand = implode(' ', array_map(static function (string $part): string {
    return escapeshellarg($part);
}, $command));

passthru($escapedCommand, $exitCode);

exit($exitCode);
