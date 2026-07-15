<?php

namespace App\Contracts\Modules;

use App\Modules\ModuleIdentity;

interface ModuleWorkerClient
{
    /**
     * @return array<string, mixed>
     */
    public function health(): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function invoke(ModuleIdentity $identity, string $operation, array $payload, string $requestId): array;
}
