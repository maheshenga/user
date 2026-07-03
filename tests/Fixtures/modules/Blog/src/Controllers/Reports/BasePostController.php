<?php

namespace Modules\Blog\Controllers\Reports;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\annotation\NodeAnnotation;
use Illuminate\Http\JsonResponse;

class BasePostController extends AdminController
{
    #[NodeAnnotation(title: 'Inherited Report Action', auth: true)]
    public function inheritedAction(): JsonResponse
    {
        return response()->json(['action' => 'reports-inherited']);
    }
}
