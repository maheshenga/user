<?php

namespace App\Providers;

use App\Contracts\Modules\ActivationCodeGateway;
use App\Contracts\Modules\AffiliateGateway;
use App\Contracts\Modules\AuditGateway;
use App\Contracts\Modules\BalanceGateway;
use App\Contracts\Modules\InvitationGateway;
use App\Contracts\Modules\MemberGateway;
use App\Contracts\Modules\NotificationGateway;
use App\Contracts\Modules\VipGateway;
use App\Modules\Host\HostActivationCodeGateway;
use App\Modules\Host\HostAffiliateGateway;
use App\Modules\Host\HostAuditGateway;
use App\Modules\Host\HostBalanceGateway;
use App\Modules\Host\HostInvitationGateway;
use App\Modules\Host\HostMemberGateway;
use App\Modules\Host\HostNotificationGateway;
use App\Modules\Host\HostVipGateway;
use App\Modules\ModuleServiceProviderRegistrar;
use App\Modules\ModuleExecutionContext;
use App\Modules\ModuleOperationCoordinator;
use App\Modules\ModuleViewRegistrar;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(ModuleExecutionContext::class, fn (): ModuleExecutionContext => new ModuleExecutionContext);
        $this->app->scoped(ModuleOperationCoordinator::class, fn (): ModuleOperationCoordinator => new ModuleOperationCoordinator);
        $this->app->bind(MemberGateway::class, HostMemberGateway::class);
        $this->app->bind(InvitationGateway::class, HostInvitationGateway::class);
        $this->app->bind(VipGateway::class, HostVipGateway::class);
        $this->app->bind(ActivationCodeGateway::class, HostActivationCodeGateway::class);
        $this->app->bind(BalanceGateway::class, HostBalanceGateway::class);
        $this->app->bind(AffiliateGateway::class, HostAffiliateGateway::class);
        $this->app->bind(AuditGateway::class, HostAuditGateway::class);
        $this->app->bind(NotificationGateway::class, HostNotificationGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (class_exists(ModuleServiceProviderRegistrar::class)) {
            app(ModuleServiceProviderRegistrar::class)->registerEnabled();
        }

        if (class_exists(ModuleViewRegistrar::class)) {
            app(ModuleViewRegistrar::class)->registerEnabled();
        }
    }
}
