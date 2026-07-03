<?php

use App\Models\SystemModule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Inspiring;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('module:discover', function () {
    foreach (app(\App\Modules\ModuleManager::class)->discover() as $manifest) {
        app(\App\Modules\ModuleRepository::class)->upsertDiscovered($manifest);
        $this->line($manifest->name().' '.$manifest->version());
    }
})->purpose('Discover local EasyAdmin8 modules');

Artisan::command('module:install {name}', function (string $name) {
    app(\App\Modules\ModuleInstaller::class)->install($name);
    $this->info("Installed module: {$name}");
})->purpose('Install a local EasyAdmin8 module');

Artisan::command('module:enable {name}', function (string $name) {
    app(\App\Modules\ModuleInstaller::class)->enable($name);
    $this->info("Enabled module: {$name}");
})->purpose('Enable an installed EasyAdmin8 module');

Artisan::command('module:disable {name}', function (string $name) {
    app(\App\Modules\ModuleInstaller::class)->disable($name);
    $this->info("Disabled module: {$name}");
})->purpose('Disable an EasyAdmin8 module');

Artisan::command('module:uninstall {name}', function (string $name) {
    app(\App\Modules\ModuleInstaller::class)->uninstallPreserve($name);
    $this->info("Uninstalled module: {$name}");
})->purpose('Uninstall an EasyAdmin8 module while preserving data');

Artisan::command('module:list', function () {
    $rows = SystemModule::query()
        ->orderBy('name')
        ->get(['name', 'version', 'type', 'status', 'admin_prefix'])
        ->map(fn ($module) => $module->toArray())
        ->all();
    $this->table(['name', 'version', 'type', 'status', 'admin_prefix'], $rows);
})->purpose('List EasyAdmin8 modules');
