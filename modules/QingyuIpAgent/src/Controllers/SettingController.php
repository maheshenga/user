<?php

namespace Modules\QingyuIpAgent\Controllers;

use App\Http\Controllers\common\AdminController as Controller;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Modules\QingyuIpAgent\Services\SettingService;

#[ControllerAnnotation(title: '轻语模块设置')]
class SettingController extends Controller
{
    #[NodeAnnotation(title: '模块设置', auth: true)]
    public function index(): View|JsonResponse
    {
        $settings = app(SettingService::class);
        $row = $settings->all();
        if (request()->ajax()) {
            return response()->json(['code' => 1, 'msg' => 'ok', 'data' => $row]);
        }

        return $this->fetch('', compact('row'));
    }

    #[NodeAnnotation(title: '保存设置', auth: true)]
    public function save(): JsonResponse
    {
        $settings = app(SettingService::class);
        if (! request()->isMethod('post')) {
            return $this->error('请使用 POST 请求。');
        }

        return $this->success('保存成功', $settings->save(request()->all()));
    }
}
