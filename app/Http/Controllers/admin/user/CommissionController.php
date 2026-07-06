<?php

namespace App\Http\Controllers\admin\user;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use App\Models\AffiliateCommission;
use App\User\AffiliateService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use InvalidArgumentException;

#[ControllerAnnotation(title: '分销佣金管理')]
class CommissionController extends AdminController
{
    private const LIST_COLUMNS = [
        'id',
        'source_type',
        'source_id',
        'buyer_user_id',
        'beneficiary_user_id',
        'level',
        'amount',
        'status',
        'audit_admin_id',
        'audited_at',
        'settled_ledger_id',
        'create_time',
    ];

    private const SEARCHABLE_COLUMNS = [
        'id',
        'source_type',
        'source_id',
        'buyer_user_id',
        'beneficiary_user_id',
        'level',
        'status',
        'audit_admin_id',
        'create_time',
    ];

    public function initialize()
    {
        parent::initialize();
        $this->model = new AffiliateCommission();
    }

    public function setOrder(): static
    {
        $tableOrder = trim((string) request()->get('tableOrder', ''));
        if ($tableOrder === '') {
            return $this;
        }

        $parts = preg_split('/\s+/', $tableOrder) ?: [];
        if (count($parts) !== 2) {
            $this->order = 'id';
            $this->orderDirection = 'desc';

            return $this;
        }

        [$order, $direction] = $parts;
        if (! in_array($order, self::LIST_COLUMNS, true)) {
            $this->order = 'id';
            $this->orderDirection = 'desc';

            return $this;
        }

        $direction = strtolower($direction);
        $this->order = $order;
        $this->orderDirection = in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc';

        return $this;
    }

    #[NodeAnnotation(title: '分销佣金列表', auth: true)]
    public function index(): View|JsonResponse
    {
        if (! request()->ajax() && ! request()->expectsJson()) {
            return $this->fetch();
        }

        [$page, $limit, $where, $excludes] = $this->buildTableParams();
        unset($excludes);

        $where = $this->sanitizeTableWhere($where);
        [$order, $direction] = $this->sanitizeTableOrder();

        $query = AffiliateCommission::query()->where($where);
        $list = (clone $query)
            ->select(self::LIST_COLUMNS)
            ->orderBy($order, $direction)
            ->paginate((int) $limit, ['*'], 'page', (int) $page)
            ->items();

        return response()->json([
            'code' => 0,
            'msg' => '',
            'count' => (clone $query)->count(),
            'data' => $list,
        ]);
    }

    public function approve(): JsonResponse
    {
        try {
            $result = app(AffiliateService::class)->approve(
                (int) request()->input('id', 0),
                (int) session('admin.id', 0)
            );

            return $this->success('佣金审核通过。', $result);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    public function reject(): JsonResponse
    {
        try {
            $result = app(AffiliateService::class)->reject(
                (int) request()->input('id', 0),
                (string) request()->input('reason', ''),
                (int) session('admin.id', 0)
            );

            return $this->success('佣金审核拒绝。', $result);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    public function batchApprove(): JsonResponse
    {
        try {
            $result = app(AffiliateService::class)->batchApprove(
                $this->requestIds(),
                (int) session('admin.id', 0)
            );

            return $this->success('佣金批量审核通过。', $result);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    public function batchReject(): JsonResponse
    {
        try {
            $result = app(AffiliateService::class)->batchReject(
                $this->requestIds(),
                (string) request()->input('reason', ''),
                (int) session('admin.id', 0)
            );

            return $this->success('佣金批量审核拒绝。', $result);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'code' => 1,
            'msg' => '佣金统计。',
            'data' => app(AffiliateService::class)->stats(),
            'url' => '',
            'wait' => 3,
            '__token__' => csrf_token(),
        ]);
    }

    public function add(): JsonResponse
    {
        return $this->readOnlyError();
    }

    public function edit(): JsonResponse
    {
        return $this->readOnlyError();
    }

    public function delete(): JsonResponse
    {
        return $this->readOnlyError();
    }

    public function modify(): JsonResponse
    {
        return $this->readOnlyError();
    }

    public function recycle(): JsonResponse
    {
        return $this->readOnlyError();
    }

    public function export(): View|bool
    {
        abort(403, '佣金导出功能已禁用。');
    }

    private function sanitizeTableWhere(array $where): array
    {
        return array_values(array_filter($where, static function (array $condition): bool {
            $field = $condition[0] ?? null;

            return is_string($field) && in_array($field, self::SEARCHABLE_COLUMNS, true);
        }));
    }

    private function sanitizeTableOrder(): array
    {
        if (! in_array($this->order, self::LIST_COLUMNS, true)) {
            return ['id', 'desc'];
        }

        $direction = strtolower($this->orderDirection);

        return [$this->order, in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc'];
    }

    private function readOnlyError(): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'msg' => '佣金记录不允许执行该操作。',
            'data' => [],
        ]);
    }

    private function requestIds(): array
    {
        $ids = request()->input('ids', request()->input('id', []));

        return is_array($ids) ? $ids : [$ids];
    }
}
