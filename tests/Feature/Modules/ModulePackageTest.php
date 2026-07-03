<?php

namespace Tests\Feature\Modules;

use App\Modules\ModuleFileStore;
use App\Modules\ModuleZipExtractor;
use RuntimeException;
use Tests\TestCase;

class ModulePackageTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureRoot = storage_path('framework/testing-module-packages');
        $this->deleteDirectory($this->fixtureRoot);
        mkdir($this->fixtureRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->fixtureRoot);
        $this->deleteDirectory(storage_path('modules/tmp'));
        $this->deleteDirectory(storage_path('modules/backups'));

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
        $current = $this->fixtureRoot.DIRECTORY_SEPARATOR.'current';
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

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($child)) {
                $this->deleteDirectory($child);
                continue;
            }

            @unlink($child);
        }

        @rmdir($path);
    }
}
