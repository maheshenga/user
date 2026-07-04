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

        $query = UserAccount::query()->where($where);
        $list = (clone $query)
            ->orderBy($this->order, $this->orderDirection)
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
}
