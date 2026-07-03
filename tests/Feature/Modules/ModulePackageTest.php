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
        $this->deletePath($this->fixtureRoot);
        mkdir($this->fixtureRoot, 0777, true);
        Config::set('modules.path', $this->fixtureRoot.DIRECTORY_SEPARATOR.'modules');
        mkdir(Config::string('modules.path'), 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deletePath($this->fixtureRoot);
        $this->deletePath(storage_path('modules/tmp'));
        $this->deletePath(storage_path('modules/backups'));
        $this->deletePath(storage_path('logs/module-package-target'));

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
        $link = storage_path('modules/tmp/link');
        $this->deletePath(dirname($link));
        mkdir($real, 0777, true);
        mkdir(dirname($link), 0777, true);
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
        $normalizedBackup = str_replace('\\', '/', $backup);

        $this->assertStringContainsString('blog_module', $backup);
        $this->assertMatchesRegularExpression('#/blog_module/\d{14}-1\.0\.0_\.\._\.\._beta-[^/]+$#', $normalizedBackup);
        $this->assertFileExists($backup.DIRECTORY_SEPARATOR.'module.txt');
    }

    public function test_backup_sanitizes_exact_dot_segments_to_safe_names(): void
    {
        $current = $this->fixtureRoot.DIRECTORY_SEPARATOR.'dot-source';
        mkdir($current, 0777, true);
        file_put_contents($current.DIRECTORY_SEPARATOR.'module.txt', 'ok');

        $backup = app(ModuleFileStore::class)->backup($current, '..', '..');
        $normalizedBackup = str_replace('\\', '/', $backup);

        $this->assertStringStartsWith(str_replace('\\', '/', storage_path('modules/backups')).'/_', $normalizedBackup);
        $this->assertMatchesRegularExpression('#/_/\d{14}-_-[^/]+$#', $normalizedBackup);
        $this->assertFileExists($backup.DIRECTORY_SEPARATOR.'module.txt');
    }

    public function test_backup_uses_unique_suffix_for_same_module_and_version(): void
    {
        $current = $this->fixtureRoot.DIRECTORY_SEPARATOR.'same-second-source';
        mkdir($current, 0777, true);
        file_put_contents($current.DIRECTORY_SEPARATOR.'module.txt', 'ok');

        $store = app(ModuleFileStore::class);
        $first = $store->backup($current, 'blog', '1.0.0');
        $second = $store->backup($current, 'blog', '1.0.0');

        $this->assertNotSame($first, $second);
        $this->assertFileExists($first.DIRECTORY_SEPARATOR.'module.txt');
        $this->assertFileExists($second.DIRECTORY_SEPARATOR.'module.txt');
    }

    public function test_replace_rejects_target_under_non_module_storage_path(): void
    {
        $target = storage_path('logs/module-package-target');
        $source = $this->fixtureRoot.DIRECTORY_SEPARATOR.'source';

        $this->deletePath($target);
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
            $this->deletePath($target);
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

    public function test_replace_rejects_target_under_symlink_ancestor(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symlinks are not available.');
        }

        $modulesRoot = Config::string('modules.path');
        $real = $modulesRoot.DIRECTORY_SEPARATOR.'real-parent';
        $link = $modulesRoot.DIRECTORY_SEPARATOR.'linked-parent';
        $target = $link.DIRECTORY_SEPARATOR.'child';
        $source = $this->fixtureRoot.DIRECTORY_SEPARATOR.'source';

        mkdir($real.DIRECTORY_SEPARATOR.'child', 0777, true);
        mkdir($source, 0777, true);
        file_put_contents($real.DIRECTORY_SEPARATOR.'child'.DIRECTORY_SEPARATOR.'keep.txt', 'keep');
        file_put_contents($source.DIRECTORY_SEPARATOR.'new.txt', 'new');

        set_error_handler(static fn () => true);
        $linked = symlink($real, $link);
        restore_error_handler();

        if ($linked !== true) {
            $this->markTestSkipped('Symlink creation is not available in this environment.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Replacement target contains symlink ancestor');

        try {
            app(ModuleFileStore::class)->replace($target, $source);
        } finally {
            $this->assertFileExists($real.DIRECTORY_SEPARATOR.'child'.DIRECTORY_SEPARATOR.'keep.txt');
            $this->assertFileDoesNotExist($real.DIRECTORY_SEPARATOR.'child'.DIRECTORY_SEPARATOR.'new.txt');
        }
    }

    public function test_replace_rejects_normalized_target_under_symlink_ancestor(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symlinks are not available.');
        }

        $modulesRoot = Config::string('modules.path');
        $modules2 = dirname($modulesRoot).DIRECTORY_SEPARATOR.'modules2';
        $real = $modulesRoot.DIRECTORY_SEPARATOR.'real-parent';
        $link = $modulesRoot.DIRECTORY_SEPARATOR.'linked-parent';
        $target = $modules2.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.'linked-parent'.DIRECTORY_SEPARATOR.'child';
        $source = $this->fixtureRoot.DIRECTORY_SEPARATOR.'normalized-source';

        mkdir($modules2, 0777, true);
        mkdir($real.DIRECTORY_SEPARATOR.'child', 0777, true);
        mkdir($source, 0777, true);
        file_put_contents($real.DIRECTORY_SEPARATOR.'child'.DIRECTORY_SEPARATOR.'keep.txt', 'keep');
        file_put_contents($source.DIRECTORY_SEPARATOR.'new.txt', 'new');

        set_error_handler(static fn () => true);
        $linked = symlink($real, $link);
        restore_error_handler();

        if ($linked !== true) {
            $this->markTestSkipped('Symlink creation is not available in this environment.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Replacement target contains dot segments');

        try {
            app(ModuleFileStore::class)->replace($target, $source);
        } finally {
            $this->assertFileExists($real.DIRECTORY_SEPARATOR.'child'.DIRECTORY_SEPARATOR.'keep.txt');
            $this->assertFileDoesNotExist($real.DIRECTORY_SEPARATOR.'child'.DIRECTORY_SEPARATOR.'new.txt');
            $this->deletePath($modules2);
        }
    }

    public function test_replace_rejects_raw_target_with_symlink_before_dot_segments(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symlinks are not available.');
        }

        $modulesRoot = Config::string('modules.path');
        $outside = $this->fixtureRoot.DIRECTORY_SEPARATOR.'outside';
        $link = $modulesRoot.DIRECTORY_SEPARATOR.'link-to-outside';
        $target = $link.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'victim';
        $source = $this->fixtureRoot.DIRECTORY_SEPARATOR.'outside-source';

        mkdir($outside.DIRECTORY_SEPARATOR.'victim', 0777, true);
        mkdir($source, 0777, true);
        file_put_contents($outside.DIRECTORY_SEPARATOR.'victim'.DIRECTORY_SEPARATOR.'keep.txt', 'keep');
        file_put_contents($source.DIRECTORY_SEPARATOR.'new.txt', 'new');

        set_error_handler(static fn () => true);
        $linked = symlink($outside, $link);
        restore_error_handler();

        if ($linked !== true) {
            $this->markTestSkipped('Symlink creation is not available in this environment.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Replacement target contains dot segments');

        try {
            app(ModuleFileStore::class)->replace($target, $source);
        } finally {
            $this->assertFileExists($outside.DIRECTORY_SEPARATOR.'victim'.DIRECTORY_SEPARATOR.'keep.txt');
            $this->assertFileDoesNotExist($outside.DIRECTORY_SEPARATOR.'victim'.DIRECTORY_SEPARATOR.'new.txt');
        }
    }

    public function test_zip_extractor_rejects_symlink_entries_when_attributes_indicate_symlink(): void
    {
        if (! class_exists(\ZipArchive::class) || ! method_exists(\ZipArchive::class, 'setExternalAttributesName')) {
            $this->markTestSkipped('Zip symlink attributes are not available.');
        }

        $zipPath = $this->fixtureRoot.DIRECTORY_SEPARATOR.'symlink-entry.zip';
        $zip = new \ZipArchive();
        $result = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $this->assertTrue($result === true, 'Failed to create zip fixture.');
        $zip->addFromString('module-link', 'target');

        if (! $zip->setExternalAttributesName('module-link', \ZipArchive::OPSYS_UNIX, 0120000 << 16)) {
            $zip->close();
            $this->markTestSkipped('Unable to assign zip symlink attributes.');
        }

        $zip->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsafe zip entry');

        app(ModuleZipExtractor::class)->extract($zipPath);
    }

    public function test_backup_rejects_symlink_to_file_entries(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symlinks are not available.');
        }

        $current = $this->fixtureRoot.DIRECTORY_SEPARATOR.'symlink-file-source';
        mkdir($current, 0777, true);
        file_put_contents($current.DIRECTORY_SEPARATOR.'real.txt', 'ok');

        set_error_handler(static fn () => true);
        $linked = symlink($current.DIRECTORY_SEPARATOR.'real.txt', $current.DIRECTORY_SEPARATOR.'alias.txt');
        restore_error_handler();

        if ($linked !== true) {
            $this->markTestSkipped('Symlink creation is not available in this environment.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to copy symlink');

        app(ModuleFileStore::class)->backup($current, 'blog', '1.0.0');
    }

    public function test_failed_backup_removes_partial_backup_directory(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symlinks are not available.');
        }

        $current = $this->fixtureRoot.DIRECTORY_SEPARATOR.'partial-backup-source';
        mkdir($current, 0777, true);
        file_put_contents($current.DIRECTORY_SEPARATOR.'first.txt', 'copied-before-failure');
        file_put_contents($current.DIRECTORY_SEPARATOR.'real.txt', 'ok');

        set_error_handler(static fn () => true);
        $linked = symlink($current.DIRECTORY_SEPARATOR.'real.txt', $current.DIRECTORY_SEPARATOR.'alias.txt');
        restore_error_handler();

        if ($linked !== true) {
            $this->markTestSkipped('Symlink creation is not available in this environment.');
        }

        try {
            app(ModuleFileStore::class)->backup($current, 'blog', '1.0.0');
            $this->fail('Expected backup to reject symlink entry.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Refusing to copy symlink', $exception->getMessage());
        }

        $backupRoot = storage_path('modules/backups/blog');
        $entries = is_dir($backupRoot) ? array_values(array_filter(
            scandir($backupRoot) ?: [],
            static fn (string $entry): bool => $entry !== '.' && $entry !== '..'
        )) : [];

        $this->assertSame([], $entries);
    }

    public function test_replace_rejects_source_and_target_containment(): void
    {
        $source = Config::string('modules.path');
        $target = $source.DIRECTORY_SEPARATOR.'contained-target';

        mkdir($target, 0777, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Replacement target must not contain or be contained by source');

        app(ModuleFileStore::class)->replace($target, $source);
    }

    public function test_public_delete_directory_rejects_safe_root_itself(): void
    {
        $root = storage_path('modules/tmp');
        mkdir($root, 0777, true);
        file_put_contents($root.DIRECTORY_SEPARATOR.'keep.txt', 'keep');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Delete path cannot be a safe root');

        try {
            app(ModuleFileStore::class)->deleteDirectory($root);
        } finally {
            $this->assertDirectoryExists($root);
            $this->assertFileExists($root.DIRECTORY_SEPARATOR.'keep.txt');
        }
    }

    public function test_public_delete_directory_rejects_non_safe_root(): void
    {
        $outside = $this->fixtureRoot.DIRECTORY_SEPARATOR.'unsafe-delete';
        mkdir($outside, 0777, true);
        file_put_contents($outside.DIRECTORY_SEPARATOR.'keep.txt', 'keep');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Delete path is outside allowed roots');

        try {
            app(ModuleFileStore::class)->deleteDirectory($outside);
        } finally {
            $this->assertFileExists($outside.DIRECTORY_SEPARATOR.'keep.txt');
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

    private function deletePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path) || @rmdir($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->deletePath($path.DIRECTORY_SEPARATOR.$entry);
        }

        @rmdir($path);
    }
}
