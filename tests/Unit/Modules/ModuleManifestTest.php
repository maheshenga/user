<?php

namespace Tests\Unit\Modules;

use App\Modules\ModuleManifest;
use InvalidArgumentException;
use Tests\TestCase;

class ModuleManifestTest extends TestCase
{
    public function test_manifest_is_loaded_and_normalized(): void
    {
        $manifest = ModuleManifest::fromFile(base_path('tests/Fixtures/modules/Blog/module.json'));

        $this->assertSame('blog', $manifest->name());
        $this->assertSame('Blog Module', $manifest->title());
        $this->assertSame('Modules\\Blog', $manifest->namespace());
        $this->assertSame('blog', $manifest->adminPrefix());
        $this->assertStringEndsWith('tests/Fixtures/modules/Blog/src/Controllers', $manifest->controllersPath());
        $this->assertSame('blog/post/index', $manifest->menus()[0]['children'][0]['href']);
    }

    public function test_manifest_rejects_missing_required_fields(): void
    {
        $path = base_path('storage/framework/testing-invalid-module.json');
        file_put_contents($path, json_encode(['name' => 'broken'], JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('module.json missing required field: schema_version');

        ModuleManifest::fromFile($path);
    }
}
