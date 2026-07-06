# Deployment Acceptance Automation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a one-command deployment acceptance script that validates env readiness, optional migrations/menu sync, and existing user portal/admin smoke checks.

**Architecture:** Implement a standalone PHP CLI orchestrator in `scripts/deploy-acceptance.php`. Keep Laravel business code untouched; call artisan and existing smoke scripts as subprocesses and expose a Composer alias.

**Tech Stack:** PHP 8.3 CLI, Symfony Process in PHPUnit tests, existing Laravel artisan commands, existing smoke scripts.

---

## File Structure

- Create: `scripts/deploy-acceptance.php`
  - Standalone CLI script.
  - Parses options, validates required inputs, runs local checks and child commands.
  - Supports `--dry-run`, skip flags, and `--php-binary` for tests.
- Create: `tests/Feature/User/DeployAcceptanceScriptTest.php`
  - Feature tests that execute the script as a subprocess.
  - Uses dry-run and a fixture PHP runner to avoid real DB/network mutation.
- Create: `tests/Fixtures/deploy-acceptance-php-runner.php`
  - Test-only fake PHP executable.
  - Records child command arguments to JSON-lines and can simulate failure.
- Modify: `composer.json`
  - Add `deploy:acceptance` script next to `smoke:user-portal` and `smoke:user-admin`.

## Task 1: Add RED tests for deployment acceptance CLI

**Files:**
- Create: `tests/Feature/User/DeployAcceptanceScriptTest.php`
- Create: `tests/Fixtures/deploy-acceptance-php-runner.php`

- [ ] **Step 1: Write the fixture runner**

Create `tests/Fixtures/deploy-acceptance-php-runner.php`:

```php
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
```

- [ ] **Step 2: Write failing tests**

Create `tests/Feature/User/DeployAcceptanceScriptTest.php`:

```php
<?php

namespace Tests\Feature\User;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeployAcceptanceScriptTest extends TestCase
{
    public function test_deploy_acceptance_dry_run_lists_planned_checks_without_running_children(): void
    {
        $recordFile = $this->recordFile();

        $process = $this->runDeployAcceptance([
            '--base-url=http://127.0.0.1:8000',
            '--dry-run',
            '--php-binary=' . base_path('tests/Fixtures/deploy-acceptance-php-runner.php'),
        ], ['DEPLOY_ACCEPTANCE_RECORD_FILE' => $recordFile]);

        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('DRY-RUN env APP_KEY present', $output);
        $this->assertStringContainsString('DRY-RUN php artisan migrate --force', $output);
        $this->assertStringContainsString('DRY-RUN user portal smoke', $output);
        $this->assertStringContainsString('DRY-RUN user admin smoke', $output);
        $this->assertStringContainsString('OK deployment acceptance dry run passed', $output);
        $this->assertFileDoesNotExist($recordFile);
    }

    public function test_deploy_acceptance_runs_artisan_and_existing_smoke_scripts(): void
    {
        $recordFile = $this->recordFile();

        $process = $this->runDeployAcceptance([
            '--base-url=http://127.0.0.1:8000',
            '--skip-env',
            '--php-binary=' . base_path('tests/Fixtures/deploy-acceptance-php-runner.php'),
            '--admin-prefix=staff',
            '--admin-username=root',
            '--admin-password=topsecret',
            '--portal-email=smoke@example.test',
            '--portal-password=secret123',
            '--timeout=3',
        ], ['DEPLOY_ACCEPTANCE_RECORD_FILE' => $recordFile]);

        $output = $process->getOutput() . $process->getErrorOutput();
        $commands = $this->recordedCommands($recordFile);

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('PASS artisan migrate --force', $output);
        $this->assertStringContainsString('PASS artisan user:ops-menu:sync', $output);
        $this->assertStringContainsString('PASS user portal smoke', $output);
        $this->assertStringContainsString('PASS user admin smoke', $output);
        $this->assertStringContainsString('OK deployment acceptance passed', $output);

        $this->assertSame('artisan', $commands[0][0] ?? null);
        $this->assertSame(['migrate', '--force'], array_slice($commands[0], 1));
        $this->assertSame('artisan', $commands[1][0] ?? null);
        $this->assertSame(['user:ops-menu:sync'], array_slice($commands[1], 1));
        $this->assertStringEndsWith('scripts/user-portal-smoke.php', str_replace('\\', '/', $commands[2][0] ?? ''));
        $this->assertContains('--base-url=http://127.0.0.1:8000', $commands[2]);
        $this->assertContains('--email=smoke@example.test', $commands[2]);
        $this->assertContains('--password=secret123', $commands[2]);
        $this->assertStringEndsWith('scripts/user-admin-smoke.php', str_replace('\\', '/', $commands[3][0] ?? ''));
        $this->assertContains('--admin-prefix=staff', $commands[3]);
        $this->assertContains('--username=root', $commands[3]);
        $this->assertContains('--password=topsecret', $commands[3]);
    }

    public function test_deploy_acceptance_surfaces_child_command_failure(): void
    {
        $recordFile = $this->recordFile();

        $process = $this->runDeployAcceptance([
            '--base-url=http://127.0.0.1:8000',
            '--skip-env',
            '--php-binary=' . base_path('tests/Fixtures/deploy-acceptance-php-runner.php'),
        ], [
            'DEPLOY_ACCEPTANCE_RECORD_FILE' => $recordFile,
            'DEPLOY_ACCEPTANCE_FAIL_CONTAINS' => 'user-admin-smoke.php',
        ]);

        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertNotSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('FAIL deployment acceptance failed', $output);
        $this->assertStringContainsString('user admin smoke failed with exit code 17', $output);
        $this->assertStringContainsString('fixture failure for user-admin-smoke.php', $output);
    }

    public function test_deploy_acceptance_requires_base_url_when_smoke_checks_are_enabled(): void
    {
        $process = $this->runDeployAcceptance([
            '--skip-env',
            '--skip-migrate',
            '--skip-menu-sync',
        ]);

        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertNotSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('Missing required option: --base-url', $output);

        $skipped = $this->runDeployAcceptance([
            '--skip-env',
            '--skip-migrate',
            '--skip-menu-sync',
            '--skip-portal',
            '--skip-admin',
        ]);

        $this->assertSame(0, $skipped->getExitCode(), $skipped->getOutput() . $skipped->getErrorOutput());
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     */
    private function runDeployAcceptance(array $arguments, array $environment = []): Process
    {
        $process = new Process(array_merge([
            PHP_BINARY,
            base_path('scripts/deploy-acceptance.php'),
        ], $arguments), base_path(), $environment);
        $process->setTimeout(20);
        $process->run();

        return $process;
    }

    private function recordFile(): string
    {
        return sys_get_temp_dir() . '/deploy-acceptance-' . bin2hex(random_bytes(6)) . '.jsonl';
    }

    /**
     * @return list<list<string>>
     */
    private function recordedCommands(string $recordFile): array
    {
        $this->assertFileExists($recordFile);

        $commands = [];

        foreach (file($recordFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);
            $commands[] = $decoded;
        }

        return $commands;
    }
}
```

- [ ] **Step 3: Run the new test to verify RED**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\DeployAcceptanceScriptTest.php
```

Expected: FAIL because `scripts/deploy-acceptance.php` does not exist.

## Task 2: Implement deployment acceptance script

**Files:**
- Create: `scripts/deploy-acceptance.php`

- [ ] **Step 1: Add minimal implementation**

Create `scripts/deploy-acceptance.php` with:

```php
<?php

declare(strict_types=1);

final class DeploymentAcceptanceFailure extends RuntimeException
{
}

function optionValue(array $options, string $key, ?string $default = null): ?string
{
    if (! isset($options[$key]) || ! is_string($options[$key]) || trim($options[$key]) === '') {
        return $default;
    }

    return trim($options[$key]);
}

function hasFlag(array $options, string $key): bool
{
    return array_key_exists($key, $options);
}

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

    $skipPortal = hasFlag($options, 'skip-portal');
    $skipAdmin = hasFlag($options, 'skip-admin');
    $baseUrl = optionValue($options, 'base-url');

    if ($baseUrl === null && (! $skipPortal || ! $skipAdmin)) {
        throw new DeploymentAcceptanceFailure('Missing required option: --base-url');
    }

    $timeout = optionValue($options, 'timeout', '10');

    if (! is_numeric($timeout) || (float) $timeout <= 0) {
        throw new DeploymentAcceptanceFailure('Invalid --timeout value.');
    }

    return [
        'base_url' => $baseUrl === null ? null : rtrim($baseUrl, '/'),
        'admin_prefix' => trim(optionValue($options, 'admin-prefix', 'admin') ?? 'admin', " \t\n\r\0\x0B/"),
        'admin_username' => optionValue($options, 'admin-username', 'admin') ?? 'admin',
        'admin_password' => optionValue($options, 'admin-password', '123456') ?? '123456',
        'portal_email' => optionValue($options, 'portal-email'),
        'portal_password' => optionValue($options, 'portal-password', 'secret123') ?? 'secret123',
        'timeout' => (float) $timeout,
        'php_binary' => optionValue($options, 'php-binary', PHP_BINARY) ?? PHP_BINARY,
        'skip_env' => hasFlag($options, 'skip-env'),
        'skip_migrate' => hasFlag($options, 'skip-migrate'),
        'skip_menu_sync' => hasFlag($options, 'skip-menu-sync'),
        'skip_portal' => $skipPortal,
        'skip_admin' => $skipAdmin,
        'dry_run' => hasFlag($options, 'dry-run'),
    ];
}

function projectRoot(): string
{
    return dirname(__DIR__);
}

function pass(string $message): void
{
    fwrite(STDOUT, "PASS {$message}\n");
}

function dryRun(string $message): void
{
    fwrite(STDOUT, "DRY-RUN {$message}\n");
}

function runChild(array $command, string $label, float $timeout): void
{
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, projectRoot());

    if (! is_resource($process)) {
        throw new DeploymentAcceptanceFailure("Unable to start {$label}.");
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $output = trim((string) $stdout . ((string) $stderr === '' ? '' : PHP_EOL . (string) $stderr));

    if ($exitCode !== 0) {
        $message = "{$label} failed with exit code {$exitCode}.";

        if ($output !== '') {
            $message .= PHP_EOL . $output;
        }

        throw new DeploymentAcceptanceFailure($message);
    }
}

function checkEnv(): void
{
    $envPath = projectRoot() . DIRECTORY_SEPARATOR . '.env';

    if (! is_file($envPath)) {
        throw new DeploymentAcceptanceFailure('Missing .env file.');
    }

    $env = (string) file_get_contents($envPath);

    if (preg_match('/^APP_KEY=(.+)$/m', $env, $matches) !== 1 || trim($matches[1], "\"' \t") === '') {
        throw new DeploymentAcceptanceFailure('APP_KEY is empty in .env.');
    }

    pass('env APP_KEY present');
}

function commandPreview(array $command): string
{
    $root = str_replace('\\', '/', projectRoot()) . '/';
    $parts = [];

    foreach ($command as $part) {
        $normalized = str_replace('\\', '/', $part);
        $parts[] = str_starts_with($normalized, $root) ? substr($normalized, strlen($root)) : $part;
    }

    return implode(' ', $parts);
}

function runDeploymentAcceptance(): void
{
    $options = parseDeploymentOptions();
    $php = $options['php_binary'];
    $root = projectRoot();

    if (! $options['skip_env']) {
        if ($options['dry_run']) {
            dryRun('env APP_KEY present');
        } else {
            checkEnv();
        }
    }

    $commands = [];

    if (! $options['skip_migrate']) {
        $commands[] = [
            'label' => 'artisan migrate --force',
            'command' => [$php, 'artisan', 'migrate', '--force'],
        ];
    }

    if (! $options['skip_menu_sync']) {
        $commands[] = [
            'label' => 'artisan user:ops-menu:sync',
            'command' => [$php, 'artisan', 'user:ops-menu:sync'],
        ];
    }

    if (! $options['skip_portal']) {
        $portalCommand = [
            $php,
            $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'user-portal-smoke.php',
            '--base-url=' . $options['base_url'],
            '--password=' . $options['portal_password'],
            '--timeout=' . (string) $options['timeout'],
        ];

        if ($options['portal_email'] !== null) {
            $portalCommand[] = '--email=' . $options['portal_email'];
        }

        $commands[] = [
            'label' => 'user portal smoke',
            'command' => $portalCommand,
        ];
    }

    if (! $options['skip_admin']) {
        $commands[] = [
            'label' => 'user admin smoke',
            'command' => [
                $php,
                $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'user-admin-smoke.php',
                '--base-url=' . $options['base_url'],
                '--admin-prefix=' . $options['admin_prefix'],
                '--username=' . $options['admin_username'],
                '--password=' . $options['admin_password'],
                '--timeout=' . (string) $options['timeout'],
            ],
        ];
    }

    foreach ($commands as $entry) {
        if ($options['dry_run']) {
            dryRun($entry['label']);
            dryRun(commandPreview($entry['command']));
            continue;
        }

        runChild($entry['command'], $entry['label'], $options['timeout']);
        pass($entry['label']);
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
```

- [ ] **Step 2: Run the new test to verify GREEN**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\DeployAcceptanceScriptTest.php
```

Expected: PASS.

## Task 3: Add Composer alias and focused verification

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Add Composer script**

Modify `composer.json` scripts section:

```json
"test:sqlite": "@php scripts/phpunit-sqlite.php",
"smoke:user-portal": "@php scripts/user-portal-smoke.php",
"smoke:user-admin": "@php scripts/user-admin-smoke.php",
"deploy:acceptance": "@php scripts/deploy-acceptance.php",
```

- [ ] **Step 2: Validate Composer metadata**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar validate --no-check-publish
```

Expected: exit `0`.

- [ ] **Step 3: Run focused smoke script tests**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit tests\Feature\User\DeployAcceptanceScriptTest.php tests\Feature\User\UserPortalSmokeScriptTest.php tests\Feature\User\UserAdminSmokeScriptTest.php
```

Expected: PASS.

## Task 4: Final review, full verification, commit, push

**Files:**
- Review all P15 changes.

- [ ] **Step 1: Run syntax checks**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe -l scripts\deploy-acceptance.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests\Feature\User\DeployAcceptanceScriptTest.php
E:\code\user\.tools\php-8.3.32\php.exe -l tests\Fixtures\deploy-acceptance-php-runner.php
```

Expected: `No syntax errors detected` for all files.

- [ ] **Step 2: Run full SQLite suite**

Run:

```bash
E:\code\user\.tools\php-8.3.32\php.exe -d extension=pdo_sqlite -d extension=sqlite3 E:\code\user\.tools\composer.phar run test:sqlite
```

Expected: PASS.

- [ ] **Step 3: Request code review**

Dispatch a reviewer with:

```text
Description: P15 deployment acceptance automation.
Requirements: docs/superpowers/specs/2026-07-06-deployment-acceptance-design.md and this plan.
Base: commit before P15 implementation.
Head: current HEAD after P15 implementation.
```

Expected: no Critical or Important issues. Fix any Critical or Important issue before continuing.

- [ ] **Step 4: Commit and push**

Run:

```bash
git add docs/superpowers/specs/2026-07-06-deployment-acceptance-design.md docs/superpowers/plans/2026-07-06-deployment-acceptance-phase-15.md scripts/deploy-acceptance.php tests/Feature/User/DeployAcceptanceScriptTest.php tests/Fixtures/deploy-acceptance-php-runner.php composer.json
git commit -m "feat: add deployment acceptance automation"
git push origin main
```

Expected: push succeeds.

## Self-review

The plan covers every design requirement: CLI contract, dry-run, local readiness checks, artisan commands, smoke delegation, tests, Composer alias, review, and push. There are no placeholders. The planned script names and option names are consistent across docs, tests, and implementation.
