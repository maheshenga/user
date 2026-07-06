<?php

namespace App\Http\Controllers\admin\user;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\TriggerService;
use App\Models\SystemConfig;
use App\User\UserOpsSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use InvalidArgumentException;

class SettingsController extends AdminController
{
    public function index(): View
    {
        $settings = app(UserOpsSettings::class);
        $this->assign(['settings' => $settings->publicSettings()]);

        return $this->fetch();
    }

    public function save(): JsonResponse
    {
        if (! request()->ajax() && ! request()->expectsJson()) {
            return $this->error();
        }

        try {
            $settings = app(UserOpsSettings::class);
            foreach ($settings->validateForSave(request()->post()) as $name => $value) {
                SystemConfig::query()->updateOrInsert([
                    'group' => UserOpsSettings::GROUP,
                    'name' => $name,
                ], [
                    'value' => $value,
                ]);
            }

            TriggerService::updateSysConfig();

            return $this->success('Saved');
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }
}
