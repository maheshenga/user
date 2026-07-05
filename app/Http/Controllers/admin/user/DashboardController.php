<?php

namespace App\Http\Controllers\admin\user;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use App\User\UserOpsDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

#[ControllerAnnotation(title: 'User Operations Dashboard')]
class DashboardController extends AdminController
{
    #[NodeAnnotation(title: 'Overview', auth: true)]
    public function index(): View|JsonResponse
    {
        if (request()->ajax() || request()->expectsJson()) {
            return response()->json([
                'code' => 1,
                'msg' => 'User operations metrics.',
                'data' => app(UserOpsDashboardService::class)->metrics(),
                'url' => '',
                'wait' => 3,
                '__token__' => csrf_token(),
            ]);
        }

        return $this->fetch();
    }
}
