<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Shared\Exception\ValidationException;
use Domain\Transcript\ValueObject\TranscriptReleasePolicy;
use Domain\Transcript\ValueObject\TranscriptStandingPresentation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranscriptStandingPresentationTest extends TestCase
{
    public static function malformed(): array
    {
        return [[[]], [['periods' => 'through_cutoff']], [['show_dates' => true]],
            [['periods' => 'latest', 'show_dates' => true]], [['periods' => null, 'show_dates' => true]],
            [['periods' => 'effective_at_cutoff', 'show_dates' => 'false']],
            [['periods' => 'through_cutoff', 'show_dates' => 0]],
            [['periods' => 'through_cutoff', 'show_dates' => true, 'unreviewed' => true]]];
    }

    #[DataProvider('malformed')]
    public function testPresentationRequiresCompleteTypedBoundedChoices(array $values): void
    {
        $this->expectException(ValidationException::class);
        TranscriptStandingPresentation::fromArray($values);
    }

    public static function earlierPolicies(): array
    {
        return [[['revision' => 1, 'checks' => []]], [['revision' => 1, 'checks' => [], 'include_standing' => true]],
            [['revision' => 1, 'checks' => [], 'include_standing' => true, 'standing_definitions' => null]]];
    }

    #[DataProvider('earlierPolicies')]
    public function testEarlierEvidenceKeepsItsOriginalShapeAndOriginalAllPeriodsWithDatesMeaning(array $policy): void
    {
        $actor = '01111111111111111111111111';
        $at = '2026-08-10T12:00:00+00:00';
        $evidence = ['stage' => 'preparation', 'policy' => $policy, 'confirmed' => [],
            'actor_id' => $actor, 'confirmed_at' => $at];
        $before = $evidence;
        TranscriptReleasePolicy::validateEvidence($evidence, 'preparation', $actor, $at);
        self::assertSame($before, $evidence);
        self::assertSame(
            ['periods' => 'through_cutoff', 'show_dates' => true],
            TranscriptReleasePolicy::summary($evidence)['standing_presentation']
        );
    }
}
