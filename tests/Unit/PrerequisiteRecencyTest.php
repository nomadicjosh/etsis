<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Catalog\ValueObject\PrerequisiteCompletionWindow;
use Domain\Catalog\ValueObject\PrerequisiteGroups;
use Domain\Registration\ValueObject\PrerequisiteDecision;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrerequisiteRecencyTest extends TestCase
{
    public static function calendarWindows(): array
    {
        return [['2026-08-31', 60, '2021-08-31'], ['2024-03-31', 1, '2024-02-29'],
            ['2025-03-31', 1, '2025-02-28'], ['2024-02-29', 12, '2023-02-28'],
            ['2026-01-31', 1, '2025-12-31'], ['0001-01-01', 1200, '0001-01-01']];
    }

    #[DataProvider('calendarWindows')]
    public function testActualCompletionFallsWithinInclusiveClampedCalendarMonths(
        string $start,
        int $months,
        string $from,
    ): void {
        $rule = new PrerequisiteCompletionWindow(['from' => null, 'through' => null, 'max_age_months' => $months]);
        self::assertSame($from, $rule->earliestCompletion($start));
        self::assertTrue($rule->accepts($from, $start));
        self::assertTrue($rule->accepts($start, $start));
        self::assertFalse($rule->accepts(null, $start));
        self::assertFalse($rule->accepts($from));
        if ($from !== '0001-01-01') {
            self::assertFalse($rule->accepts(new \DateTimeImmutable($from)->modify('-1 day')->format('Y-m-d'), $start));
        }
        self::assertFalse($rule->accepts(new \DateTimeImmutable($start)->modify('+1 day')->format('Y-m-d'), $start));
    }

    public static function invalidAges(): array
    {
        return [[null], [0], [-1], [1201], [60.5], ['60'], [true], [[]]];
    }

    #[DataProvider('invalidAges')]
    public function testAgeIsAnExplicitBoundedWholeNumber(mixed $age): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PrerequisiteCompletionWindow(['from' => null, 'through' => null, 'max_age_months' => $age]);
    }

    public function testFixedDatesFurtherRestrictTheRollingWindow(): void
    {
        $window = new PrerequisiteCompletionWindow([
            'from' => '2022-01-01', 'through' => '2025-12-31', 'max_age_months' => 60,
        ]);
        self::assertFalse($window->accepts('2021-09-01', '2026-08-31'));
        self::assertFalse($window->accepts('2026-01-01', '2026-08-31'));
        self::assertTrue($window->accepts('2022-01-01', '2026-08-31'));
        self::assertTrue($window->accepts('2025-12-31', '2026-08-31'));
    }

    private function decision(int $recentPoints = 3000): PrerequisiteDecision
    {
        $course = EntityId::generate()->value;
        $groups = new PrerequisiteGroups(
            [[$course], [$course]],
            minimumGradePointsMilli: [4000, 3000],
            completionWindows: [null, ['from' => null, 'through' => null, 'max_age_months' => 60]]
        );
        $support = [];
        foreach (['2020-01-01' => 4000, '2025-06-01' => $recentPoints] as $on => $points) {
            $support[] = ['course_id' => $course, 'credit_id' => EntityId::generate()->value,
                'origin' => 'institutional', 'source_id' => EntityId::generate()->value,
                'academic_version' => 2, 'registration_version' => 1, 'pursuit_version' => 1,
                'grade_points_milli' => $points, 'completed_on' => $on];
        }
        return new PrerequisiteDecision(
            EntityId::generate(),
            [$course],
            $support,
            $groups,
            completionAnchor: ['section_version_id' => EntityId::generate()->value, 'starts_on' => '2026-08-31']
        );
    }

    public function testOneAttemptMustMeetItsGradeAndRecencyTogetherAndOriginalEvidenceRoundTrips(): void
    {
        self::assertFalse($this->decision(2000)->isMet());
        $decision = $this->decision();
        self::assertTrue($decision->isMet());
        self::assertSame($decision->retained(), PrerequisiteDecision::fromRetained($decision->retained())->retained());
        self::assertSame(
            ['starts_on' => '2026-08-31', 'earliest_completion_on' => '2021-08-31'],
            $decision->groupStatuses()[1]['completion_anchor']
        );
        self::assertStringNotContainsString('section_version_id', json_encode($decision->groupStatuses()));
    }

    public static function damagedAnchors(): array
    {
        return [['missing'], ['malformed'], ['version'], ['extra'], ['too_late'], ['unneeded']];
    }

    #[DataProvider('damagedAnchors')]
    public function testRetainedRecencyCannotLoseOrInventItsContext(string $damage): void
    {
        $facts = $this->decision()->retained();
        if ($damage === 'missing') {
            unset($facts['completion_anchor']);
        } elseif ($damage === 'malformed') {
            $facts['completion_anchor']['starts_on'] = '2026-02-29';
        } elseif ($damage === 'version') {
            $facts['completion_anchor']['section_version_id'] = 'not-a-version';
        } elseif ($damage === 'extra') {
            $facts['completion_anchor']['extra'] = true;
        } elseif ($damage === 'too_late') {
            $facts['completion_anchor']['starts_on'] = '2035-08-31';
        } else {
            unset($facts['completion_windows'][1]['max_age_months']);
            $facts['completion_windows'][1]['from'] = '2021-01-01';
        }
        $this->expectException(CorruptHistoryException::class);
        PrerequisiteDecision::fromRetained($facts);
    }

    public function testUnknownStartDoesNotDisableAnUnrestrictedAlternative(): void
    {
        $course = EntityId::generate()->value;
        $groups = new PrerequisiteGroups(
            [[$course], [$course]],
            minimumRequirements: 1,
            completionWindows: [null, ['from' => null, 'through' => null, 'max_age_months' => 60]]
        );
        $support = $this->decision()->support[0];
        unset($support['grade_points_milli']);
        $support['course_id'] = $course;
        $decision = new PrerequisiteDecision(EntityId::generate(), [$course], [$support], $groups);
        self::assertSame([true, false], array_column($decision->groupStatuses(), 'met'));
        self::assertNull($decision->groupStatuses()[1]['completion_anchor']);
        self::assertTrue(PrerequisiteDecision::fromRetained($decision->retained())->isMet());
        self::assertNull($decision->retained()['completion_anchor']);
    }
}
