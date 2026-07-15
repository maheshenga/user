<?php

use App\Models\SystemModule;
use App\Modules\ModuleCenterMenuService;
use App\Modules\ModuleHealthInspector;
use App\Modules\ModuleInstaller;
use App\Modules\ModuleManager;
use App\Modules\ModuleManifest;
use App\Modules\ModuleOperationRecovery;
use App\Modules\ModuleReleaseManager;
use App\Modules\ModuleRepository;
use App\Modules\ModuleRetentionService;
use App\Modules\ModuleReviewService;
use App\User\BalanceReconciliationService;
use App\User\NotificationOutboxDispatcher;
use App\User\NotificationOutboxMaintenanceService;
use App\User\UserOpsMenuService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('system:module-operations:recover {--minutes=15} {--json}', function (): int {
    if (! Schema::hasTable('system_module_operation')) {
        $this->error('Module operation records are not installed. Run database migrations first.');

        return Command::FAILURE;
    }

    $minutes = filter_var($this->option('minutes'), FILTER_VALIDATE_INT);
    if ($minutes === false || $minutes < 1 || $minutes > 10080) {
        $this->error('The stale threshold must be between 1 and 10080 minutes.');

        return Command::FAILURE;
    }

    $result = app(ModuleOperationRecovery::class)->recoverStale(now()->subMinutes($minutes));
    if ((bool) $this->option('json')) {
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    } else {
        foreach ($result['operations'] as $operation) {
            $this->line(
                $operation['module'].' '.$operation['action']
                .' operation='.$operation['id']
                .' restored='.($operation['restored'] ? 'yes' : 'no')
            );
        }
        $this->info(
            'examined='.$result['examined']
            .' recovered='.$result['recovered']
            .' restored='.$result['restored']
            .' skipped='.$result['skipped']
        );
    }

    return Command::SUCCESS;
})->purpose('Recover stale module lifecycle operation markers without reversing migrations');

$modulePersistenceTables = static fn (): array => [
    'system_module',
    'system_module_version',
    'system_module_migration',
    'system_module_log',
    'system_module_source',
];

$ensureModulePersistence = function () use ($modulePersistenceTables): bool {
    foreach ($modulePersistenceTables() as $table) {
        if (! Schema::hasTable($table)) {
            $this->error('模块数据表未安装，请先运行模块迁移。');

            return false;
        }
    }

    return true;
};

$runModuleCommand = function (callable $callback) use ($ensureModulePersistence): int {
    if (! $ensureModulePersistence->call($this)) {
        return Command::FAILURE;
    }

    try {
        $callback();
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }

    return Command::SUCCESS;
};

Artisan::command('module:discover', function () use ($runModuleCommand) {
    return $runModuleCommand->call($this, function (): void {
        foreach (app(ModuleManager::class)->discover() as $manifest) {
            app(ModuleRepository::class)->upsertDiscovered($manifest);
            $this->line($manifest->name().' '.$manifest->version());
        }
    });
})->purpose('Discover local EasyAdmin8 modules');

Artisan::command('module:install {name}', function (string $name) use ($runModuleCommand) {
    return $runModuleCommand->call($this, function () use ($name): void {
        app(ModuleInstaller::class)->install($name);
        $this->info("模块已安装：{$name}");
    });
})->purpose('Install a local EasyAdmin8 module');

Artisan::command('module:enable {name}', function (string $name) use ($runModuleCommand) {
    return $runModuleCommand->call($this, function () use ($name): void {
        app(ModuleInstaller::class)->enable($name);
        $this->info("模块已启用：{$name}");
    });
})->purpose('Enable an installed EasyAdmin8 module');

Artisan::command('module:disable {name}', function (string $name) use ($runModuleCommand) {
    return $runModuleCommand->call($this, function () use ($name): void {
        app(ModuleInstaller::class)->disable($name);
        $this->info("模块已禁用：{$name}");
    });
})->purpose('Disable an EasyAdmin8 module');

Artisan::command('module:uninstall {name}', function (string $name) use ($runModuleCommand) {
    return $runModuleCommand->call($this, function () use ($name): void {
        app(ModuleInstaller::class)->uninstallPreserve($name);
        $this->info("模块已卸载：{$name}");
    });
})->purpose('Uninstall an EasyAdmin8 module while preserving data');

Artisan::command('module:list', function () use ($ensureModulePersistence) {
    if (! $ensureModulePersistence->call($this)) {
        return Command::FAILURE;
    }

    $rows = SystemModule::query()
        ->orderBy('name')
        ->get(['name', 'version', 'type', 'status', 'admin_prefix'])
        ->map(fn ($module) => $module->toArray())
        ->all();
    $this->table(['name', 'version', 'type', 'status', 'admin_prefix'], $rows);

    return Command::SUCCESS;
})->purpose('List EasyAdmin8 modules');

Artisan::command('module:release-adopt-enabled {--admin-id=}', function () use ($runModuleCommand) {
    return $runModuleCommand->call($this, function (): void {
        $adminId = (int) $this->option('admin-id');
        if ($adminId <= 0) {
            throw new InvalidArgumentException('必须提供有效的 --admin-id。');
        }

        $rows = SystemModule::query()
            ->whereIn('status', ['installed', 'enabled', 'disabled'])
            ->whereNull('active_release_id')
            ->orderBy('name')
            ->get();
        foreach ($rows as $module) {
            $manifest = ModuleManifest::fromFile(
                rtrim((string) $module->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'module.json'
            );
            app(ModuleReleaseManager::class)->stageManifest($manifest, 'local', 'private', $adminId);
            app(ModuleReviewService::class)->approve((string) $module->name, $adminId, 'private');
            app(ModuleReleaseManager::class)->activateApproved((string) $module->name, $adminId);
            $this->info("已纳入不可变制品：{$module->name} {$manifest->version()}");
        }

        $this->info('adopted='.$rows->count());
    });
})->purpose('Adopt runnable legacy modules into immutable release history with an administrator identity');

Artisan::command('system:module-health {--json}', function (): int {
    $result = app(ModuleHealthInspector::class)->inspect();
    if ((bool) $this->option('json')) {
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    } else {
        foreach ($result['issues'] as $issue) {
            $this->error((string) $issue['message']);
        }
        $this->info(
            'enabled='.$result['metrics']['enabled_modules']
            .' verified='.$result['metrics']['verified_modules']
            .' issues='.$result['metrics']['issue_count']
        );
    }

    return $result['ok'] ? Command::SUCCESS : Command::FAILURE;
})->purpose('Verify module release, integrity, ownership, and API readiness');

Artisan::command('system:module-retention:prune {--days=90} {--limit=500} {--json}', function (): int {
    $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
    if ($days === false || $days < 1 || $days > 3650) {
        $this->error('The retention period must be between 1 and 3650 days.');

        return Command::FAILURE;
    }
    if ($limit === false || $limit < 1 || $limit > 5000) {
        $this->error('The retention batch limit must be between 1 and 5000.');

        return Command::FAILURE;
    }

    $result = app(ModuleRetentionService::class)->prune(now()->subDays($days), $limit);
    if ((bool) $this->option('json')) {
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    } else {
        foreach ($result['deleted'] as $type => $deleted) {
            $this->line($type.'='.$deleted);
        }
        $this->info('total_deleted='.$result['total_deleted'].' artifact_failures='.$result['artifact_failures']);
    }

    return $result['artifact_failures'] === 0 ? Command::SUCCESS : Command::FAILURE;
})->purpose('Prune expired module platform operational history while preserving protected releases');

Artisan::command('user:notifications:send {--limit=50}', function (): int {
    $result = app(NotificationOutboxDispatcher::class)->sendPending((int) $this->option('limit'));
    $this->info(
        'sent='.$result['sent']
        .' failed='.$result['failed']
        .' dead='.$result['dead']
        .' recovered='.$result['recovered']
    );

    return Command::SUCCESS;
})->purpose('Send pending user notification outbox rows');

Artisan::command('user:notifications:purge {--days=30} {--limit=500}', function (): int {
    $result = app(NotificationOutboxMaintenanceService::class)->purgeSentOlderThan(
        (int) $this->option('days'),
        (int) $this->option('limit')
    );
    $this->info('deleted='.$result['deleted'].' days='.$result['days'].' limit='.$result['limit']);

    return Command::SUCCESS;
})->purpose('Purge old sent user notification outbox rows');

Artisan::command('user:balance:reconcile {--user=} {--limit=1000}', function (): int {
    $user = $this->option('user');
    if ($user !== null && $user !== '' && (! ctype_digit((string) $user) || (int) $user <= 0)) {
        $this->error('用户 ID 必须是正整数。');

        return Command::FAILURE;
    }
    $result = app(BalanceReconciliationService::class)->inspect(
        $user === null || $user === '' ? null : (int) $user,
        (int) $this->option('limit')
    );

    foreach ($result['issues'] as $issue) {
        $this->line(json_encode($issue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
    $this->info('checked='.$result['checked_users'].' issues='.$result['issue_count']);

    return $result['issue_count'] === 0 ? Command::SUCCESS : Command::FAILURE;
})->purpose('Verify user account balances against immutable ledger snapshots');

Artisan::command('user:ops-menu:sync', function (): int {
    try {
        $result = app(UserOpsMenuService::class)->sync();
    } catch (RuntimeException $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }

    $this->info('parent_id='.$result['parent_id'].' synced='.$result['synced']);

    return Command::SUCCESS;
})->purpose('Synchronize EasyAdmin menu entries for user operations');

Artisan::command('system:module-menu:sync', function (): int {
    try {
        $result = app(ModuleCenterMenuService::class)->sync();
    } catch (RuntimeException $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }

    $this->info('parent_id='.$result['parent_id'].' synced='.$result['synced']);

    return Command::SUCCESS;
})->purpose('Synchronize EasyAdmin menu entry for module management');

Schedule::command('user:notifications:send --limit=50')
    ->everyMinute()
    ->withoutOverlapping();
Schedule::command('user:balance:reconcile --limit=1000')
    ->hourly()
    ->withoutOverlapping();
Schedule::command('system:module-health --json')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
Schedule::command('system:module-operations:recover --minutes=15 --json')
    ->everyFiveMinutes()
    ->withoutOverlapping();
$retentionDays = max(1, (int) config('modules.retention_days', 90));
$retentionLimit = max(1, min(5000, (int) config('modules.retention_limit', 500)));
Schedule::command("system:module-retention:prune --days={$retentionDays} --limit={$retentionLimit} --json")
    ->dailyAt('03:30')
    ->withoutOverlapping();
