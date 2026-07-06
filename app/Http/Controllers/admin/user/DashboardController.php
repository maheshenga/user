<?php

namespace App\Http\Controllers\admin\user;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use App\User\UserOpsDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

#[ControllerAnnotation(title: '用户运营概览')]
class DashboardController extends AdminController
{
    #[NodeAnnotation(title: '运营概览', auth: true)]
    public function index(): View|JsonResponse
    {
        if (request()->ajax() || request()->expectsJson()) {
            return response()->json([
                'code' => 1,
                'msg' => '用户运营指标。',
                'data' => app(UserOpsDashboardService::class)->metrics(),
                'url' => '',
                'wait' => 3,
                '__token__' => csrf_token(),
            ]);
        }

        return $this->fetch();
    }
}
