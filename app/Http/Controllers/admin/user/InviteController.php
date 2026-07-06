<?php

namespace App\Http\Controllers\admin\user;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use App\Models\UserInviteCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

#[ControllerAnnotation(title: '用户邀请管理')]
class InviteController extends AdminController
{
    private const CODE_LIST_COLUMNS = [
        'id',
        'owner_user_id',
        'code',
        'type',
        'status',
        'max_uses',
        'used_count',
        'expires_at',
        'create_time',
    ];

    private const CODE_SEARCHABLE_COLUMNS = [
        'id',
        'owner_user_id',
        'code',
        'type',
        'status',
        'expires_at',
    ];

    private const RELATION_LIST_COLUMNS = [
        'id',
        'user_id',
        'parent_user_id',
        'grandparent_user_id',
        'invite_code_id',
        'level_path',
        'bind_type',
        'status',
        'create_time',
    ];

    private const RELATION_SEARCHABLE_COLUMNS = [
        'id',
        'user_id',
        'parent_user_id',
        'grandparent_user_id',
        'invite_code_id',
        'bind_type',
        'status',
        'create_time',
    ];

    public function initialize()
    {
        parent::initialize();
        $this->model = new UserInviteCode();
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
        $allowed = request()->route('action') === 'relations'
            ? self::RELATION_LIST_COLUMNS
            : self::CODE_LIST_COLUMNS;

        if (! in_array($order, $allowed, true)) {
            $this->order = 'id';
            $this->orderDirection = 'desc';

            return $this;
        }

        $direction = strtolower($direction);
        $this->order = $order;
        $this->orderDirection = in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc';

        return $this;
    }

    #[NodeAnnotation(title: '邀请码列表', auth: true)]
    public function index(): View|JsonResponse
    {
        if (! request()->ajax() && ! request()->expectsJson()) {
            return $this->fetch();
        }

        [$page, $limit, $where, $excludes] = $this->buildTableParams();
        unset($excludes);

        $where = $this->sanitizeTableWhere($where, self::CODE_SEARCHABLE_COLUMNS);
        [$order, $direction] = $this->sanitizeTableOrder(self::CODE_LIST_COLUMNS);

        $query = UserInviteCode::query()->where($where);
        $list = (clone $query)
            ->select(self::CODE_LIST_COLUMNS)
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

    #[NodeAnnotation(title: '邀请关系', auth: true)]
    public function relations(): View|JsonResponse
    {
        if (! request()->ajax() && ! request()->expectsJson()) {
            return $this->fetch();
        }

        [$page, $limit, $where, $excludes] = $this->buildTableParams();
        unset($excludes);

        $where = $this->sanitizeTableWhere($where, self::RELATION_SEARCHABLE_COLUMNS);
        [$order, $direction] = $this->sanitizeTableOrder(self::RELATION_LIST_COLUMNS);

        $query = DB::table('user_invite_relation')->whereNull('delete_time');
        foreach ($where as $condition) {
            $query->where(...$condition);
        }

        $list = (clone $query)
            ->select(self::RELATION_LIST_COLUMNS)
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
        abort(403, '用户邀请管理当前为只读。');
    }

    private function sanitizeTableWhere(array $where, array $allowedColumns): array
    {
        return array_values(array_filter($where, static function (array $condition) use ($allowedColumns): bool {
            $field = $condition[0] ?? null;

            return is_string($field) && in_array($field, $allowedColumns, true);
        }));
    }

    private function sanitizeTableOrder(array $allowedColumns): array
    {
        if (! in_array($this->order, $allowedColumns, true)) {
            return ['id', 'desc'];
        }

        $direction = strtolower($this->orderDirection);

        return [$this->order, in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc'];
    }

    private function readOnlyError(): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'msg' => '用户邀请管理当前为只读。',
            'data' => [],
        ]);
    }
}
