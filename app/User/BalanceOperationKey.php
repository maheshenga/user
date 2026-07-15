<?php

namespace App\User;

use InvalidArgumentException;
use JsonException;

final class BalanceOperationKey
{
    /**
     * @throws JsonException
     */
    public static function make(
        int $userId,
        string $direction,
        string $type,
        ?string $sourceType,
        ?int $sourceId
    ): ?string {
        if ($sourceType === null && $sourceId === null) {
            return null;
        }

        $sourceType = trim((string) $sourceType);
        if ($userId <= 0 || $sourceType === '' || $sourceId === null || $sourceId <= 0) {
            throw new InvalidArgumentException('余额业务来源无效。');
        }

        return hash('sha256', json_encode(
            [$userId, $direction, $type, $sourceType, $sourceId],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }
}
