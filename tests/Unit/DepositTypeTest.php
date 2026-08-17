<?php

use App\Enums\DepositType;

it('charges nothing when no deposit is configured', function () {
    expect(DepositType::None->resolveAmount(5000, 20000))->toBe(0);
});

it('charges a fixed amount verbatim', function () {
    expect(DepositType::Fixed->resolveAmount(2500, 20000))->toBe(2500);
});

it('charges a percentage of the price', function () {
    expect(DepositType::Percentage->resolveAmount(25, 12000))->toBe(3000);
});

it('rounds a percentage to whole minor units', function () {
    // 33% of 10.01 is 3.3033, which has to land on a real number of cents.
    expect(DepositType::Percentage->resolveAmount(33, 1001))->toBe(330);
});

it('never asks for more than the service costs', function () {
    expect(DepositType::Fixed->resolveAmount(50000, 9000))->toBe(9000)
        ->and(DepositType::Percentage->resolveAmount(150, 9000))->toBe(9000);
});

it('never returns a negative deposit', function () {
    expect(DepositType::Fixed->resolveAmount(2500, 0))->toBe(0);
});
