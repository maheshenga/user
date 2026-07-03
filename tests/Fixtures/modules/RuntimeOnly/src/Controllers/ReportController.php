<?php

namespace Modules\RuntimeOnly\Controllers;

use App\Http\Controllers\common\Controller;
use App\Http\Services\annotation\ControllerAnnotation;
use App\Http\Services\annotation\MiddlewareAnnotation;
use App\Http\Services\annotation\NodeAnnotation;
use Illuminate\Support\Facades\DB;
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

    public function protectedAction(): Response
    {
        $this->incrementCounter('protected_hits');

        return response('protected-action-executed');
    }

    #[MiddlewareAnnotation(ignore: MiddlewareAnnotation::IGNORE_LOGIN)]
    public function ignoreLoginCounter(): Response
    {
        $this->incrementCounter('ignore_hits');

        return response('ignore-login-counter');
    }

    private function incrementCounter(string $name): void
    {
        $current = (int) DB::table('system_config')
            ->where('group', 'module_test')
            ->where('name', $name)
            ->value('value');

        DB::table('system_config')
            ->where('group', 'module_test')
            ->where('name', $name)
            ->update(['value' => (string) ($current + 1)]);
    }
}
