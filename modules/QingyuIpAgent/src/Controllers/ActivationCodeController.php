<?php

namespace Modules\QingyuIpAgent\Controllers;

use App\Http\Controllers\common\AdminController as Controller;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Modules\QingyuIpAgent\Services\ActivationCodeOpsService;
use Throwable;

#[ControllerAnnotation(title: '轻语激活码管理')]
class ActivationCodeController extends Controller
{
    #[NodeAnnotation(title: '激活码批次', auth: true)]
    public function index(): View|JsonResponse
    {
        $codes = app(ActivationCodeOpsService::class);
        if (! request()->ajax()) {
            return $this->fetch();
        }

        $page = (int) request()->input('page', 1);
        $limit = (int) request()->input('limit', 15);
        $result = $codes->batches(request()->only(['name', 'status']), $page, $limit);

        return response()->json(['code' => 0, 'msg' => '', 'count' => $result['total'], 'data' => $result['list']]);
    }

    #[NodeAnnotation(title: '创建激活码批次', auth: true)]
    public function createBatch(): View|JsonResponse
    {
        $codes = app(ActivationCodeOpsService::class);
        if (! request()->isMethod('post')) {
            return $this->fetch();
        }

        $validator = Validator::make(request()->all(), [
            'name' => ['required', 'string', 'max:120'],
            'vip_plan_id' => ['required', 'integer', 'min:1'],
            'total_count' => ['required', 'integer', 'min:1'],
            'duration_days' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string'],
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        try {
            return $this->success('创建成功', $codes->createBatch(request()->all(), (int) session('admin.id', 0)));
        } catch (Throwable $exception) {
            return $this->error($exception->getMessage());
        }
    }

    #[NodeAnnotation(title: '生成激活码', auth: true)]
    public function generateCodes(): View|JsonResponse
    {
        $codes = app(ActivationCodeOpsService::class);
        if (! request()->isMethod('post')) {
            return $this->fetch('', ['batchId' => (int) request()->input('batch_id', 0)]);
        }

        $validator = Validator::make(request()->all(), [
            'batch_id' => ['required', 'integer', 'min:1'],
            'count' => ['required', 'integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        try {
            return $this->success('生成成功', $codes->generateCodes(
                (int) request()->input('batch_id'),
                (int) request()->input('count'),
                (int) session('admin.id', 0)
            ));
        } catch (Throwable $exception) {
            return $this->error($exception->getMessage());
        }
    }

    #[NodeAnnotation(title: '激活码列表', auth: true)]
    public function codes(): JsonResponse
    {
        $codes = app(ActivationCodeOpsService::class);
        $page = (int) request()->input('page', 1);
        $limit = (int) request()->input('limit', 15);
        $result = $codes->codes(request()->only(['batch_id', 'status']), $page, $limit);

        return response()->json(['code' => 0, 'msg' => '', 'count' => $result['total'], 'data' => $result['list']]);
    }

    #[NodeAnnotation(title: '兑换记录', auth: true)]
    public function redemptions(): View|JsonResponse
    {
        $codes = app(ActivationCodeOpsService::class);
        if (! request()->ajax()) {
            return $this->fetch();
        }

        $page = (int) request()->input('page', 1);
        $limit = (int) request()->input('limit', 15);
        $result = $codes->redemptions(request()->only(['result', 'user_id']), $page, $limit);

        return response()->json(['code' => 0, 'msg' => '', 'count' => $result['total'], 'data' => $result['list']]);
    }
}
