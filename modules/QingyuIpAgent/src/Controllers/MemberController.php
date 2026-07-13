<?php

namespace Modules\QingyuIpAgent\Controllers;

use App\Http\Controllers\common\AdminController as Controller;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Modules\QingyuIpAgent\Services\MemberOpsService;
use Throwable;

#[ControllerAnnotation(title: '轻语会员运营')]
class MemberController extends Controller
{
    #[NodeAnnotation(title: '会员列表', auth: true)]
    public function index(): View|JsonResponse
    {
        $members = app(MemberOpsService::class);
        if (! request()->ajax()) {
            return $this->fetch();
        }

        $page = (int) request()->input('page', 1);
        $limit = (int) request()->input('limit', 15);
        $result = $members->paginate(request()->only(['keyword', 'status']), $page, $limit);

        return response()->json(['code' => 0, 'msg' => '', 'count' => $result['total'], 'data' => $result['list']]);
    }

    #[NodeAnnotation(title: '会员详情', auth: true)]
    public function detail(): View|JsonResponse
    {
        $members = app(MemberOpsService::class);
        $row = $members->detail((int) request()->input('id'));
        if (request()->ajax()) {
            return response()->json(['code' => 1, 'msg' => 'ok', 'data' => $row]);
        }

        return $this->fetch('', compact('row'));
    }

    #[NodeAnnotation(title: '发放 VIP', auth: true)]
    public function grantVip(): JsonResponse
    {
        $members = app(MemberOpsService::class);
        if (! request()->isMethod('post')) {
            return $this->error('请使用 POST 请求。');
        }

        $validator = Validator::make(request()->all(), [
            'user_id' => ['required', 'integer', 'min:1'],
            'vip_plan_id' => ['required', 'integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        try {
            $result = $members->grantVip(
                (int) request()->input('user_id'),
                (int) request()->input('vip_plan_id'),
                (int) session('admin.id', 0)
            );

            return $this->success('发放成功', $result);
        } catch (Throwable $exception) {
            return $this->error($exception->getMessage());
        }
    }
}
