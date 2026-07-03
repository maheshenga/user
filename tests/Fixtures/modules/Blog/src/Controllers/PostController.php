<?php

namespace Modules\Blog\Controllers;

use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

#[ControllerAnnotation(title: 'Posts', auth: true)]
class PostController extends BasePostController
{
    #[NodeAnnotation(ignore: ['hiddenInheritedAction'])]
    protected array $ignoreNode = [];

    #[NodeAnnotation(title: 'Post Index', auth: true)]
    public function index(): View|JsonResponse
    {
        return $this->fetch();
    }
}
