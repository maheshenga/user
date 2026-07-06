<?php

namespace App\Http\Controllers\admin\user;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use App\Models\UserWithdrawalRequest;
use App\User\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use InvalidArgumentException;

#[ControllerAnnotation(title: '用户提现管理')]
class WithdrawalController extends AdminController
{
    private const LIST_COLUMNS = [
        'id',
        'withdrawal_no',
        'user_id',
        'amount',
        'status',
        'request_ip',
        'ledger_freeze_id',
        'ledger_success_id',
        'reason',
        'audit_admin_id',
        'audited_at',
        'approved_admin_id',
        'approved_at',
        'payout_admin_id',
        'payout_method',
        'payout_transaction_id',
        'payout_attempt_count',
        'payout_last_attempt_at',
        'paid_at',
        'create_time',
    ];

    private const SEARCHABLE_COLUMNS = [
        'id',
        'withdrawal_no',
        'user_id',
        'status',
        'request_ip',
        'audit_admin_id',
        'approved_admin_id',
        'payout_admin_id',
        'payout_method',
        'payout_transaction_id',
        'paid_at',
        'create_time',
    ];

    public function initialize()
    {
        parent::initialize();
        $this->model = new UserWithdrawalRequest();
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

    #[NodeAnnotation(title: '提现审核', auth: true)]
    public function index(): View|JsonResponse
    {
        if (! request()->ajax() && ! request()->expectsJson()) {
            return $this->fetch();
        }

        [$page, $limit, $where, $excludes] = $this->buildTableParams();
        unset($excludes);

        $where = $this->sanitizeTableWhere($where);
        [$order, $direction] = $this->sanitizeTableOrder();

        $query = UserWithdrawalRequest::query()->where($where);
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
            return $this->success('提现已通过。', app(WithdrawalService::class)->approve(
                (int) request()->input('id', 0),
                (int) session('admin.id', 0)
            ));
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    public function reject(): JsonResponse
    {
        try {
            return $this->success('提现已拒绝。', app(WithdrawalService::class)->reject(
                (int) request()->input('id', 0),
                (string) request()->input('reason', ''),
                (int) session('admin.id', 0)
            ));
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    public function payout(): JsonResponse
    {
        try {
            $proof = request()->input('proof', []);
            if (! is_array($proof)) {
                $proof = [];
            }

            return $this->success('提现打款已记录。', app(WithdrawalService::class)->markPaid(
                (int) request()->input('id', 0),
                [
                    'method' => request()->input('method', ''),
                    'transaction_id' => request()->input('transaction_id', ''),
                    'proof' => $proof,
                ],
                (int) session('admin.id', 0)
            ));
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    public function payoutFail(): JsonResponse
    {
        try {
            return $this->success('提现打款失败已记录。', app(WithdrawalService::class)->markPayoutFailed(
                (int) request()->input('id', 0),
                (string) request()->input('error', ''),
                (int) session('admin.id', 0)
            ));
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'code' => 1,
            'msg' => '提现统计。',
            'data' => app(WithdrawalService::class)->stats(),
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
        abort(403, '提现导出已禁用。');
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
            'msg' => '不允许执行该提现操作。',
            'data' => [],
        ]);
    }
}
