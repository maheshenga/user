<?php

namespace App\Modules;

use App\Models\SystemModule;
use InvalidArgumentException;

final class ModuleUpgrader
{
    public function __construct(
        private readonly ModuleRepository $repository,
        private readonly ModuleReleaseManager $releases,
        private readonly ModuleOperationCoordinator $operations,
    ) {}

    public function upgradeLocal(string $name, ?int $actorId = null): void
    {
        if (app()->environment('production')) {
            throw new InvalidArgumentException('生产环境禁止从可变本地目录直接升级模块，请上传 ZIP 制品并重新审核。');
        }

        $this->operations->run($name, 'upgrade_local', $actorId, function (string $operationId) use ($name, $actorId): void {
            $this->operations->stage($operationId, 'validating_upgrade');
            $module = $this->installedModule($name);
            $manifest = ModuleManifest::fromFile(
                rtrim((string) $module->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'module.json'
            );
            $this->assertManifestName($manifest, $name);
            $this->assertUpgradeable((string) $module->status, (string) $module->version, $manifest);
            $this->releases->stageManifest($manifest, 'local', 'private', $actorId);
            $this->operations->stage($operationId, 'upgrade_staged');
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

}
