<?php

namespace Modules\QingyuIpAgent\Controllers;

use App\Http\Controllers\common\AdminController as Controller;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Modules\QingyuIpAgent\Services\AuditLogService;

#[ControllerAnnotation(title: '轻语操作审计')]
class AuditLogController extends Controller
{
    #[NodeAnnotation(title: '操作审计', auth: true)]
    public function index(): View|JsonResponse
    {
        $audit = app(AuditLogService::class);
        if (! request()->ajax()) {
            return $this->fetch();
        }

        $page = (int) request()->input('page', 1);
        $limit = (int) request()->input('limit', 15);
        $result = $audit->paginate(request()->only(['action', 'result']), $page, $limit);

        return response()->json(['code' => 0, 'msg' => '', 'count' => $result['total'], 'data' => $result['list']]);
    }
}
