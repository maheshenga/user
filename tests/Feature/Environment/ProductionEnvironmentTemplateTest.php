<?php

namespace Tests\Feature\Environment;

use Tests\TestCase;

class ProductionEnvironmentTemplateTest extends TestCase
{
    public function test_production_environment_example_uses_safe_defaults(): void
    {
        $env = file_get_contents(base_path('.env.production.example'));

        $this->assertIsString($env);
        $this->assertStringContainsString('APP_ENV=production', $env);
        $this->assertStringContainsString('APP_DEBUG=false', $env);
        $this->assertStringContainsString('APP_LOCALE=zh_CN', $env);
        $this->assertStringContainsString('APP_FALLBACK_LOCALE=zh_CN', $env);
        $this->assertStringContainsString('APP_FAKER_LOCALE=zh_CN', $env);
        $this->assertStringContainsString('SESSION_ENCRYPT=true', $env);
        $this->assertStringContainsString('EASYADMIN.CAPTCHA=true', $env);
        $this->assertStringContainsString('EASYADMIN.IS_CSRF=true', $env);
        $this->assertStringContainsString('EASYADMIN.RATE_LIMITING_STATUS=true', $env);
        $this->assertStringNotContainsString('APP_KEY=base64:', $env);
    }

    public function test_local_environment_example_defaults_to_chinese_locale(): void
    {
        $env = file_get_contents(base_path('.env.example'));

        $this->assertIsString($env);
        $this->assertStringContainsString('APP_LOCALE=zh_CN', $env);
        $this->assertStringContainsString('APP_FALLBACK_LOCALE=zh_CN', $env);
        $this->assertStringContainsString('APP_FAKER_LOCALE=zh_CN', $env);
    }
}
