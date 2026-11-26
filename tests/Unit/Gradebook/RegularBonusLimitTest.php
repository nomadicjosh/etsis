<?php

declare(strict_types=1);

namespace Tests\Unit\Gradebook;

use Domain\Gradebook\ValueObject\GradebookCalculation;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegularBonusLimitTest extends TestCase
{
    private function score(?int $earned, int $possible = 10000, int $bonus = 0): array
    {
        return ['assignment_id' => EntityId::generate()->value, 'possible_hundredths' => $possible,
            'earned_hundredths' => $earned, 'bonus_hundredths' => $bonus, 'excused' => false];
    }

    private function category(array $scores, array $rules = [], int $weight = 10000): array
    {
        return ['title' => 'Fictional regular work', 'weight_hundredths' => $weight,
            'assignments' => array_column($scores, 'assignment_id'), ...$rules];
    }

    private function summary(array $scores, array $rules = [], bool $exact = true): array
    {
        return new GradebookCalculation('category', categories: [$this->category($scores, $rules)])
            ->summarize($scores, $exact);
    }

    public function testOnlyExcessRegularPointsAreLimitedAndOriginalMarksStayIntact(): void
    {
        $scores = [$this->score(11000, 10000, 1000), $this->score(8000)];
        $before = $scores;
        $result = $this->summary($scores, ['regular_bonus_cap_hundredths' => 250]);
        self::assertSame('185/2', $result['exact_percent']);
        self::assertSame(9250, $result['percent_hundredths']);
        self::assertSame(
            9250,
            $this->summary($scores, ['regular_bonus_cap_hundredths' => 250], false)['percent_hundredths']
        );
        self::assertSame(500, $result['categories'][0]['regular_bonus_raw_percent_hundredths']);
        self::assertSame(250, $result['categories'][0]['regular_bonus_applied_percent_hundredths']);
        self::assertTrue($result['categories'][0]['regular_bonus_limited']);
        self::assertSame(1000, $result['bonus_earned_hundredths']);
        self::assertSame($before, $scores);
    }

    public function testRegularAndBonusOnlyLimitsRemainIndependentAndRunBeforeTheWholeCategoryCap(): void
    {
        $scores = [$this->score(11000, 10000, 1000), $this->score(8000), $this->score(4000, 0, 4000)];
        self::assertSame('110', $this->summary($scores, ['regular_bonus_cap_hundredths' => 0])['exact_percent']);
        self::assertSame('95', $this->summary($scores, ['bonus_only_cap_hundredths' => 0])['exact_percent']);
        $rules = ['regular_bonus_cap_hundredths' => 250, 'bonus_only_cap_hundredths' => 500];
        self::assertSame('195/2', $this->summary($scores, $rules)['exact_percent']);
        $rules['bonus_only_cap_hundredths'] = 1000;
        $rules['cap_at_hundred'] = true;
        $result = $this->summary($scores, $rules);
        self::assertSame('100', $result['exact_percent']);
        self::assertTrue($result['categories'][0]['capped']);
        self::assertSame(250, $result['categories'][0]['regular_bonus_applied_percent_hundredths']);
        self::assertSame(1000, $result['categories'][0]['bonus_only_applied_percent_hundredths']);
    }

    public function testCategoryWeightingUsesTheLimitedResultWithoutChangingOtherCategories(): void
    {
        $first = [$this->score(11000, 10000, 1000), $this->score(8000)];
        $second = [$this->score(10000)];
        $categories = [$this->category($first, ['regular_bonus_cap_hundredths' => 250], 4000),
            $this->category($second, ['title' => 'Fictional exam'], 6000)];
        $result = new GradebookCalculation('category', categories: $categories)
            ->summarize([...$first, ...$second], true);
        self::assertSame('97', $result['exact_percent']);
        self::assertArrayNotHasKey('regular_bonus_cap_hundredths', $result['categories'][1]);
    }

    public function testDropsUseActualScoresBeforeTheLimitAndDroppedOrExcusedBonusIsExcluded(): void
    {
        $scores = [$this->score(0), $this->score(11000, 10000, 1000)];
        $rules = ['drop_lowest' => 1, 'regular_bonus_cap_hundredths' => 200];
        $result = $this->summary($scores, $rules);
        self::assertSame('102', $result['exact_percent']);
        self::assertSame([$scores[0]['assignment_id']], array_column($result['dropped_assignments'], 'assignment_id'));
        $rules['protected_assignments'] = [$scores[0]['assignment_id']];
        self::assertSame('0', $this->summary($scores, $rules)['exact_percent']);
        $scores[1]['excused'] = true;
        $result = $this->summary($scores, ['regular_bonus_cap_hundredths' => 0]);
        self::assertSame('0', $result['exact_percent']);
        self::assertSame(0, $result['categories'][0]['regular_bonus_raw_percent_hundredths']);
    }

    public function testFractionalAllowancesRemainExactForFinalGradeComparison(): void
    {
        $scores = [$this->score(4, 3, 1), $this->score(1, 3)];
        $result = $this->summary($scores, ['regular_bonus_cap_hundredths' => 555]);
        self::assertSame('4333/60', $result['exact_percent']);
        self::assertSame(7222, $result['percent_hundredths']);
        self::assertSame(555, $result['categories'][0]['regular_bonus_applied_percent_hundredths']);
    }

    public function testMissingOrExcusedRegularDenominatorsAreNotInventedByALimit(): void
    {
        foreach ([false, true] as $excused) {
            $scores = [$this->score($excused ? 11000 : null, 10000, 1000), $this->score(500, 0, 500)];
            $scores[0]['excused'] = $excused;
            $result = $this->summary($scores, ['regular_bonus_cap_hundredths' => 0]);
            self::assertNull($result['exact_percent']);
            self::assertNull($result['categories'][0]['regular_bonus_applied_percent_hundredths']);
            self::assertSame(500, $result['unapplied_bonus_hundredths']);
            self::assertFalse($result['categories'][0]['regular_bonus_limited']);
        }
    }

    public function testNoBonusAndZeroWeightWorkDoNotCreateBonusCreditOrAnAverage(): void
    {
        $scores = [$this->score(9000), $this->score(11000, 10000, 1000)];
        $categories = [$this->category([$scores[0]], ['regular_bonus_cap_hundredths' => 10000]),
            $this->category([$scores[1]], [
                'title' => 'Fictional excluded work', 'regular_bonus_cap_hundredths' => 500,
            ], 0)];
        $result = new GradebookCalculation('category', categories: $categories)->summarize($scores, true);
        self::assertSame('90', $result['exact_percent']);
        self::assertSame(0, $result['bonus_earned_hundredths']);
        self::assertFalse($result['categories'][0]['regular_bonus_limited']);
    }

    public function testAbsentAndExplicitZeroLimitsRemainDifferentAndBelowLimitBonusIsUnchanged(): void
    {
        $scores = [$this->score(11000, 10000, 1000)];
        $old = $this->summary($scores);
        self::assertSame('110', $old['exact_percent']);
        self::assertArrayNotHasKey('regular_bonus_cap_hundredths', $old['categories'][0]);
        self::assertSame('100', $this->summary($scores, ['regular_bonus_cap_hundredths' => 0])['exact_percent']);
        $unrestricted = $this->summary($scores, ['regular_bonus_cap_hundredths' => 10000]);
        self::assertSame('110', $unrestricted['exact_percent']);
        self::assertFalse($unrestricted['categories'][0]['regular_bonus_limited']);
    }

    public static function invalidLimits(): array
    {
        return array_map(static fn(mixed $value): array => [$value], [-1, 10001, null, '500', 5.5, true, false, []]);
    }

    #[DataProvider('invalidLimits')]
    public function testOnlyBoundedIntegerPercentagePointLimitsAreAccepted(mixed $limit): void
    {
        $this->expectException(ValidationException::class);
        $this->summary([$this->score(10000)], ['regular_bonus_cap_hundredths' => $limit]);
    }
}
