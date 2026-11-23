<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Catalog\ValueObject\CreditPolicy;
use Domain\Shared\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreditPolicyTest extends TestCase
{
    public function testRangeChecksUseExactThousandthsWithoutBuildingAnOptionList(): void
    {
        $policy = new CreditPolicy(1000, 6000, 500);
        foreach ([1000, 2500, 6000] as $choice) {
            $policy->requireChoice($choice);
        }
        self::assertEquals($policy, CreditPolicy::stored(json_encode($policy->describe(), JSON_THROW_ON_ERROR)));
        CreditPolicy::fixed(0)->requireChoice(0);
        new CreditPolicy(0, 999999, 1)->requireChoice(999999);
        $this->expectException(ValidationException::class);
        $policy->requireChoice(2501);
    }

    public static function invalid(): array
    {
        return [[-1, 3000, 1], [3000, 1000, 500], [0, 1000000, 1], [3000, 3000, 1],
            [1000, 6000, 0], [1000, 6000, -1], [1000, 6000, 750]];
    }

    #[DataProvider('invalid')]
    public function testMalformedBoundsAndIncrementsAreRejected(int $min, int $max, int $step): void
    {
        $this->expectException(ValidationException::class);
        new CreditPolicy($min, $max, $step);
    }
}
