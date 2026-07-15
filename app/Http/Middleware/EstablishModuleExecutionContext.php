<?php

namespace App\Http\Middleware;

use App\Models\SystemModule;
use App\Modules\ModuleExecutionContext;
use App\Modules\ModuleRepository;
use App\Modules\ModuleRuntimeEligibility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class EstablishModuleExecutionContext
{
    public function __construct(
        private readonly ModuleExecutionContext $context,
        private readonly ModuleRepository $modules,
        private readonly ModuleRuntimeEligibility $eligibility,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $module = $request->attributes->get('module_runtime_record');
        if (! $module instanceof SystemModule) {
            $secondary = $request->route('secondary');
            if (is_string($secondary) && $secondary !== '') {
                $candidate = $this->modules->enabledByPrefix($secondary);
                try {
                    $module = $candidate === null ? null : $this->eligibility->assertEligible($candidate);
                } catch (Throwable) {
                    $module = null;
                }
            }
        }

        if (! $module instanceof SystemModule) {
            return $next($request);
        }

        $requestId = (string) ($request->header('X-Request-ID') ?: $request->attributes->get('request_id', ''));

        return $this->context->run($module, $requestId, fn (): Response => $next($request));
    }
}
