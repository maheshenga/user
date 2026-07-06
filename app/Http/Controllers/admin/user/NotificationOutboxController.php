<?php

namespace App\Http\Controllers\admin\user;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use App\Models\UserNotificationOutbox;
use App\User\NotificationOutboxMaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

#[ControllerAnnotation(title: '用户通知队列')]
class NotificationOutboxController extends AdminController
{
    private const LIST_COLUMNS = [
        'id',
        'user_id',
        'type',
        'channel',
        'recipient_mask',
        'subject',
        'status',
        'attempt_count',
        'last_error',
        'available_at',
        'sent_at',
        'create_time',
        'update_time',
    ];

    private const SEARCHABLE_COLUMNS = [
        'id',
        'user_id',
        'type',
        'channel',
        'recipient_mask',
        'status',
        'attempt_count',
        'create_time',
    ];

    public function initialize()
    {
        parent::initialize();
        $this->model = new UserNotificationOutbox();
    }

    #[NodeAnnotation(title: '通知队列', auth: true)]
    public function index(): View|JsonResponse
    {
        if (! request()->ajax() && ! request()->expectsJson()) {
            return $this->fetch();
        }

        [$page, $limit, $where, $excludes] = $this->buildTableParams();
        unset($excludes);

        $where = $this->sanitizeTableWhere($where);
        [$order, $direction] = $this->sanitizeTableOrder();
        $query = UserNotificationOutbox::query()->where($where);

        return response()->json([
            'code' => 0,
            'msg' => '',
            'count' => (clone $query)->count(),
            'data' => (clone $query)
                ->select(self::LIST_COLUMNS)
                ->orderBy($order, $direction)
                ->paginate((int) $limit, ['*'], 'page', (int) $page)
                ->items(),
        ]);
    }

    public function stats(): View|JsonResponse
    {
        return response()->json([
            'code' => 1,
            'msg' => '通知队列统计。',
            'data' => app(NotificationOutboxMaintenanceService::class)->summary(),
            'url' => '',
            'wait' => 3,
            '__token__' => csrf_token(),
        ]);
    }

    public function add(): JsonResponse { return $this->readOnlyError(); }
    public function edit(): JsonResponse { return $this->readOnlyError(); }
    public function delete(): JsonResponse { return $this->readOnlyError(); }
    public function modify(): JsonResponse { return $this->readOnlyError(); }
    public function recycle(): JsonResponse { return $this->readOnlyError(); }

    public function export(): View|bool
    {
        abort(403, '通知队列导出已禁用。');
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
            'msg' => '不允许执行该通知队列操作。',
            'data' => [],
        ]);
    }
}
