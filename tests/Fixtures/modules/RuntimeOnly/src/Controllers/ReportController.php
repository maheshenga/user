<?php

namespace Modules\RuntimeOnly\Controllers;

use App\Http\Controllers\common\Controller;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\MiddlewareAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use Illuminate\Http\Response;

#[ControllerAnnotation(title: 'Runtime Reports', auth: true)]
class ReportController extends Controller
{
    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[NodeAnnotation(title: 'Runtime Index', auth: true)]
    public function index(): Response
    {
        return response('runtime-only-index');
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    #[NodeAnnotation(title: 'Runtime Action', auth: true)]
    public function actionName(): Response
    {
        return response(currentAdminAction());
    }
}
