<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Shared\Exception\ValidationException;
use Domain\Student\ValueObject\StandingProposalRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StandingProposalRulesTest extends TestCase
{
    public static function invalidRules(): array
    {
        $band = ['minimum_gpa_milli' => 0, 'definition_id' => '01111111111111111111111111',
            'code' => 'REVIEW', 'title' => 'Fictional review'];
        $rules = StandingProposalRules::DEFAULT;
        $rules['enabled'] = true;
        $rules['bands'] = [$band];
        return [
            [array_replace($rules, ['enabled' => 'true'])],
            [array_replace($rules, ['minimum_gpa_credits_milli' => -1])],
            [array_replace($rules, ['minimum_gpa_credits_milli' => '3000'])],
            [array_replace($rules, ['bands' => []])],
            [array_replace($rules, ['bands' => [$band + ['unreviewed_rule' => true]]])],
            [array_replace($rules, ['bands' => [$band, $band]])],
            [array_replace($rules, ['bands' => [array_replace($band, ['minimum_gpa_milli' => 2000])]])],
            [array_replace($rules, ['bands' => [array_replace($band, ['minimum_gpa_milli' => '0'])]])],
            [array_replace($rules, ['bands' => [$band, array_replace($band, ['minimum_gpa_milli' => 10001])]])],
            [array_replace($rules, ['bands' => [array_replace($band, ['definition_id' => 'invalid'])]])],
        ];
    }

    #[DataProvider('invalidRules')]
    public function testInstitutionalRulesRejectCoercionAmbiguousFloorsAndUnreviewedInputs(array $rules): void
    {
        $this->expectException(ValidationException::class);
        new StandingProposalRules($rules);
    }

    public function testNoDefaultStandingIsInventedAndHighestInclusiveFloorApplies(): void
    {
        self::assertFalse(new StandingProposalRules(StandingProposalRules::DEFAULT)->facts['enabled']);
        $band = ['definition_id' => '01111111111111111111111111', 'code' => 'REVIEW', 'title' => 'Fictional review'];
        $rules = new StandingProposalRules(['enabled' => true, 'minimum_gpa_credits_milli' => 0, 'bands' => [
            ['minimum_gpa_milli' => 0] + $band, ['minimum_gpa_milli' => 2000] + $band,
            ['minimum_gpa_milli' => 3500] + $band,
        ]]);
        self::assertSame(0, $rules->band(1999)['minimum_gpa_milli']);
        self::assertSame(2000, $rules->band(2000)['minimum_gpa_milli']);
        self::assertSame(3500, $rules->band(10000)['minimum_gpa_milli']);
    }
}
