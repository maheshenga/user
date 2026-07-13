<?php

namespace Modules\QingyuIpAgent\Controllers;

use App\Http\Controllers\common\AdminController as Controller;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Modules\QingyuIpAgent\Services\DashboardService;

#[ControllerAnnotation(title: '轻语运营概览')]
class DashboardController extends Controller
{
    #[NodeAnnotation(title: '运营概览', auth: true)]
    public function index(): View|JsonResponse
    {
        $dashboard = app(DashboardService::class);
        $summary = $dashboard->summary();
        if (request()->ajax()) {
            return response()->json(['code' => 0, 'msg' => '', 'data' => $summary]);
        }

        return $this->fetch('', compact('summary'));
    }
}
