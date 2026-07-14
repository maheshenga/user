<?php

namespace App\Modules;

use RuntimeException;

final class ModuleApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus,
        private readonly string $errorCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
