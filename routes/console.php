<?php

use App\Models\SystemModule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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
            $this->error('Module tables are not installed. Run the module migrations before using module commands.');

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
    } catch (\Throwable $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }

    return Command::SUCCESS;
};

Artisan::command('module:discover', function () use ($runModuleCommand) {
    return $runModuleCommand->call($this, function (): void {
        foreach (app(\App\Modules\ModuleManager::class)->discover() as $manifest) {
            app(\App\Modules\ModuleRepository::class)->upsertDiscovered($manifest);
            $this->line($manifest->name().' '.$manifest->version());
        }
    });
})->purpose('Discover local EasyAdmin8 modules');

Artisan::command('module:install {name}', function (string $name) use ($runModuleCommand) {
    return $runModuleCommand->call($this, function () use ($name): void {
        app(\App\Modules\ModuleInstaller::class)->install($name);
        $this->info("Installed module: {$name}");
    });
})->purpose('Install a local EasyAdmin8 module');

Artisan::command('module:enable {name}', function (string $name) use ($runModuleCommand) {
    return $runModuleCommand->call($this, function () use ($name): void {
        app(\App\Modules\ModuleInstaller::class)->enable($name);
        $this->info("Enabled module: {$name}");
    });
})->purpose('Enable an installed EasyAdmin8 module');

Artisan::command('module:disable {name}', function (string $name) use ($runModuleCommand) {
    return $runModuleCommand->call($this, function () use ($name): void {
        app(\App\Modules\ModuleInstaller::class)->disable($name);
        $this->info("Disabled module: {$name}");
    });
})->purpose('Disable an EasyAdmin8 module');

Artisan::command('module:uninstall {name}', function (string $name) use ($runModuleCommand) {
    return $runModuleCommand->call($this, function () use ($name): void {
        app(\App\Modules\ModuleInstaller::class)->uninstallPreserve($name);
        $this->info("Uninstalled module: {$name}");
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

Artisan::command('user:notifications:send {--limit=50}', function (): int {
    $result = app(\App\User\NotificationOutboxDispatcher::class)->sendPending((int) $this->option('limit'));
    $this->info('sent='.$result['sent'].' failed='.$result['failed']);

    return Command::SUCCESS;
})->purpose('Send pending user notification outbox rows');

Artisan::command('user:notifications:purge {--days=30} {--limit=500}', function (): int {
    $result = app(\App\User\NotificationOutboxMaintenanceService::class)->purgeSentOlderThan(
        (int) $this->option('days'),
        (int) $this->option('limit')
    );
    $this->info('deleted='.$result['deleted'].' days='.$result['days'].' limit='.$result['limit']);

    return Command::SUCCESS;
})->purpose('Purge old sent user notification outbox rows');

Artisan::command('user:ops-menu:sync', function (): int {
    try {
        $result = app(\App\User\UserOpsMenuService::class)->sync();
    } catch (\RuntimeException $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }

    $this->info('parent_id='.$result['parent_id'].' synced='.$result['synced']);

    return Command::SUCCESS;
})->purpose('Synchronize EasyAdmin menu entries for user operations');

Artisan::command('system:module-menu:sync', function (): int {
    try {
        $result = app(\App\Modules\ModuleCenterMenuService::class)->sync();
    } catch (\RuntimeException $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }

    $this->info('parent_id='.$result['parent_id'].' synced='.$result['synced']);

    return Command::SUCCESS;
})->purpose('Synchronize EasyAdmin menu entry for module management');
