<?php

namespace App\Http\Controllers\admin\user;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use App\Models\VipPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

#[ControllerAnnotation(title: 'VIP套餐管理')]
class VipPlanController extends AdminController
{
    private const LIST_COLUMNS = [
        'id',
        'name',
        'level',
        'duration_days',
        'price',
        'status',
        'is_commissionable',
        'first_level_rate',
        'second_level_rate',
        'create_time',
    ];

    private const SEARCHABLE_COLUMNS = [
        'id',
        'name',
        'level',
        'duration_days',
        'price',
        'status',
        'is_commissionable',
        'create_time',
    ];

    private const WRITE_COLUMNS = [
        'name',
        'level',
        'duration_days',
        'price',
        'status',
        'is_commissionable',
        'first_level_rate',
        'second_level_rate',
        'benefits_json',
    ];

    public function initialize()
    {
        parent::initialize();
        $this->model = new VipPlan();
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

    #[NodeAnnotation(title: 'VIP套餐列表', auth: true)]
    public function index(): View|JsonResponse
    {
        if (! request()->ajax() && ! request()->expectsJson()) {
            return $this->fetch();
        }

        [$page, $limit, $where, $excludes] = $this->buildTableParams();
        unset($excludes);

        $where = $this->sanitizeTableWhere($where);
        [$order, $direction] = $this->sanitizeTableOrder();

        $query = VipPlan::query()->where($where);
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

    public function add(): View|JsonResponse
    {
        if (! request()->ajax() && ! request()->expectsJson() && request()->method() !== 'POST') {
            return $this->fetch();
        }

        $plan = VipPlan::query()->create($this->writePayload(true));

        return $this->success('VIP套餐已保存。', ['id' => (int) $plan->id]);
    }

    public function edit(): View|JsonResponse
    {
        $id = (int) request()->input('id', 0);
        $plan = VipPlan::query()->find($id);
        if ($plan === null) {
            return $this->error('VIP套餐不存在。');
        }

        if (! request()->ajax() && ! request()->expectsJson() && request()->method() !== 'POST') {
            return $this->fetch('', ['row' => $plan]);
        }

        $plan->forceFill($this->writePayload(false))->save();

        return $this->success('VIP套餐已保存。', ['id' => (int) $plan->id]);
    }

    public function modify(): JsonResponse
    {
        $id = (int) request()->input('id', 0);
        $field = (string) request()->input('field', '');
        if (! in_array($field, self::WRITE_COLUMNS, true)) {
            return $this->readOnlyError();
        }

        $plan = VipPlan::query()->find($id);
        if ($plan === null) {
            return $this->error('VIP套餐不存在。');
        }

        $plan->forceFill([
            $field => request()->input('value'),
            'update_time' => time(),
        ])->save();

        return $this->success('VIP套餐已保存。', ['id' => (int) $plan->id]);
    }

    public function delete(): JsonResponse
    {
        return $this->readOnlyError();
    }

    public function recycle(): JsonResponse
    {
        return $this->readOnlyError();
    }

    public function export(): View|bool
    {
        abort(403, 'VIP套餐导出功能已禁用。');
    }

    private function writePayload(bool $isCreate): array
    {
        $payload = array_intersect_key(request()->all(), array_flip(self::WRITE_COLUMNS));

        if ($isCreate) {
            $payload += [
                'name' => '',
                'level' => 0,
                'duration_days' => 0,
                'price' => 0,
                'status' => 'active',
                'is_commissionable' => false,
                'first_level_rate' => 0,
                'second_level_rate' => 0,
                'benefits_json' => [],
            ];
        }

        if (array_key_exists('name', $payload)) $payload['name'] = trim((string) $payload['name']);
        if (array_key_exists('level', $payload)) $payload['level'] = (int) $payload['level'];
        if (array_key_exists('duration_days', $payload)) $payload['duration_days'] = (int) $payload['duration_days'];
        if (array_key_exists('price', $payload)) $payload['price'] = (float) $payload['price'];
        if (array_key_exists('status', $payload)) $payload['status'] = (string) $payload['status'];
        if (array_key_exists('is_commissionable', $payload)) $payload['is_commissionable'] = (bool) $payload['is_commissionable'];
        if (array_key_exists('first_level_rate', $payload)) $payload['first_level_rate'] = (float) $payload['first_level_rate'];
        if (array_key_exists('second_level_rate', $payload)) $payload['second_level_rate'] = (float) $payload['second_level_rate'];
        $payload['update_time'] = time();

        if ($isCreate) {
            $payload['create_time'] = time();
        }

        return $payload;
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
            'msg' => 'VIP套餐不允许执行该操作。',
            'data' => [],
        ]);
    }
}
