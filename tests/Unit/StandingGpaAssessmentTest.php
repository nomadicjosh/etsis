<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ValidationException;
use Domain\Student\ValueObject\StandingGpaAssessment;
use Domain\Student\ValueObject\StandingProposalRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StandingGpaAssessmentTest extends TestCase
{
    public static function malformed(): array
    {
        $term = ['id' => '01111111111111111111111111', 'code' => '26/FA'];
        return [[[]], [['basis' => 'year', 'term' => null]], [['basis' => 'term', 'term' => null]],
            [['basis' => 'cumulative', 'term' => $term]], [['basis' => 'term', 'term' => ['code' => '26/FA']]],
            [['basis' => 'term', 'term' => array_replace($term, ['id' => 'invalid'])]],
            [['basis' => 'term', 'term' => array_replace($term, ['code' => str_repeat('A', 81)])]],
            [['basis' => 'term', 'term' => $term, 'cutoff' => '2026-01-01']]];
    }

    #[DataProvider('malformed')]
    public function testAssessmentRejectsUnrecordedBasisAndAmbiguousTermReferences(array $data): void
    {
        $this->expectException(CorruptHistoryException::class);
        new StandingGpaAssessment($data);
    }

    public function testEarlierRuleSetsRemainCumulativeAndAssessmentSerializationIsRedacted(): void
    {
        $old = ['enabled' => false, 'bands' => [], 'minimum_gpa_credits_milli' => 0];
        $rules = new StandingProposalRules($old);
        self::assertSame($old, $rules->facts);
        self::assertSame('cumulative', $rules->gpaBasis());
        $id = '01111111111111111111111111';
        $assessment = new StandingGpaAssessment(['basis' => 'term', 'term' => ['id' => $id, 'code' => '26/FA']]);
        self::assertSame(['basis' => 'term', 'term_code' => '26/FA'], $assessment->summary());
        self::assertStringNotContainsString($id, json_encode($assessment, JSON_THROW_ON_ERROR));
    }

    public static function invalidBasis(): array
    {
        return [[null], [true], ['year'], [['term']]];
    }

    #[DataProvider('invalidBasis')]
    public function testPolicyBasisIsAnExplicitSupportedChoice(mixed $basis): void
    {
        $this->expectException(ValidationException::class);
        new StandingProposalRules(array_replace(StandingProposalRules::DEFAULT, ['gpa_basis' => $basis]));
    }
}
