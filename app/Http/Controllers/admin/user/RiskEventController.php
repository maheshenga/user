<?php

namespace App\Http\Controllers\admin\user;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use App\Models\UserRiskEvent;
use App\User\RiskService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use InvalidArgumentException;

#[ControllerAnnotation(title: '用户风控事件管理')]
class RiskEventController extends AdminController
{
    private const LIST_COLUMNS = [
        'id',
        'user_id',
        'category',
        'event_type',
        'severity',
        'source_type',
        'source_id',
        'ip',
        'status',
        'review_admin_id',
        'reviewed_at',
        'create_time',
    ];

    private const SEARCHABLE_COLUMNS = [
        'id',
        'user_id',
        'category',
        'event_type',
        'severity',
        'source_type',
        'source_id',
        'ip',
        'status',
        'review_admin_id',
        'create_time',
    ];

    public function initialize()
    {
        parent::initialize();
        $this->model = new UserRiskEvent();
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

    #[NodeAnnotation(title: '风控事件', auth: true)]
    public function index(): View|JsonResponse
    {
        if (! request()->ajax() && ! request()->expectsJson()) {
            return $this->fetch();
        }

        [$page, $limit, $where, $excludes] = $this->buildTableParams();
        unset($excludes);

        $where = $this->sanitizeTableWhere($where);
        [$order, $direction] = $this->sanitizeTableOrder();

        $query = UserRiskEvent::query()->where($where);
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

    public function review(): JsonResponse
    {
        try {
            $result = app(RiskService::class)->review(
                (int) request()->input('id', 0),
                (string) request()->input('status', ''),
                (int) session('admin.id', 0)
            );

            return $this->success('风控事件已审核。', $result);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    public function add(): JsonResponse { return $this->readOnlyError(); }
    public function edit(): JsonResponse { return $this->readOnlyError(); }
    public function delete(): JsonResponse { return $this->readOnlyError(); }
    public function modify(): JsonResponse { return $this->readOnlyError(); }
    public function recycle(): JsonResponse { return $this->readOnlyError(); }

    public function export(): View|bool
    {
        abort(403, '风控事件导出已禁用。');
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
            'msg' => '不允许执行该风控事件操作。',
            'data' => [],
        ]);
    }
}
