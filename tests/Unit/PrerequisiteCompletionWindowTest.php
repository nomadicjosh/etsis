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

final class PrerequisiteCompletionWindowTest extends TestCase
{
    public static function invalidWindows(): array
    {
        return [[[]], [['from' => null, 'through' => null]], [['from' => '2025-01-01']],
            [['from' => '2025-02-29', 'through' => null]], [['from' => '2025-1-01', 'through' => null]],
            [['from' => '0000-01-01', 'through' => null]], [['from' => 2025, 'through' => null]],
            [['from' => '2026-01-01', 'through' => '2025-12-31']],
            [['from' => null, 'through' => '2025-01-01', 'extra' => true]]];
    }

    #[DataProvider('invalidWindows')]
    public function testMalformedEmptyOrReversedWindowsAreRejected(array $window): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PrerequisiteCompletionWindow($window);
    }

    public function testInclusiveBoundsAndUnknownDatesRemainDistinctFromUnrestrictedWork(): void
    {
        $id = EntityId::generate()->value;
        $groups = new PrerequisiteGroups([[$id], [$id]], completionWindows: [
            ['from' => '2024-02-29', 'through' => '2024-03-01'], null,
        ]);
        self::assertTrue($groups->acceptsCompletion(0, '2024-02-29'));
        self::assertTrue($groups->acceptsCompletion(0, '2024-03-01'));
        self::assertFalse($groups->acceptsCompletion(0, '2024-02-28'));
        self::assertFalse($groups->acceptsCompletion(0, null));
        self::assertTrue($groups->acceptsCompletion(1, null));
        self::assertTrue(new PrerequisiteGroups([[$id]], completionWindows: [
            ['from' => null, 'through' => '2024-03-01'],
        ])->acceptsCompletion(0, '2024-02-01'));
    }

    private function decision(int $recentPoints): PrerequisiteDecision
    {
        $course = EntityId::generate()->value;
        $groups = new PrerequisiteGroups(
            [[$course], [$course]],
            minimumGradePointsMilli: [4000, 3000],
            completionWindows: [['from' => '2024-01-01', 'through' => '2024-12-31'],
            ['from' => '2025-01-01', 'through' => null]]
        );
        $support = [];
        foreach (['2024-06-01' => 4000, '2025-06-01' => $recentPoints] as $on => $points) {
            $support[] = ['course_id' => $course, 'credit_id' => EntityId::generate()->value,
                'origin' => 'institutional', 'source_id' => EntityId::generate()->value,
                'academic_version' => 2, 'registration_version' => 0, 'pursuit_version' => 1,
                'grade_points_milli' => $points, 'completed_on' => $on];
        }
        return new PrerequisiteDecision(EntityId::generate(), [$course], $support, $groups);
    }

    public function testOneAttemptCannotLendItsGradeToAnotherCompletionDate(): void
    {
        $decision = $this->decision(2000);
        self::assertFalse($decision->isMet());
        self::assertSame([true, false], array_column($decision->groupStatuses(), 'met'));
        self::assertSame(1, $decision->missingCount());
        $met = $this->decision(3000);
        self::assertTrue($met->isMet());
        self::assertSame($met->retained(), PrerequisiteDecision::fromRetained($met->retained())->retained());
        self::assertStringNotContainsString('credit_id', json_encode($met->groupStatuses()));
        self::assertSame([1, 1], array_column($met->groupStatuses(), 'completed_count'));
    }

    public static function damagedEvidence(): array
    {
        return [['missing'], ['malformed'], ['duplicate'], ['outside']];
    }

    #[DataProvider('damagedEvidence')]
    public function testRetainedCompletionEvidenceCannotBeOmittedChangedOrBorrowed(string $kind): void
    {
        $facts = $this->decision(3000)->retained();
        if ($kind === 'missing') {
            unset($facts['support'][1]['completed_on']);
        } elseif ($kind === 'malformed') {
            $facts['support'][1]['completed_on'] = '2025-02-30';
        } elseif ($kind === 'duplicate') {
            $facts['support'][1]['completed_on'] = $facts['support'][0]['completed_on'];
        } else {
            $facts['support'][1]['completed_on'] = '2023-01-01';
        }
        $this->expectException(CorruptHistoryException::class);
        PrerequisiteDecision::fromRetained($facts);
    }

    public function testOptionalWindowsTrackRequirementPositionsThroughSubsets(): void
    {
        $first = EntityId::generate()->value;
        $second = EntityId::generate()->value;
        $window = ['from' => '2025-01-01', 'through' => null];
        $groups = new PrerequisiteGroups([[$first], [$second]], completionWindows: [null, $window]);
        self::assertSame([$window], $groups->subset([$second])->completionWindows);
        self::assertNull($groups->subset([])->completionWindows);
        $this->expectException(\InvalidArgumentException::class);
        new PrerequisiteGroups([[$first]], completionWindows: []);
    }
    public function testDateSpecificEvidenceRetainsItsBoundedSourceAllowance(): void
    {
        $course = EntityId::generate()->value;
        $groups = new PrerequisiteGroups([[$course]], completionWindows: [
            ['from' => '2025-01-01', 'through' => null],
        ]);
        $support = [];
        for ($index = 0; $index < 201; $index++) {
            $date = new \DateTimeImmutable('2025-01-01')->modify('+' . $index . ' days')->format('Y-m-d');
            $support[] = ['course_id' => $course, 'credit_id' => EntityId::generate()->value,
                'origin' => 'institutional', 'source_id' => EntityId::generate()->value,
                'academic_version' => 2, 'registration_version' => 1, 'pursuit_version' => 1,
                'completed_on' => $date];
        }
        self::assertTrue(new PrerequisiteDecision(
            EntityId::generate(),
            [$course],
            array_slice($support, 0, 200),
            $groups
        )->isMet());
        $this->expectException(CorruptHistoryException::class);
        new PrerequisiteDecision(EntityId::generate(), [$course], $support, $groups);
    }

    public function testCalendarValidationDoesNotChangeWithTheServerTimezone(): void
    {
        $original = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Apia');
            self::assertTrue(PrerequisiteCompletionWindow::validDate('2011-12-30'));
            self::assertFalse(PrerequisiteCompletionWindow::validDate('2025-02-29'));
        } finally {
            date_default_timezone_set($original);
        }
    }
}
