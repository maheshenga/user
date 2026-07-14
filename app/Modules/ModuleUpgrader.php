<?php

namespace App\Modules;

use App\Models\SystemModule;
use InvalidArgumentException;
use RuntimeException;

final class ModuleUpgrader
{
    public function __construct(
        private readonly ModuleRepository $repository,
        private readonly ModuleReleaseManager $releases,
    ) {}

    public function upgradeLocal(string $name, ?int $actorId = null): void
    {
        if (app()->environment('production')) {
            throw new InvalidArgumentException('生产环境禁止从可变本地目录直接升级模块，请上传 ZIP 制品并重新审核。');
        }

        $this->withModuleLock($name, function () use ($name, $actorId): void {
            $module = $this->installedModule($name);
            $manifest = ModuleManifest::fromFile(
                rtrim((string) $module->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'module.json'
            );
            $this->assertManifestName($manifest, $name);
            $this->assertUpgradeable((string) $module->status, (string) $module->version, $manifest);
            $this->releases->stageManifest($manifest, 'local', 'private', $actorId);
        });
    }

    public function upgradeZip(string $zipPath, ?string $expectedName = null, ?int $actorId = null): void
    {
        $this->releases->stageZip($zipPath, $expectedName, $actorId);
    }

    private function installedModule(string $name): SystemModule
    {
        $module = $this->repository->installed($name);
        if ($module === null) {
            throw new InvalidArgumentException("模块未安装：{$name}");
        }

        return $module;
    }

    private function assertManifestName(ModuleManifest $manifest, string $expectedName): void
    {
        if ($manifest->name() !== $expectedName) {
            throw new InvalidArgumentException("期望模块 [{$expectedName}]，实际为 [{$manifest->name()}]。");
        }
    }

    private function assertUpgradeable(string $status, string $currentVersion, ModuleManifest $manifest): void
    {
        if (! in_array($status, ['installed', 'enabled', 'disabled'], true)) {
            throw new InvalidArgumentException("模块 [{$manifest->name()}] 当前状态 [{$status}] 不允许升级。");
        }

        if (version_compare($manifest->version(), $currentVersion, '<=')) {
            throw new InvalidArgumentException(
                "模块 [{$manifest->name()}] 新版本 [{$manifest->version()}] 必须大于当前版本 [{$currentVersion}]。"
            );
        }
    }

    /**
     * @param  callable(): void  $operation
     */
    private function withModuleLock(string $module, callable $operation): void
    {
        $dir = storage_path('modules/locks');
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException("无法创建模块锁目录：{$dir}");
        }

        $path = $dir.DIRECTORY_SEPARATOR.$this->safeLockSegment($module).'.lock';
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException("无法打开模块锁：{$path}");
        }

        try {
            $deadline = microtime(true) + 2.0;
            do {
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    try {
                        $operation();
                    } finally {
                        flock($handle, LOCK_UN);
                    }

                    return;
                }

                usleep(50_000);
            } while (microtime(true) < $deadline);

            throw new RuntimeException("模块 [{$module}] 正在升级中，请稍后再试。");
        } finally {
            fclose($handle);
        }
    }

    private function safeLockSegment(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $value) ?? '_';

        return in_array($safe, ['', '.', '..'], true) ? '_' : $safe;
    }
}
