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

    public static function invalidOverrides(): array
    {
        $base = ['enabled' => true, 'minimum_gpa_credits_milli' => 0, 'bands' => [[
            'minimum_gpa_milli' => 0, 'definition_id' => '01111111111111111111111111',
            'code' => 'REVIEW', 'title' => 'Fictional review',
        ]]];
        $override = ['kind' => 'program', 'target' => ['id' => '01111111111111111111111112',
            'code' => 'ARTS.UG', 'title' => 'Fictional program'], 'rules' => $base];
        $nested = $override;
        $nested['rules']['overrides'] = [];
        $invalidTarget = $override;
        $invalidTarget['target']['id'] = 'invalid';
        $invalidSet = $override;
        $invalidSet['rules']['minimum_gpa_credits_milli'] = '3000';
        return [[null], ['program'], [['program' => $override]], [array_fill(0, 21, $override)],
            [[$override, $override]], [[$override + ['enabled' => true]]],
            [[array_replace($override, ['kind' => 'department'])]], [[$nested]], [[$invalidTarget]], [[$invalidSet]]];
    }

    #[DataProvider('invalidOverrides')]
    public function testOverridesRejectUnboundedDuplicateNestedOrUntypedRules(mixed $overrides): void
    {
        $rules = StandingProposalRules::DEFAULT;
        $rules['overrides'] = $overrides;
        $this->expectException(ValidationException::class);
        new StandingProposalRules($rules);
    }

    public function testProgramTargetsSupportTheFullProgramCodeContractAndScopeSerializationIsRedacted(): void
    {
        $program = '01111111111111111111111112';
        StandingProposalRules::target('program', [
            'id' => $program, 'code' => str_repeat('A', 80), 'title' => 'Fictional program',
        ]);
        $scope = new \Domain\Student\ValueObject\StandingProposalScope([
            'program_id' => $program, 'pursuit_version' => 0, 'level_source' => null]);
        self::assertStringNotContainsString($program, json_encode($scope, JSON_THROW_ON_ERROR));
        self::assertNull($scope->levelId());
    }

    public static function malformedScopes(): array
    {
        $base = ['program_id' => '01111111111111111111111112', 'pursuit_version' => 0, 'level_source' => null];
        $source = ['academic_year_id' => '01111111111111111111111113',
            'program_version_id' => '01111111111111111111111114', 'academic_level' => null];
        return [[$base + ['academic_level' => null]], [array_replace($base, ['pursuit_version' => -1])],
            [array_replace($base, ['pursuit_version' => '1'])], [array_replace($base, ['pursuit_version' => 10001])],
            [array_replace($base, ['level_source' => $source])],
            [array_replace($base, ['pursuit_version' => 1, 'level_source' => $source + ['inferred' => true]])]];
    }

    #[DataProvider('malformedScopes')]
    public function testScopeEvidenceRejectsIncompleteOrInventedSourceContext(array $scope): void
    {
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        new \Domain\Student\ValueObject\StandingProposalScope($scope);
    }
}
