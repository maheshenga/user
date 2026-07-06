<?php

declare(strict_types=1);

$recordFile = getenv('DEPLOY_ACCEPTANCE_RECORD_FILE');

if (is_string($recordFile) && $recordFile !== '') {
    file_put_contents($recordFile, json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND);
}

$failNeedle = getenv('DEPLOY_ACCEPTANCE_FAIL_CONTAINS');
$commandLine = implode(' ', array_slice($argv, 1));

if (is_string($failNeedle) && $failNeedle !== '' && str_contains($commandLine, $failNeedle)) {
    fwrite(STDERR, "fixture failure for {$failNeedle}\n");
    exit(17);
}

fwrite(STDOUT, "fixture ok: {$commandLine}\n");
exit(0);
