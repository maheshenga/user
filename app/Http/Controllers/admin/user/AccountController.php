<?php

namespace App\Http\Controllers\admin\user;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use App\Models\UserAccount;
use App\User\UserAccountStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

#[ControllerAnnotation(title: '用户账号管理')]
class AccountController extends AdminController
{
    private const LIST_COLUMNS = [
        'id',
        'mobile',
        'email',
        'nickname',
        'status',
        'vip_level',
        'available_balance',
        'last_login_at',
    ];

    private const SEARCHABLE_COLUMNS = [
        'id',
        'mobile',
        'email',
        'nickname',
        'status',
        'vip_level',
        'last_login_at',
    ];

    private const SORTABLE_COLUMNS = [
        'id',
        'mobile',
        'email',
        'nickname',
        'status',
        'vip_level',
        'available_balance',
        'last_login_at',
    ];

    public function initialize()
    {
        parent::initialize();
        $this->model = new UserAccount();
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
        if (! in_array($order, self::SORTABLE_COLUMNS, true)) {
            $this->order = 'id';
            $this->orderDirection = 'desc';

            return $this;
        }

        $direction = strtolower($direction);
        $this->order = $order;
        $this->orderDirection = in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc';

        return $this;
    }

    #[NodeAnnotation(title: '列表', auth: true)]
    public function index(): View|JsonResponse
    {
        if (! request()->ajax() && ! request()->expectsJson()) {
            return $this->fetch();
        }

        [$page, $limit, $where, $excludes] = $this->buildTableParams();
        unset($excludes);

        $where = $this->sanitizeTableWhere($where);
        [$order, $direction] = $this->sanitizeTableOrder();

        $query = UserAccount::query()->where($where);
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

    #[NodeAnnotation(title: '详情', auth: true)]
    public function detail(): View|JsonResponse
    {
        $id = (int) request()->input('id', 0);
        $user = UserAccount::query()->find($id);

        if (empty($user)) {
            return $this->error('用户不存在');
        }

        return $this->fetch('', ['user' => $user]);
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

    #[NodeAnnotation(title: '修改状态', auth: true)]
    public function modify(): JsonResponse
    {
        if (! request()->ajax() && ! request()->expectsJson()) {
            return $this->error();
        }

        $id = (int) request()->post('id', 0);
        $field = (string) request()->post('field', '');
        $value = (string) request()->post('value', '');

        if ($id <= 0 || $field === '' || $value === '') {
            return $this->error('ID、字段和值不能为空');
        }

        if ($field !== 'status') {
            return $this->error('用户账号管理仅允许修改账号状态。');
        }

        if (! in_array($value, $this->allowedStatuses(), true)) {
            return $this->error('账号状态值无效。');
        }

        $user = UserAccount::query()->find($id);

        if ($user === null) {
            return $this->error('用户不存在');
        }

        $user->forceFill([
            'status' => $value,
            'update_time' => time(),
        ])->save();

        return $this->success('保存成功');
    }

    public function recycle(): JsonResponse
    {
        return $this->readOnlyError();
    }

    public function export(): View|bool
    {
        abort(403, '用户账号管理当前为只读。');
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
        if (! in_array($this->order, self::SORTABLE_COLUMNS, true)) {
            return ['id', 'desc'];
        }

        $order = $this->order;
        $direction = strtolower($this->orderDirection);
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc';

        return [$order, $direction];
    }

    private function allowedStatuses(): array
    {
        return [
            UserAccountStatus::PENDING,
            UserAccountStatus::ACTIVE,
            UserAccountStatus::DISABLED,
            UserAccountStatus::FROZEN,
        ];
    }

    private function readOnlyError(): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'msg' => '用户账号管理当前为只读。',
            'data' => [],
        ]);
    }
}
