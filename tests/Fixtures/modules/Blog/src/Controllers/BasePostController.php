<?php

namespace Modules\Blog\Controllers;

use App\Http\Controllers\common\AdminController;
use App\Http\Services\annotation\NodeAnnotation;
use Illuminate\Http\JsonResponse;

class BasePostController extends AdminController
{
    #[NodeAnnotation(title: 'Inherited Action', auth: true)]
    public function inheritedAction(): JsonResponse
    {
        return response()->json(['action' => 'inherited']);
    }

    #[NodeAnnotation(title: 'Hidden Inherited Action', auth: true)]
    public function hiddenInheritedAction(): JsonResponse
    {
        return response()->json(['action' => 'hidden-inherited']);
    }
}
