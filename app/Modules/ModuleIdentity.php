<?php

namespace App\Modules;

final readonly class ModuleIdentity
{
    /**
     * @param  list<string>  $capabilities
     */
    public function __construct(
        public string $name,
        public ?int $releaseId,
        public string $trustLevel,
        public array $capabilities,
        public string $requestId,
        private bool $host = false,
    ) {}

    public function isHost(): bool
    {
        return $this->host;
    }

    public function can(string $capability): bool
    {
        return $this->host || in_array($capability, $this->capabilities, true);
    }
}
