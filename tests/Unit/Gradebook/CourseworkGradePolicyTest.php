<?php

declare(strict_types=1);

namespace Tests\Unit\Gradebook;

use Brick\Math\BigRational;
use Domain\Gradebook\ValueObject\CourseworkGradePolicy;
use Domain\Shared\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CourseworkGradePolicyTest extends TestCase
{
    public static function comparisons(): array
    {
        return [
            ["none", null, false, "464997/5000", "464997/5000"],
            ["half_up", 0, false, "185/2", "93"],
            ["half_even", 0, false, "185/2", "92"],
            ["half_even", 0, false, "187/2", "94"],
            ["half_up", 2, false, "464997/5000", "93"],
            ["floor", 3, false, "464997/5000", "92999/1000"],
            ["ceiling", 3, false, "464997/5000", "93"],
            ["floor", 0, false, "100/3", "33"],
            ["ceiling", 0, false, "100/3", "34"],
            ["half_up", 1, false, "100/3", "333/10"],
            ["none", null, true, "110", "100"],
            ["none", null, false, "110", "110"],
            ["ceiling", 0, true, "100001/1000", "100"],
            ["half_up", 0, true, "0", "0"],
        ];
    }

    #[DataProvider("comparisons")]
    public function testExactRationalComparisonAndCapPrecedesRounding(
        string $rounding,
        ?int $places,
        bool $cap,
        string $raw,
        string $expected,
    ): void {
        $policy = new CourseworkGradePolicy([
            "rounding" => $rounding,
            "decimal_places" => $places,
            "cap_at_hundred" => $cap,
        ]);
        self::assertSame($expected, (string) $policy->apply(BigRational::of($raw)));
    }

    public static function invalidPolicies(): array
    {
        return [
            [[]],
            [["rounding" => "none", "decimal_places" => 0, "cap_at_hundred" => false]],
            [["rounding" => "half_up", "decimal_places" => null, "cap_at_hundred" => false]],
            [["rounding" => "half_up", "decimal_places" => 4, "cap_at_hundred" => false]],
            [["rounding" => "half_up", "decimal_places" => "2", "cap_at_hundred" => false]],
            [["rounding" => "half_up", "decimal_places" => -1, "cap_at_hundred" => false]],
            [["rounding" => "half_down", "decimal_places" => 2, "cap_at_hundred" => false]],
            [["rounding" => "none", "decimal_places" => null, "cap_at_hundred" => 1]],
            [CourseworkGradePolicy::DEFAULT + ["extra" => true]],
        ];
    }

    #[DataProvider("invalidPolicies")]
    public function testIncompleteAmbiguousOrUnboundedPoliciesFailClosed(array $facts): void
    {
        $this->expectException(ValidationException::class);
        new CourseworkGradePolicy($facts);
    }
}
