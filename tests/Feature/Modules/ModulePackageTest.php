<?php

namespace Tests\Feature\Modules;

use App\Modules\ModuleFileStore;
use App\Modules\ModuleZipExtractor;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

class ModulePackageTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureRoot = storage_path('framework/testing-module-packages');
        app(ModuleFileStore::class)->deleteDirectory($this->fixtureRoot);
        mkdir($this->fixtureRoot, 0777, true);
        Config::set('modules.path', $this->fixtureRoot.DIRECTORY_SEPARATOR.'modules');
    }

    protected function tearDown(): void
    {
        $store = app(ModuleFileStore::class);
        $store->deleteDirectory($this->fixtureRoot);
        $store->deleteDirectory(storage_path('modules/tmp'));
        $store->deleteDirectory(storage_path('modules/backups'));

        parent::tearDown();
    }

    public function test_zip_extractor_rejects_path_traversal_entries(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        $zipPath = $this->fixtureRoot.DIRECTORY_SEPARATOR.'unsafe.zip';
        $this->createZip($zipPath, [
            '../escape.txt' => 'nope',
            'module.json' => $this->moduleManifest('unsafe'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsafe zip entry');

        app(ModuleZipExtractor::class)->extract($zipPath);
    }

    public function test_zip_extractor_returns_module_root_for_single_nested_directory(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        $zipPath = $this->fixtureRoot.DIRECTORY_SEPARATOR.'blog.zip';
        $this->createZip($zipPath, [
            'Blog/module.json' => $this->moduleManifest('blog'),
        ]);

        $root = app(ModuleZipExtractor::class)->extract($zipPath);

        $this->assertStringEndsWith(DIRECTORY_SEPARATOR.'Blog', $root);
        $this->assertFileExists($root.DIRECTORY_SEPARATOR.'module.json');
    }

    public function test_file_store_backs_up_and_replaces_module_directory(): void
    {
        $current = Config::string('modules.path').DIRECTORY_SEPARATOR.'current';
        $next = $this->fixtureRoot.DIRECTORY_SEPARATOR.'next';

        mkdir($current, 0777, true);
        mkdir($next, 0777, true);
        file_put_contents($current.DIRECTORY_SEPARATOR.'old.txt', 'old');
        file_put_contents($next.DIRECTORY_SEPARATOR.'new.txt', 'new');

        $store = app(ModuleFileStore::class);
        $backup = $store->backup($current, 'blog', '1.0.0');

        $store->replace($current, $next);

        $this->assertFileExists($backup.DIRECTORY_SEPARATOR.'old.txt');
        $this->assertFileExists($current.DIRECTORY_SEPARATOR.'new.txt');
        $this->assertFileDoesNotExist($current.DIRECTORY_SEPARATOR.'old.txt');
    }

    public function test_delete_directory_on_symlink_root_unlinks_symlink_only(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symlinks are not available.');
        }

        $real = $this->fixtureRoot.DIRECTORY_SEPARATOR.'real';
        $link = $this->fixtureRoot.DIRECTORY_SEPARATOR.'link';
        mkdir($real, 0777, true);
        file_put_contents($real.DIRECTORY_SEPARATOR.'keep.txt', 'keep');

        set_error_handler(static fn () => true);
        $linked = symlink($real, $link);
        restore_error_handler();

        if ($linked !== true) {
            $this->markTestSkipped('Symlink creation is not available in this environment.');
        }

        app(ModuleFileStore::class)->deleteDirectory($link);

        $this->assertFalse(file_exists($link));
        $this->assertFileExists($real.DIRECTORY_SEPARATOR.'keep.txt');
    }

    public function test_backup_sanitizes_unsafe_version_path_segment(): void
    {
        $current = $this->fixtureRoot.DIRECTORY_SEPARATOR.'source';
        mkdir($current, 0777, true);
        file_put_contents($current.DIRECTORY_SEPARATOR.'module.txt', 'ok');

        $backup = app(ModuleFileStore::class)->backup($current, 'blog/module', '1.0.0/../../beta');

        $this->assertStringContainsString('blog_module', $backup);
        $this->assertStringContainsString('1.0.0_.._.._beta-', $backup);
        $this->assertFileExists($backup.DIRECTORY_SEPARATOR.'module.txt');
    }

    public function test_replace_rejects_target_under_non_module_storage_path(): void
    {
        $target = storage_path('logs/module-package-target');
        $source = $this->fixtureRoot.DIRECTORY_SEPARATOR.'source';

        app(ModuleFileStore::class)->deleteDirectory($target);
        mkdir($target, 0777, true);
        mkdir($source, 0777, true);
        file_put_contents($target.DIRECTORY_SEPARATOR.'keep.txt', 'keep');
        file_put_contents($source.DIRECTORY_SEPARATOR.'new.txt', 'new');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Replacement target is outside allowed roots');

        try {
            app(ModuleFileStore::class)->replace($target, $source);
        } finally {
            $this->assertFileExists($target.DIRECTORY_SEPARATOR.'keep.txt');
            $this->assertFileDoesNotExist($target.DIRECTORY_SEPARATOR.'new.txt');
            app(ModuleFileStore::class)->deleteDirectory($target);
        }
    }

    public function test_zip_extraction_failure_removes_temp_directory(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        $zipPath = $this->fixtureRoot.DIRECTORY_SEPARATOR.'bad.zip';
        $this->createZip($zipPath, [
            '../escape.txt' => 'nope',
            'module.json' => $this->moduleManifest('unsafe'),
        ]);

        $before = $this->moduleTmpDirectories();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsafe zip entry');

        try {
            app(ModuleZipExtractor::class)->extract($zipPath);
        } finally {
            $this->assertSame($before, $this->moduleTmpDirectories());
        }
    }

    private function createZip(string $zipPath, array $entries): void
    {
        $zip = new \ZipArchive();
        $result = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $this->assertTrue($result === true, 'Failed to create zip fixture.');

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();
    }

    private function moduleManifest(string $name): string
    {
        return json_encode([
            'schema_version' => '1.0',
            'name' => $name,
            'title' => ucfirst($name),
            'vendor' => 'easyadmin8',
            'version' => '1.0.0',
            'type' => 'private',
            'core_version' => '^8.0',
            'namespace' => 'Modules\\'.ucfirst($name),
            'admin_prefix' => $name,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<int, string>
     */
    private function moduleTmpDirectories(): array
    {
        $path = storage_path('modules/tmp');

        if (! is_dir($path)) {
            return [];
        }

        return array_values(array_filter(
            scandir($path) ?: [],
            static fn (string $entry): bool => $entry !== '.' && $entry !== '..'
        ));
    }
}
