<?php

namespace Modules\Blog\Controllers\Reports;

use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

#[ControllerAnnotation(title: 'Report Posts', auth: true)]
class PostController extends BasePostController
{
    #[NodeAnnotation(title: 'Report Post Index', auth: true)]
    public function index(): View|JsonResponse
    {
        return $this->fetch();
    }
}
