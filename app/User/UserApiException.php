<?php

namespace App\User;

use RuntimeException;

final class UserApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus,
        private readonly string $apiErrorCode,
    ) {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): string
    {
        return $this->apiErrorCode;
    }
}
