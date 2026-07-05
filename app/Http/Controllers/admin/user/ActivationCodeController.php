<?php

namespace App\Http\Controllers\admin\user;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use App\Models\ActivationCode;
use App\Models\ActivationCodeRedemption;
use App\User\ActivationCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use InvalidArgumentException;

#[ControllerAnnotation(title: 'Activation Code Management')]
class ActivationCodeController extends AdminController
{
    private const CODE_LIST_COLUMNS = [
        'id',
        'batch_id',
        'display_code_tail',
        'status',
        'max_uses',
        'used_count',
        'bound_user_id',
        'expires_at',
        'create_time',
    ];

    private const CODE_SEARCHABLE_COLUMNS = [
        'id',
        'batch_id',
        'display_code_tail',
        'status',
        'bound_user_id',
        'expires_at',
        'create_time',
    ];

    private const REDEMPTION_LIST_COLUMNS = [
        'id',
        'activation_code_id',
        'batch_id',
        'user_id',
        'vip_record_id',
        'commission_source_id',
        'redeem_ip',
        'result',
        'create_time',
    ];

    private const REDEMPTION_SEARCHABLE_COLUMNS = [
        'id',
        'activation_code_id',
        'batch_id',
        'user_id',
        'vip_record_id',
        'redeem_ip',
        'result',
        'create_time',
    ];

    public function initialize()
    {
        parent::initialize();
        $this->model = new ActivationCode();
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
        $allowed = request()->route('action') === 'redemptions'
            ? self::REDEMPTION_LIST_COLUMNS
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

    #[NodeAnnotation(title: 'Activation Codes', auth: true)]
    public function index(): View|JsonResponse
    {
        if (! request()->ajax() && ! request()->expectsJson()) {
            return $this->fetch();
        }

        [$page, $limit, $where, $excludes] = $this->buildTableParams();
        unset($excludes);

        $where = $this->sanitizeTableWhere($where, self::CODE_SEARCHABLE_COLUMNS);
        [$order, $direction] = $this->sanitizeTableOrder(self::CODE_LIST_COLUMNS);

        $query = ActivationCode::query()->where($where);
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

    #[NodeAnnotation(title: 'Activation Code Redemptions', auth: true)]
    public function redemptions(): View|JsonResponse
    {
        if (! request()->ajax() && ! request()->expectsJson()) {
            return $this->fetch();
        }

        [$page, $limit, $where, $excludes] = $this->buildTableParams();
        unset($excludes);

        $where = $this->sanitizeTableWhere($where, self::REDEMPTION_SEARCHABLE_COLUMNS);
        [$order, $direction] = $this->sanitizeTableOrder(self::REDEMPTION_LIST_COLUMNS);

        $query = ActivationCodeRedemption::query()->where($where);
        $list = (clone $query)
            ->select(self::REDEMPTION_LIST_COLUMNS)
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

    public function createBatch(): JsonResponse
    {
        try {
            $batch = app(ActivationCodeService::class)->createBatch(request()->all(), session('admin.id'));

            return $this->success('Activation code batch saved.', $batch);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    public function generate(): JsonResponse
    {
        try {
            $result = app(ActivationCodeService::class)->generateCodes(
                (int) request()->input('batch_id', 0),
                (int) request()->input('count', 0),
                session('admin.id')
            );

            return $this->success('Activation codes generated.', $result);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    public function disable(): JsonResponse
    {
        return $this->setStatus('disabled');
    }

    public function void(): JsonResponse
    {
        return $this->setStatus('void');
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
        abort(403, 'Activation code export is disabled in Phase 4.');
    }

    private function setStatus(string $status): JsonResponse
    {
        $id = (int) request()->input('id', 0);
        $code = ActivationCode::query()->find($id);
        if ($code === null) {
            return $this->error('Activation code not found.');
        }

        $code->forceFill([
            'status' => $status,
            'update_time' => time(),
        ])->save();

        return $this->success('Activation code updated.', ['id' => (int) $code->id, 'status' => $status]);
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
            'msg' => 'Activation code action is not allowed.',
            'data' => [],
        ]);
    }
}
