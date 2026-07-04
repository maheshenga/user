<?php

namespace App\Http\Controllers\admin\user;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use App\Models\UserAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

#[ControllerAnnotation(title: 'User Account Management')]
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

    #[NodeAnnotation(title: 'List', auth: true)]
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

    #[NodeAnnotation(title: 'Detail', auth: true)]
    public function detail(): View|JsonResponse
    {
        $id = request()->input('id');
        $user = UserAccount::query()->find($id);

        if (empty($user)) {
            return $this->error('User not found');
        }

        return $this->fetch('', ['user' => $user]);
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
}
