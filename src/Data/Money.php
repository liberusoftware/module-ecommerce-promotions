<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Data;

use InvalidArgumentException;
use Liberu\Ecommerce\Promotions\Exceptions\CurrencyMismatch;

/**
 * Integer minor units, a currency and an exponent, kept together.
 *
 * Settled since wave 3 and never re-decided. The host is what breaking it looks
 * like: `Coupon::$value` is cast `float`, `CouponService` takes and returns
 * `float` throughout, and `Discount::$value` is cast `decimal:2` — which in
 * Laravel hands back a *string*, which then goes into `min($this->value,
 * $subtotal)` against a float.
 */
final readonly class Money
{
    private function __construct(
        public int $minor,
        public string $currency,
        public int $exponent,
    ) {}

    public static function fromMinor(int $minor, string $currency, int $exponent = 2): self
    {
        $currency = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("[{$currency}] is not a three-letter currency code.");
        }

        if ($exponent < 0 || $exponent > 4) {
            throw new InvalidArgumentException("A currency exponent of [{$exponent}] is not usable.");
        }

        return new self($minor, $currency, $exponent);
    }

    /**
     * Parse a decimal string to minor units by **string arithmetic**.
     *
     * `(int) (19.99 * 100)` is 1998, because 19.99 has no exact binary form and
     * the product falls just short of 1999. The constant matters: `(int) (4.99 *
     * 100)` is 499, so a test written with that value fails for the opposite
     * reason to the one it is teaching. See tests/Unit/MoneyTest.php.
     */
    public static function fromDecimalString(string $amount, string $currency, int $exponent = 2): self
    {
        $amount = trim($amount);

        if (preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', $amount, $matches) !== 1) {
            throw new InvalidArgumentException("[{$amount}] is not a decimal amount.");
        }

        [, $sign, $whole, $fraction] = $matches + [3 => ''];

        if (strlen($fraction) > $exponent) {
            throw new InvalidArgumentException("[{$amount}] carries more precision than a {$exponent}-place currency holds.");
        }

        $minor = (int) ($sign.$whole.str_pad($fraction, $exponent, '0'));

        return self::fromMinor($minor, $currency, $exponent);
    }

    public function decimal(): string
    {
        $sign = $this->minor < 0 ? '-' : '';
        $digits = str_pad((string) abs($this->minor), $this->exponent + 1, '0', STR_PAD_LEFT);

        if ($this->exponent === 0) {
            return $sign.$digits;
        }

        return $sign.substr($digits, 0, -$this->exponent).'.'.substr($digits, -$this->exponent);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency, $this->exponent);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency || $this->exponent !== $other->exponent) {
            throw CurrencyMismatch::between($this, $other);
        }
    }

    /**
     * The published API shape. `decimal` is a **string**: handing a consumer a
     * JSON number puts the value back through a float on the way out.
     *
     * @return array{minor: int, currency: string, exponent: int, decimal: string}
     */
    public function toArray(): array
    {
        return [
            'minor' => $this->minor,
            'currency' => $this->currency,
            'exponent' => $this->exponent,
            'decimal' => $this->decimal(),
        ];
    }
}
