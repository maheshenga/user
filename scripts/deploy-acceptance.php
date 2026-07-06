<?php

declare(strict_types=1);

final class DeploymentAcceptanceFailure extends RuntimeException
{
}

/**
 * @return array{
 *     base_url:?string,
 *     admin_prefix:string,
 *     admin_username:string,
 *     admin_password:string,
 *     portal_email:?string,
 *     portal_password:string,
 *     timeout:float,
 *     php_binary:string,
 *     skip_env:bool,
 *     skip_migrate:bool,
 *     skip_menu_sync:bool,
 *     skip_portal:bool,
 *     skip_admin:bool,
 *     dry_run:bool
 * }
 */
function parseDeploymentOptions(): array
{
    $options = getopt('', [
        'base-url:',
        'admin-prefix:',
        'admin-username:',
        'admin-password:',
        'portal-email:',
        'portal-password:',
        'timeout:',
        'php-binary:',
        'skip-env',
        'skip-migrate',
        'skip-menu-sync',
        'skip-portal',
        'skip-admin',
        'dry-run',
    ]);

    if ($options === false) {
        throw new DeploymentAcceptanceFailure('Unable to parse options.');
    }

    $skipPortal = hasOptionFlag($options, 'skip-portal');
    $skipAdmin = hasOptionFlag($options, 'skip-admin');
    $baseUrl = optionString($options, 'base-url');
    $baseUrl = $baseUrl === null ? null : rtrim($baseUrl, '/');

    if ($baseUrl === null && (! $skipPortal || ! $skipAdmin)) {
        throw new DeploymentAcceptanceFailure('Missing required option: --base-url');
    }

    if ($baseUrl === '' && (! $skipPortal || ! $skipAdmin)) {
        throw new DeploymentAcceptanceFailure('Missing required option: --base-url');
    }

    $timeout = optionString($options, 'timeout', '10');

    if (! is_string($timeout) || ! is_numeric($timeout) || (float) $timeout <= 0) {
        throw new DeploymentAcceptanceFailure('Invalid --timeout value.');
    }

    return [
        'base_url' => $baseUrl,
        'admin_prefix' => trim(optionString($options, 'admin-prefix', 'admin') ?? 'admin', " \t\n\r\0\x0B/"),
        'admin_username' => optionString($options, 'admin-username', 'admin') ?? 'admin',
        'admin_password' => optionString($options, 'admin-password', '123456') ?? '123456',
        'portal_email' => optionString($options, 'portal-email'),
        'portal_password' => optionString($options, 'portal-password', 'secret123') ?? 'secret123',
        'timeout' => (float) $timeout,
        'php_binary' => optionString($options, 'php-binary', PHP_BINARY) ?? PHP_BINARY,
        'skip_env' => hasOptionFlag($options, 'skip-env'),
        'skip_migrate' => hasOptionFlag($options, 'skip-migrate'),
        'skip_menu_sync' => hasOptionFlag($options, 'skip-menu-sync'),
        'skip_portal' => $skipPortal,
        'skip_admin' => $skipAdmin,
        'dry_run' => hasOptionFlag($options, 'dry-run'),
    ];
}

/**
 * @param array<string, mixed> $options
 */
function optionString(array $options, string $key, ?string $default = null): ?string
{
    if (! isset($options[$key]) || ! is_string($options[$key]) || trim($options[$key]) === '') {
        return $default;
    }

    return trim($options[$key]);
}

/**
 * @param array<string, mixed> $options
 */
function hasOptionFlag(array $options, string $key): bool
{
    return array_key_exists($key, $options);
}

function projectRoot(): string
{
    return dirname(__DIR__);
}

function passDeploymentCheck(string $message): void
{
    fwrite(STDOUT, "PASS {$message}\n");
}

function dryRunDeploymentCheck(string $message): void
{
    fwrite(STDOUT, "DRY-RUN {$message}\n");
}

function deploymentEnvPath(): string
{
    $override = getenv('DEPLOY_ACCEPTANCE_ENV_FILE');

    if (is_string($override) && trim($override) !== '') {
        return $override;
    }

    return projectRoot() . DIRECTORY_SEPARATOR . '.env';
}

/**
 * @return array<string, string>
 */
function parseDotEnv(string $contents): array
{
    $values = [];
    $lines = preg_split('/\R/', $contents) ?: [];

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);

        if ($key === '') {
            continue;
        }

        $values[$key] = trim(trim($value), "\"'");
    }

    return $values;
}

/**
 * @param array<string, string> $env
 */
function envValue(array $env, string $key, string $default = ''): string
{
    return $env[$key] ?? $default;
}

/**
 * @param array<string, string> $env
 */
function assertProductionEnvValue(array $env, string $key, string $expected, string $message): void
{
    if (strtolower(envValue($env, $key)) !== strtolower($expected)) {
        throw new DeploymentAcceptanceFailure($message);
    }
}

/**
 * @param array<string, string> $env
 */
function assertProductionDatabaseCredentials(array $env): void
{
    if (
        strtolower(envValue($env, 'DB_CONNECTION')) === 'mysql'
        && strtolower(envValue($env, 'DB_USERNAME')) === 'root'
        && envValue($env, 'DB_PASSWORD') === 'root'
    ) {
        throw new DeploymentAcceptanceFailure('Default root database credentials are not allowed in production.');
    }
}

function checkDeploymentEnv(): void
{
    $envPath = deploymentEnvPath();

    if (! is_file($envPath)) {
        throw new DeploymentAcceptanceFailure('Missing .env file.');
    }

    $env = file_get_contents($envPath);

    if ($env === false) {
        throw new DeploymentAcceptanceFailure('Unable to read .env file.');
    }

    if (preg_match('/^APP_KEY=(.+)$/m', $env, $matches) !== 1 || trim($matches[1], "\"' \t") === '') {
        throw new DeploymentAcceptanceFailure('APP_KEY is empty in .env.');
    }

    passDeploymentCheck('env APP_KEY present');

    $parsed = parseDotEnv($env);

    if (envValue($parsed, 'APP_ENV') === 'production') {
        assertProductionEnvValue($parsed, 'APP_DEBUG', 'false', 'APP_DEBUG must be false in production.');
        assertProductionEnvValue($parsed, 'SESSION_ENCRYPT', 'true', 'SESSION_ENCRYPT must be true in production.');
        assertProductionEnvValue($parsed, 'APP_LOCALE', 'zh_CN', 'APP_LOCALE must be zh_CN in production.');
        assertProductionDatabaseCredentials($parsed);

        if (envValue($parsed, 'APP_URL') === '' || envValue($parsed, 'APP_URL') === 'http://localhost') {
            throw new DeploymentAcceptanceFailure('APP_URL must be a production URL in production.');
        }

        passDeploymentCheck('env production hardening');
    }
}

/**
 * @param list<string> $arguments
 * @return list<string>
 */
function phpCommand(string $phpBinary, array $arguments): array
{
    if (str_ends_with(strtolower($phpBinary), '.php')) {
        return array_merge([PHP_BINARY, $phpBinary], $arguments);
    }

    return array_merge([$phpBinary], $arguments);
}

/**
 * @param list<string> $command
 */
function runDeploymentChild(array $command, string $label, float $timeout): void
{
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, projectRoot());

    if (! is_resource($process)) {
        throw new DeploymentAcceptanceFailure("Unable to start {$label}.");
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + max(30.0, $timeout * 5);

    do {
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';

        $status = proc_get_status($process);

        if (! ($status['running'] ?? false)) {
            break;
        }

        if (microtime(true) >= $deadline) {
            proc_terminate($process);
            closeDeploymentPipes($pipes);
            proc_close($process);
            throw new DeploymentAcceptanceFailure("{$label} timed out.");
        }

        usleep(50_000);
    } while (true);

    $stdout .= stream_get_contents($pipes[1]) ?: '';
    $stderr .= stream_get_contents($pipes[2]) ?: '';
    closeDeploymentPipes($pipes);

    $exitCode = proc_close($process);

    if ($exitCode === -1 && isset($status['exitcode']) && is_int($status['exitcode'])) {
        $exitCode = $status['exitcode'];
    }

    if ($exitCode !== 0) {
        $output = trim($stdout . ($stderr === '' ? '' : PHP_EOL . $stderr));
        $message = "{$label} failed with exit code {$exitCode}.";

        if ($output !== '') {
            $message .= PHP_EOL . $output;
        }

        throw new DeploymentAcceptanceFailure($message);
    }
}

/**
 * @param array<int, resource> $pipes
 */
function closeDeploymentPipes(array $pipes): void
{
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
}

/**
 * @param list<string> $command
 */
function commandPreview(array $command): string
{
    $root = str_replace('\\', '/', projectRoot()) . '/';
    $parts = [];

    foreach ($command as $part) {
        $normalized = str_replace('\\', '/', $part);

        if (str_starts_with($normalized, $root)) {
            $part = substr($normalized, strlen($root));
        }

        if (preg_match('/^--(?:admin-password|portal-password|password)=/i', $part) === 1) {
            $part = preg_replace('/=.*/', '=***', $part) ?? $part;
        }

        $parts[] = $part;
    }

    return implode(' ', $parts);
}

/**
 * @return list<array{label:string,command:list<string>}>
 */
function deploymentCommands(array $options): array
{
    $php = $options['php_binary'];
    $root = projectRoot();
    $commands = [];

    if (! $options['skip_migrate']) {
        $commands[] = [
            'label' => 'artisan migrate --force',
            'command' => phpCommand($php, ['artisan', 'migrate', '--force']),
        ];
    }

    if (! $options['skip_menu_sync']) {
        $commands[] = [
            'label' => 'artisan user:ops-menu:sync',
            'command' => phpCommand($php, ['artisan', 'user:ops-menu:sync']),
        ];
        $commands[] = [
            'label' => 'artisan system:module-menu:sync',
            'command' => phpCommand($php, ['artisan', 'system:module-menu:sync']),
        ];
    }

    if (! $options['skip_portal']) {
        $portalArguments = [
            $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'user-portal-smoke.php',
            '--base-url=' . $options['base_url'],
            '--password=' . $options['portal_password'],
            '--timeout=' . (string) $options['timeout'],
        ];

        if ($options['portal_email'] !== null) {
            $portalArguments[] = '--email=' . $options['portal_email'];
        }

        $commands[] = [
            'label' => 'user portal smoke',
            'command' => phpCommand($php, $portalArguments),
        ];
    }

    if (! $options['skip_admin']) {
        $commands[] = [
            'label' => 'user admin smoke',
            'command' => phpCommand($php, [
                $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'user-admin-smoke.php',
                '--base-url=' . $options['base_url'],
                '--admin-prefix=' . $options['admin_prefix'],
                '--username=' . $options['admin_username'],
                '--password=' . $options['admin_password'],
                '--timeout=' . (string) $options['timeout'],
            ]),
        ];
    }

    return $commands;
}

function runDeploymentAcceptance(): void
{
    $options = parseDeploymentOptions();

    if (! $options['skip_env']) {
        if ($options['dry_run']) {
            dryRunDeploymentCheck('env APP_KEY present');
        } else {
            checkDeploymentEnv();
        }
    }

    foreach (deploymentCommands($options) as $entry) {
        if ($options['dry_run']) {
            dryRunDeploymentCheck($entry['label']);
            dryRunDeploymentCheck(commandPreview($entry['command']));
            continue;
        }

        runDeploymentChild($entry['command'], $entry['label'], $options['timeout']);
        passDeploymentCheck($entry['label']);
    }

    fwrite(STDOUT, $options['dry_run'] ? "OK deployment acceptance dry run passed\n" : "OK deployment acceptance passed\n");
}

try {
    runDeploymentAcceptance();
    exit(0);
} catch (DeploymentAcceptanceFailure $exception) {
    fwrite(STDERR, "FAIL deployment acceptance failed\n{$exception->getMessage()}\n");
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, "FAIL deployment acceptance failed\n{$exception->getMessage()}\n");
    exit(1);
}
