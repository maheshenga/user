<?php

namespace Modules\QingyuIpAgent\Providers;

use Illuminate\Support\ServiceProvider;

class QingyuIpAgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'qingyu_ip_agent.php',
            'qingyu_ip_agent'
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(
            dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'api.php'
        );
    }
}
