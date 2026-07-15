<?php

namespace App\User;

use InvalidArgumentException;

final readonly class Money
{
    private const MAX_CENTS = 999_999_999_999;

    private function __construct(private int $cents)
    {
        if (abs($cents) > self::MAX_CENTS) {
            throw new InvalidArgumentException('金额超出系统支持范围。');
        }
    }

    public static function from(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $decimal = self::decimalString($value, 10);
        if (preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $decimal, $matches) !== 1) {
            throw new InvalidArgumentException('金额格式无效。');
        }

        $whole = ltrim($matches[2], '0');
        $whole = $whole === '' ? '0' : $whole;
        if (strlen($whole) > 10 || (strlen($whole) === 10 && strcmp($whole, '9999999999') > 0)) {
            throw new InvalidArgumentException('金额超出系统支持范围。');
        }

        $fraction = $matches[3] ?? '';
        $padded = str_pad($fraction, 3, '0');
        $cents = ((int) $whole * 100) + (int) substr($padded, 0, 2);
        if ((int) $padded[2] >= 5) {
            $cents++;
        }
        if (($matches[1] ?? '') === '-') {
            $cents *= -1;
        }

        return new self($cents);
    }

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    public function add(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function subtract(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    public function compareTo(self $other): int
    {
        return $this->cents <=> $other->cents;
    }

    public function multiplyRate(mixed $rate): self
    {
        $units = self::rateUnits($rate);
        $product = $this->cents * $units;
        $absolute = abs($product);
        $rounded = intdiv($absolute + 5_000, 10_000);

        return new self($product < 0 ? -$rounded : $rounded);
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function absolute(): self
    {
        return new self(abs($this->cents));
    }

    public function toString(): string
    {
        $absolute = abs($this->cents);
        $formatted = intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        return $this->cents < 0 ? '-'.$formatted : $formatted;
    }

    private static function rateUnits(mixed $rate): int
    {
        $decimal = self::decimalString($rate, 8);
        if (preg_match('/^\+?(\d+)(?:\.(\d+))?$/', $decimal, $matches) !== 1) {
            throw new InvalidArgumentException('佣金比例格式无效。');
        }

        $whole = (int) $matches[1];
        $fraction = $matches[2] ?? '';
        $padded = str_pad($fraction, 5, '0');
        $units = ($whole * 10_000) + (int) substr($padded, 0, 4);
        if ((int) $padded[4] >= 5) {
            $units++;
        }
        if ($units < 0 || $units > 10_000) {
            throw new InvalidArgumentException('佣金比例必须在 0 到 1 之间。');
        }

        return $units;
    }

    private static function decimalString(mixed $value, int $floatScale): string
    {
        if (is_int($value) || is_string($value)) {
            $decimal = trim((string) $value);
        } elseif (is_float($value) && is_finite($value)) {
            $decimal = rtrim(rtrim(sprintf("%.{$floatScale}F", $value), '0'), '.');
        } else {
            throw new InvalidArgumentException('金额格式无效。');
        }

        return $decimal;
    }
}
