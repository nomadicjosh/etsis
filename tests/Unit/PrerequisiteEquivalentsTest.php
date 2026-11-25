<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Catalog\ValueObject\PrerequisiteGroups;
use Domain\Registration\ValueObject\PrerequisiteDecision;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrerequisiteEquivalentsTest extends TestCase
{
    private static function id(int $n): string
    {
        return str_pad((string) $n, 26, '0', STR_PAD_LEFT);
    }

    public function testDistinctCourseSlotsUseMaximumMatchingAndDoNotChainOrReverse(): void
    {
        [$a, $b, $c, $d] = array_map(self::id(...), [1, 2, 3, 4]);
        $rules = new PrerequisiteGroups([[$a, $b]], [2], equivalents: [$a => [$c], $b => [$c]]);
        self::assertSame(1, $rules->missing([$c]));
        self::assertSame(0, $rules->missing([$a, $c]));
        self::assertSame(0, $rules->missing([$b, $c]));
        self::assertSame(0, $rules->missing([$a, $b]));
        self::assertSame(1, $rules->missing([$c, $c]));
        $chain = new PrerequisiteGroups([[$a], [$c]], equivalents: [$a => [$c], $c => [$d]]);
        self::assertSame(1, $chain->missing([$d]));
        self::assertSame(1, $chain->missing([$a]));
        self::assertSame(0, $chain->missing([$c, $d]));
        $shared = new PrerequisiteGroups([[$a], [$b]], equivalents: [$a => [$c], $b => [$c]]);
        self::assertSame(0, $shared->missing([$c]));
        self::assertSame([$a, $b, $c], $rules->candidateCourses());
    }

    public function testEquivalentCompletionStillRequiresTheSameAttemptSourceDateAndGrade(): void
    {
        [$a, $c] = array_map(self::id(...), [1, 3]);
        $groups = new PrerequisiteGroups(
            [[$a], [$a]],
            minimumGradePointsMilli: [3000, 4000],
            requirementCreditOrigins: ['transfer', 'transfer'],
            completionWindows: [['from' => '2025-01-01', 'through' => '2025-12-31'],
                ['from' => '2024-01-01', 'through' => '2024-12-31']],
            equivalents: [$a => [$c]],
        );
        $ref = static fn(int $id, string $origin, string $date, int $points): array => [
            'course_id' => $c, 'credit_id' => self::id($id), 'source_id' => self::id($id + 10),
            'origin' => $origin, 'completed_on' => $date, 'grade_points_milli' => $points,
            'academic_version' => 1, 'pursuit_version' => 1,
            'registration_version' => $origin === 'transfer' ? null : 1,
        ];
        $weak = $ref(4, 'transfer', '2025-01-01', 2000);
        $old = $ref(5, 'transfer', '2024-12-31', 4000);
        $decision = new PrerequisiteDecision(
            new EntityId(self::id(20)),
            [$a],
            [$old, $weak],
            $groups
        );
        self::assertFalse($decision->isMet());
        self::assertFalse($decision->groupStatuses()[0]['met']);
        $strong = $ref(4, 'transfer', '2025-01-01', 3000);
        $decision = new PrerequisiteDecision(new EntityId(self::id(20)), [$a], [$old, $strong], $groups);
        self::assertTrue($decision->isMet());
        self::assertSame(['met'], $decision->courseStatuses());
        self::assertSame(
            $decision->retained(),
            PrerequisiteDecision::fromRetained($decision->retained())->retained(),
        );
        $this->expectException(CorruptHistoryException::class);
        new PrerequisiteDecision(
            new EntityId(self::id(20)),
            [$a],
            [$ref(6, 'institutional', '2025-01-01', 4000)],
            $groups,
        );
    }

    public static function invalid(): array
    {
        [$a, $b, $c] = array_map(self::id(...), [1, 2, 3]);
        return ['unreferenced target' => [[$b => [$c]]], 'self' => [[$a => [$a]]],
            'duplicates' => [[$a => [$c, $c]]], 'empty choices' => [[$a => []]],
            'non-list choices' => [[$a => ['choice' => $c]]], 'nested' => [[$a => [[$c]]]],
            'invalid identity' => [[$a => ['COURSE.101']]], 'numeric target' => [[0 => [$c]]]];
    }

    #[DataProvider('invalid')]
    public function testInvalidEquivalenceCannotEnterTheDefinition(array $equivalents): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PrerequisiteGroups([[self::id(1)]], equivalents: $equivalents);
    }

    public function testGlobalReferenceAndLinkLimitsIncludeEquivalents(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PrerequisiteGroups([[self::id(1)]], equivalents: [self::id(1) => array_map(self::id(...), range(2, 101))]);
    }

    public function testFiveHundredLinksAreBoundedAndOverlappingMatchingRemainsFeasible(): void
    {
        $required = array_map(self::id(...), range(1, 10));
        $candidates = array_map(self::id(...), range(11, 60));
        $links = array_fill_keys($required, $candidates);
        $groups = new PrerequisiteGroups([$required], [10], equivalents: $links);
        self::assertSame(0, $groups->missing(array_slice($candidates, 0, 10)));
        self::assertSame(1, $groups->missing(array_slice($candidates, 0, 9)));
        $links[$required[0]][] = self::id(61);
        $this->expectException(\InvalidArgumentException::class);
        new PrerequisiteGroups([$required], [10], equivalents: $links);
    }
}
