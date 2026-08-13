<?php

declare(strict_types=1);

use Liberu\Ecommerce\Promotions\Data\Money;
use Liberu\Ecommerce\Promotions\Exceptions\CurrencyMismatch;

it('parses a decimal string by string arithmetic, not by multiplying a float', function () {
    // The reason this method exists, in one assertion. 19.99 has no exact binary
    // form and the product falls just short, so the cast truncates to 1998.
    expect((int) (19.99 * 100))->toBe(1998)
        ->and(Money::fromDecimalString('19.99', 'GBP')->minor)->toBe(1999);

    // 4.99 is the constant that does NOT bite: (int) (4.99 * 100) is 499. A test
    // written with it fails for the opposite reason to the one it is teaching.
    expect((int) (4.99 * 100))->toBe(499);
});

it('round-trips minor units through a decimal string', function (int $minor, int $exponent, string $decimal) {
    $money = Money::fromMinor($minor, 'GBP', $exponent);

    expect($money->decimal())->toBe($decimal)
        ->and(Money::fromDecimalString($decimal, 'GBP', $exponent)->minor)->toBe($minor);
})->with([
    [1999, 2, '19.99'],
    [5, 2, '0.05'],
    [0, 2, '0.00'],
    [100, 0, '100'],
    [1234567, 3, '1234.567'],
    [-250, 2, '-2.50'],
]);

it('publishes the API money shape with decimal as a string', function () {
    expect(Money::fromMinor(1999, 'gbp')->toArray())->toBe([
        'minor' => 1999,
        'currency' => 'GBP',
        'exponent' => 2,
        'decimal' => '19.99',
    ]);
});

it('accepts a short fraction and refuses one longer than the currency holds', function () {
    expect(Money::fromDecimalString('19.9', 'GBP')->minor)->toBe(1990);

    expect(fn () => Money::fromDecimalString('19.999', 'GBP'))
        ->toThrow(InvalidArgumentException::class, 'more precision');
});

it('refuses a value that is not a decimal amount, and a code that is not a currency', function () {
    expect(fn () => Money::fromDecimalString('nineteen', 'GBP'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::fromMinor(1, 'POUNDS'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::fromMinor(1, 'GBP', 9))->toThrow(InvalidArgumentException::class);
});

it('refuses to add two currencies together', function () {
    expect(fn () => Money::fromMinor(1, 'GBP')->plus(Money::fromMinor(1, 'EUR')))
        ->toThrow(CurrencyMismatch::class);

    expect(Money::fromMinor(100, 'GBP')->plus(Money::fromMinor(23, 'GBP'))->minor)->toBe(123);
});

it('reports zero and carries an error code a transport can render', function () {
    expect(Money::fromMinor(0, 'GBP')->isZero())->toBeTrue()
        ->and(Money::fromMinor(1, 'GBP')->isZero())->toBeFalse();

    $mismatch = CurrencyMismatch::withBasket('EUR', 'GBP');

    expect($mismatch->errorCode())->toBe('promotions.currency_mismatch')
        ->and($mismatch->status())->toBe(422);
});
