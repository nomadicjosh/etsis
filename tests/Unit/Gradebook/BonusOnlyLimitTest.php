<?php

declare(strict_types=1);

namespace Tests\Unit\Gradebook;

use Domain\Gradebook\ValueObject\GradebookCalculation;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BonusOnlyLimitTest extends TestCase
{
    private function score(?int $earned, int $possible = 10000, int $bonus = 0): array
    {
        return ['assignment_id' => EntityId::generate()->value, 'possible_hundredths' => $possible,
            'earned_hundredths' => $earned, 'bonus_hundredths' => $bonus, 'excused' => false];
    }

    private function category(array $scores, array $rules = [], int $weight = 10000): array
    {
        return ['title' => 'Fictional coursework', 'weight_hundredths' => $weight,
            'assignments' => array_column($scores, 'assignment_id'), ...$rules];
    }

    private function summary(array $scores, array $rules, bool $exact = true): array
    {
        return new GradebookCalculation('category', categories: [$this->category($scores, $rules)])
            ->summarize($scores, $exact);
    }

    public function testLimitCountsOnlyThePermittedBonusContributionAndLeavesMarksUntouched(): void
    {
        $scores = [$this->score(9000), $this->score(2000, 0, 2000)];
        $before = $scores;
        $result = $this->summary($scores, ['bonus_only_cap_hundredths' => 500]);
        self::assertSame('95', $result['exact_percent']);
        self::assertSame(9500, $result['percent_hundredths']);
        self::assertSame(
            9500,
            $this->summary($scores, ['bonus_only_cap_hundredths' => 500], false)['percent_hundredths'],
        );
        self::assertSame(2000, $result['categories'][0]['bonus_only_raw_percent_hundredths']);
        self::assertSame(500, $result['categories'][0]['bonus_only_applied_percent_hundredths']);
        self::assertTrue($result['categories'][0]['bonus_only_limited']);
        self::assertSame(2000, $result['bonus_earned_hundredths']);
        self::assertSame(0, $result['unapplied_bonus_hundredths']);
        self::assertSame($before, $scores);
    }

    public function testLimitAppliesBeforeCategoryWeightingAndKeepsOtherCategoriesIndependent(): void
    {
        $scores = [$this->score(8000), $this->score(5000, 0, 5000), $this->score(10000)];
        $first = $this->category(array_slice($scores, 0, 2), ['bonus_only_cap_hundredths' => 500], 4000);
        $second = $this->category([$scores[2]], ['title' => 'Fictional exam'], 6000);
        $result = new GradebookCalculation('category', categories: [$first, $second])->summarize($scores, true);
        self::assertSame('94', $result['exact_percent']);
        self::assertArrayNotHasKey('bonus_only_cap_hundredths', $result['categories'][1]);
    }

    public function testRegularBonusMarksAreIndependentAndTheWholeCategoryCapRunsAfterTheBonusLimit(): void
    {
        $scores = [$this->score(11000, 10000, 1000), $this->score(5000, 0, 5000)];
        self::assertSame('110', $this->summary($scores, ['bonus_only_cap_hundredths' => 0])['exact_percent']);
        $result = $this->summary($scores, ['bonus_only_cap_hundredths' => 500, 'cap_at_hundred' => true]);
        self::assertSame('100', $result['exact_percent']);
        self::assertTrue($result['categories'][0]['capped']);
        $scores[0]['earned_hundredths'] = 9000;
        $result = $this->summary($scores, ['bonus_only_cap_hundredths' => 500, 'cap_at_hundred' => true]);
        self::assertSame('95', $result['exact_percent']);
        self::assertFalse($result['categories'][0]['capped']);
    }

    public function testDropsAndProtectionDetermineTheRegularDenominatorBeforeTheLimit(): void
    {
        $scores = [$this->score(0), $this->score(4000, 5000), $this->score(2000, 0, 2000)];
        $rules = ['drop_lowest' => 1, 'bonus_only_cap_hundredths' => 500];
        $result = $this->summary($scores, $rules);
        self::assertSame('85', $result['exact_percent']);
        self::assertSame([$scores[0]['assignment_id']], array_column($result['dropped_assignments'], 'assignment_id'));
        $rules['protected_assignments'] = [$scores[0]['assignment_id']];
        self::assertSame('5', $this->summary($scores, $rules)['exact_percent']);
    }

    public function testFractionalPointAllowancesAreNotRoundedBeforeTheFinalComparison(): void
    {
        $scores = [$this->score(2, 3), $this->score(1, 0, 100)];
        $result = $this->summary($scores, ['bonus_only_cap_hundredths' => 555]);
        self::assertSame('4333/60', $result['exact_percent']);
        self::assertSame(7222, $result['percent_hundredths']);
        self::assertSame(555, $result['categories'][0]['bonus_only_applied_percent_hundredths']);
    }

    public function testTheLimitDoesNotInventARegularDenominatorOrHideUnappliedBonusWork(): void
    {
        $scores = [$this->score(null), $this->score(1000, 0, 1000)];
        $result = $this->summary($scores, ['bonus_only_cap_hundredths' => 0]);
        self::assertNull($result['exact_percent']);
        self::assertNull($result['categories'][0]['bonus_only_applied_percent_hundredths']);
        self::assertSame(1000, $result['unapplied_bonus_hundredths']);
        self::assertSame(1, $result['pending']);
    }

    public static function optionalScores(): array
    {
        return ['unentered' => [null, false], 'excused' => [1000, true], 'zero' => [0, false]];
    }

    #[DataProvider('optionalScores')]
    public function testOptionalPendingExcusedAndZeroBonusWorkNeverLowerTheAverage(?int $earned, bool $excused): void
    {
        $scores = [$this->score(9000), $this->score($earned, 0, 1000)];
        $scores[1]['excused'] = $excused;
        $result = $this->summary($scores, ['bonus_only_cap_hundredths' => 0]);
        self::assertSame('90', $result['exact_percent']);
        self::assertSame(0, $result['pending']);
        self::assertFalse($result['categories'][0]['bonus_only_limited']);
    }

    public function testAbsentLimitsPreserveEarlierShapesAndExplicitZeroRemainsDistinct(): void
    {
        $scores = [$this->score(9000), $this->score(2000, 0, 2000)];
        $old = $this->summary($scores, []);
        self::assertSame('110', $old['exact_percent']);
        self::assertArrayNotHasKey('bonus_only_cap_hundredths', $old['categories'][0]);
        self::assertSame('90', $this->summary($scores, ['bonus_only_cap_hundredths' => 0])['exact_percent']);
    }

    public static function invalidLimits(): array
    {
        return array_map(static fn(mixed $value): array => [$value], [-1, 10001, null, '500', 5.5, true, false, []]);
    }

    #[DataProvider('invalidLimits')]
    public function testOnlyBoundedIntegerPercentagePointLimitsAreAccepted(mixed $limit): void
    {
        $scores = [$this->score(10000)];
        $this->expectException(ValidationException::class);
        $this->summary($scores, ['bonus_only_cap_hundredths' => $limit]);
    }
}
