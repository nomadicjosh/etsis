<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Finance\ValueObject\Money;
use Domain\Finance\ValueObject\WithdrawalCreditBasis;
use Domain\Registration\ValueObject\DropWindows;
use Domain\Shared\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class WithdrawalCreditCalculationTest extends TestCase
{
    public function testExactHalfUpRoundingAndPortableAmountLimit(): void
    {
        foreach (
            [[1, 5000, 1], [1, 4999, 0], [10001, 5000, 5001], [10000, 1234, 1234],
            [Money::MAX_MINOR, 10000, Money::MAX_MINOR], [10000, 0, 0]] as [$minor, $rate, $expected]
        ) {
            self::assertSame($expected, WithdrawalCreditBasis::target($minor, $rate));
        }
    }

    public function testEarlierAbsentRatesRemainAbsentAndDateBoundariesAreInclusive(): void
    {
        $old = new DropWindows([['through' => null, 'grade_code' => 'W']]);
        self::assertSame([['through' => null, 'grade_code' => 'W']], $old->windows);
        self::assertNull($old->creditOn('2026-09-01'));
        $windows = new DropWindows([
            ['through' => '2026-09-01', 'grade_code' => 'W', 'credit_basis_points' => 5000],
            ['through' => null, 'grade_code' => 'W', 'credit_basis_points' => 0],
        ]);
        self::assertSame(5000, $windows->creditOn('2026-09-01'));
        self::assertSame(0, $windows->creditOn('2026-09-02'));
    }

    public function testRatesRequireAnIntegerWithinZeroToOneHundredPercent(): void
    {
        foreach ([-1, 10001, '5000', 50.5, null] as $rate) {
            try {
                new DropWindows([['through' => null, 'grade_code' => 'W', 'credit_basis_points' => $rate]]);
                self::fail('An invalid percentage was accepted.');
            } catch (ValidationException $e) {
                self::assertSame('windows', $e->errors[0]['field']);
            }
        }
    }
}
