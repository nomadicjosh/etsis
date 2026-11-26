<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Shared\Exception\ValidationException;
use Domain\Student\ValueObject\StandingLoadRequirement;
use Domain\Student\ValueObject\StandingProposalRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StandingLoadRequirementTest extends TestCase
{
    public static function invalidRequirements(): array
    {
        $valid = ['minimum_credits_milli' => 0, 'maximum_credits_milli' => null, 'classification_code' => null];
        return array_map(static fn(array $changes): array => [array_replace($valid, $changes)], [
            ['minimum_credits_milli' => -1], ['minimum_credits_milli' => '3000'],
            ['minimum_credits_milli' => 1000000], ['maximum_credits_milli' => -1],
            ['maximum_credits_milli' => 1000000], ['maximum_credits_milli' => '12000'],
            ['minimum_credits_milli' => 3000, 'maximum_credits_milli' => 2999],
            ['classification_code' => 'full'], ['classification_code' => ''],
            ['classification_code' => str_repeat('A', 33)], ['classification_code' => "FULL\n"],
            ['unknown' => true],
        ]);
    }

    #[DataProvider('invalidRequirements')]
    public function testInvalidPolicyRequirementsAreValidationErrors(array $facts): void
    {
        $this->expectException(ValidationException::class);
        new StandingLoadRequirement($facts);
    }

    public function testNullPolicyRequirementIsNotAnImplicitRemoval(): void
    {
        $this->expectException(ValidationException::class);
        new StandingProposalRules(['enabled' => false, 'bands' => [], 'minimum_gpa_credits_milli' => 0,
            'load_requirement' => null]);
    }
}
