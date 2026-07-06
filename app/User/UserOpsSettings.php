<?php

namespace App\User;

use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

final class UserOpsSettings
{
    public const GROUP = 'user_ops';

    public const DEFAULTS = [
        'invite_default_max_uses' => '0',
        'invite_default_expires_days' => '0',
        'password_reset_expires_minutes' => '30',
        'risk_invite_burst_threshold' => '5',
        'risk_invite_burst_window_hours' => '24',
        'risk_activation_failure_threshold' => '5',
        'risk_activation_failure_window_minutes' => '10',
        'withdrawal_min_amount' => '0.01',
        'withdrawal_max_amount' => '0.00',
    ];

    private const LABELS = [
        'invite_default_max_uses' => '默认邀请码可用次数',
        'invite_default_expires_days' => '默认邀请码有效天数',
        'password_reset_expires_minutes' => '找回密码有效分钟数',
        'risk_invite_burst_threshold' => '邀请集中注册阈值',
        'risk_invite_burst_window_hours' => '邀请集中注册窗口小时数',
        'risk_activation_failure_threshold' => '激活码失败阈值',
        'risk_activation_failure_window_minutes' => '激活码失败窗口分钟数',
        'withdrawal_min_amount' => '最小提现金额',
        'withdrawal_max_amount' => '最大提现金额',
    ];

    public function inviteDefaultMaxUses(): int
    {
        return $this->intValue('invite_default_max_uses', 0, 1_000_000);
    }

    public function inviteDefaultExpiresDays(): int
    {
        return $this->intValue('invite_default_expires_days', 0, 3650);
    }

    public function passwordResetExpiresMinutes(): int
    {
        return $this->intValue('password_reset_expires_minutes', 1, 1440);
    }

    public function riskInviteBurstThreshold(): int
    {
        return $this->intValue('risk_invite_burst_threshold', 1, 1000);
    }

    public function riskInviteBurstWindowHours(): int
    {
        return $this->intValue('risk_invite_burst_window_hours', 1, 720);
    }

    public function riskActivationFailureThreshold(): int
    {
        return $this->intValue('risk_activation_failure_threshold', 1, 1000);
    }

    public function riskActivationFailureWindowMinutes(): int
    {
        return $this->intValue('risk_activation_failure_window_minutes', 1, 1440);
    }

    public function withdrawalMinAmount(): string
    {
        return $this->moneyValue('withdrawal_min_amount');
    }

    public function withdrawalMaxAmount(): string
    {
        return $this->moneyValue('withdrawal_max_amount');
    }

    /**
     * @return array<string, int|string>
     */
    public function publicSettings(): array
    {
        return [
            'invite_default_max_uses' => $this->inviteDefaultMaxUses(),
            'invite_default_expires_days' => $this->inviteDefaultExpiresDays(),
            'password_reset_expires_minutes' => $this->passwordResetExpiresMinutes(),
            'risk_invite_burst_threshold' => $this->riskInviteBurstThreshold(),
            'risk_invite_burst_window_hours' => $this->riskInviteBurstWindowHours(),
            'risk_activation_failure_threshold' => $this->riskActivationFailureThreshold(),
            'risk_activation_failure_window_minutes' => $this->riskActivationFailureWindowMinutes(),
            'withdrawal_min_amount' => $this->withdrawalMinAmount(),
            'withdrawal_max_amount' => $this->withdrawalMaxAmount(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    public function validateForSave(array $payload): array
    {
        $values = [];

        foreach (array_keys(self::DEFAULTS) as $key) {
            if (array_key_exists($key, $payload)) {
                $values[$key] = (string) $payload[$key];
            }
        }

        $validated = [
            'invite_default_max_uses' => (string) $this->validateInt($values, 'invite_default_max_uses', 0, 1_000_000),
            'invite_default_expires_days' => (string) $this->validateInt($values, 'invite_default_expires_days', 0, 3650),
            'password_reset_expires_minutes' => (string) $this->validateInt($values, 'password_reset_expires_minutes', 1, 1440),
            'risk_invite_burst_threshold' => (string) $this->validateInt($values, 'risk_invite_burst_threshold', 1, 1000),
            'risk_invite_burst_window_hours' => (string) $this->validateInt($values, 'risk_invite_burst_window_hours', 1, 720),
            'risk_activation_failure_threshold' => (string) $this->validateInt($values, 'risk_activation_failure_threshold', 1, 1000),
            'risk_activation_failure_window_minutes' => (string) $this->validateInt($values, 'risk_activation_failure_window_minutes', 1, 1440),
            'withdrawal_min_amount' => $this->validateMoney($values, 'withdrawal_min_amount'),
            'withdrawal_max_amount' => $this->validateMoney($values, 'withdrawal_max_amount'),
        ];

        if (
            $this->compareMoney($validated['withdrawal_max_amount'], '0.00') > 0
            && $this->compareMoney($validated['withdrawal_max_amount'], $validated['withdrawal_min_amount']) < 0
        ) {
            throw new InvalidArgumentException('最大提现金额必须为 0，或大于等于最小提现金额。');
        }

        return $validated;
    }

    private function stringValue(string $key): string
    {
        try {
            if (! Schema::hasTable('system_config')) {
                return self::DEFAULTS[$key];
            }

            $value = sysconfig(self::GROUP, $key);
        } catch (Throwable) {
            return self::DEFAULTS[$key];
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : self::DEFAULTS[$key];
    }

    private function intValue(string $key, int $min, int $max): int
    {
        $value = filter_var($this->stringValue($key), FILTER_VALIDATE_INT);

        if ($value === false) {
            $value = (int) self::DEFAULTS[$key];
        }

        return max($min, min($max, (int) $value));
    }

    private function moneyValue(string $key): string
    {
        return $this->money($this->stringValue($key));
    }

    /**
     * @param array<string, string> $values
     */
    private function validateInt(array $values, string $key, int $min, int $max): int
    {
        $value = $values[$key] ?? self::DEFAULTS[$key];

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException("{$this->label($key)}必须是整数。");
        }

        $int = (int) $value;

        if ($int < $min || $int > $max) {
            throw new InvalidArgumentException("{$this->label($key)}必须在 {$min} 到 {$max} 之间。");
        }

        return $int;
    }

    /**
     * @param array<string, string> $values
     */
    private function validateMoney(array $values, string $key): string
    {
        $value = $values[$key] ?? self::DEFAULTS[$key];

        if (! is_numeric($value) || (float) $value < 0) {
            throw new InvalidArgumentException("{$this->label($key)}必须是非负金额。");
        }

        return $this->money($value);
    }

    private function money(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    private function compareMoney(string $left, string $right): int
    {
        return ((int) round(((float) $left) * 100)) <=> ((int) round(((float) $right) * 100));
    }

    private function label(string $key): string
    {
        return self::LABELS[$key] ?? $key;
    }
}
