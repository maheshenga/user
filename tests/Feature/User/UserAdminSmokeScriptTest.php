<?php

namespace Tests\Feature\User;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class UserAdminSmokeScriptTest extends TestCase
{
    private ?Process $serverProcess = null;

    protected function tearDown(): void
    {
        if ($this->serverProcess instanceof Process && $this->serverProcess->isRunning()) {
            $this->serverProcess->stop(0.5);
        }

        parent::tearDown();
    }

    public function test_user_admin_smoke_script_passes_against_fixture_server(): void
    {
        $baseUrl = $this->startFixtureServer();

        $process = $this->runSmokeScript($baseUrl);
        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('OK user admin smoke passed', $output);
        $this->assertStringContainsString('PASS GET /admin/ajax/initAdmin menu contains 用户运营', $output);
    }

    public function test_user_admin_smoke_script_accepts_space_separated_option_values(): void
    {
        $baseUrl = $this->startFixtureServer();

        $process = $this->runSmokeScript($baseUrl, useEqualsSyntax: false);
        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('OK user admin smoke passed', $output);
    }

    public function test_user_admin_smoke_script_fails_when_user_operations_menu_is_missing(): void
    {
        $baseUrl = $this->startFixtureServer('missing-menu');

        $process = $this->runSmokeScript($baseUrl);
        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertNotSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('FAIL user admin smoke failed', $output);
        $this->assertStringContainsString('Menu response missing 用户运营', $output);
    }

    public function test_user_admin_smoke_script_fails_when_dashboard_menu_link_is_missing(): void
    {
        $baseUrl = $this->startFixtureServer('missing-dashboard-link');

        $process = $this->runSmokeScript($baseUrl);
        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertNotSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('FAIL user admin smoke failed', $output);
        $this->assertStringContainsString('Menu response missing user/dashboard/index under 用户运营', $output);
    }

    public function test_user_admin_smoke_script_requires_dashboard_link_under_user_operations_menu(): void
    {
        $baseUrl = $this->startFixtureServer('dashboard-link-outside-user-ops');

        $process = $this->runSmokeScript($baseUrl);
        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertNotSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('FAIL user admin smoke failed', $output);
        $this->assertStringContainsString('Menu response missing user/dashboard/index under 用户运营', $output);
    }

    public function test_user_admin_smoke_script_fails_when_dashboard_metric_is_missing(): void
    {
        $baseUrl = $this->startFixtureServer('missing-dashboard-metric');

        $process = $this->runSmokeScript($baseUrl);
        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertNotSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('FAIL user admin smoke failed', $output);
        $this->assertStringContainsString('Dashboard metrics missing key: pending_payouts', $output);
    }

    public function test_user_admin_smoke_script_fails_when_admin_page_is_error_shell(): void
    {
        $baseUrl = $this->startFixtureServer('page-error');

        $process = $this->runSmokeScript($baseUrl);
        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertNotSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('FAIL user admin smoke failed', $output);
        $this->assertStringContainsString('looks like an EasyAdmin error page', $output);
    }

    public function test_user_admin_smoke_script_fails_when_admin_page_is_login_shell(): void
    {
        $baseUrl = $this->startFixtureServer('login-shell-page');

        $process = $this->runSmokeScript($baseUrl);
        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertNotSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('FAIL user admin smoke failed', $output);
        $this->assertStringContainsString('looks like a login page', $output);
    }

    private function startFixtureServer(?string $mode = null): string
    {
        $port = $this->getFreePort();
        $host = '127.0.0.1';
        $baseUrl = "http://{$host}:{$port}";
        $router = base_path('tests/Fixtures/user-admin-smoke-router.php');

        $environment = [];

        if ($mode !== null) {
            $environment['SMOKE_FIXTURE_MODE'] = $mode;
        }

        $this->serverProcess = new Process([
            PHP_BINARY,
            '-S',
            "{$host}:{$port}",
            $router,
        ], base_path(), $environment);
        $this->serverProcess->setTimeout(null);
        $this->serverProcess->start();

        $deadline = microtime(true) + 5;

        do {
            if (! $this->serverProcess->isRunning()) {
                $this->fail('Fixture server stopped early: ' . $this->serverProcess->getErrorOutput());
            }

            $context = stream_context_create([
                'http' => [
                    'ignore_errors' => true,
                    'timeout' => 0.2,
                ],
            ]);

            $response = @file_get_contents($baseUrl . '/admin/login', false, $context);

            if (is_string($response) && str_contains($response, 'fixture-admin-token')) {
                return $baseUrl;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        $this->fail('Fixture server did not become ready: ' . $this->serverProcess->getErrorOutput());
    }

    private function runSmokeScript(string $baseUrl, bool $useEqualsSyntax = true): Process
    {
        $arguments = [PHP_BINARY, base_path('scripts/user-admin-smoke.php')];

        if ($useEqualsSyntax) {
            array_push(
                $arguments,
                "--base-url={$baseUrl}",
                '--admin-prefix=admin',
                '--username=admin',
                '--password=123456',
                '--timeout=5'
            );
        } else {
            array_push(
                $arguments,
                '--base-url',
                $baseUrl,
                '--admin-prefix',
                'admin',
                '--username',
                'admin',
                '--password',
                '123456',
                '--timeout',
                '5'
            );
        }

        $process = new Process($arguments, base_path());
        $process->setTimeout(10);
        $process->run();

        return $process;
    }

    private function getFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            $this->fail("Unable to allocate a fixture server port: {$errstr} ({$errno})");
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if (! is_string($name) || ! str_contains($name, ':')) {
            $this->fail('Unable to determine fixture server port.');
        }

        return (int) substr(strrchr($name, ':'), 1);
    }
}
