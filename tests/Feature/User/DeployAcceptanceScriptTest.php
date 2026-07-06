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
            '--admin-prefix=staff',
            '--admin-username=root',
            '--admin-password=topsecret',
            '--portal-email=smoke@example.test',
            '--portal-password=secret123',
            '--timeout=3',
        ], ['DEPLOY_ACCEPTANCE_RECORD_FILE' => $recordFile]);

        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('DRY-RUN env APP_KEY present', $output);
        $this->assertStringContainsString('artisan migrate --force', $output);
        $this->assertStringContainsString('DRY-RUN user portal smoke', $output);
        $this->assertStringContainsString('DRY-RUN user admin smoke', $output);
        $this->assertStringContainsString('scripts/user-portal-smoke.php', str_replace('\\', '/', $output));
        $this->assertStringContainsString('--email=smoke@example.test', $output);
        $this->assertStringContainsString('--admin-prefix=staff', $output);
        $this->assertStringContainsString('--username=root', $output);
        $this->assertStringContainsString('--timeout=3', $output);
        $this->assertStringContainsString('--password=***', $output);
        $this->assertStringNotContainsString('secret123', $output);
        $this->assertStringNotContainsString('topsecret', $output);
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

    public function test_deploy_acceptance_surfaces_child_command_failure_with_observed_exit_code(): void
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

        $slashOnlyUrl = $this->runDeployAcceptance([
            '--base-url=/',
            '--dry-run',
        ]);

        $slashOnlyOutput = $slashOnlyUrl->getOutput() . $slashOnlyUrl->getErrorOutput();

        $this->assertNotSame(0, $slashOnlyUrl->getExitCode(), $slashOnlyOutput);
        $this->assertStringContainsString('Missing required option: --base-url', $slashOnlyOutput);

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
