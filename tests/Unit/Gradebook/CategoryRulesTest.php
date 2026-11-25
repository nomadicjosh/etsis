<?php

declare(strict_types=1);

namespace Tests\Unit\Gradebook;

use Domain\Gradebook\ValueObject\GradebookCalculation;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategoryRulesTest extends TestCase
{
    private function score(int $earned, int $possible = 10000, int $bonus = 0): array
    {
        return ['assignment_id' => EntityId::generate()->value, 'possible_hundredths' => $possible,
            'earned_hundredths' => $earned, 'bonus_hundredths' => $bonus, 'excused' => false];
    }

    private function category(array $scores, array $rules = [], int $weight = 10000): array
    {
        return ['title' => 'Fictional coursework', 'weight_hundredths' => $weight,
            'assignments' => array_column($scores, 'assignment_id'), 'drop_lowest' => 1, ...$rules];
    }

    public function testProtectionCanRetainTheExamEvenWhenItHasTheLowestScore(): void
    {
        $scores = [$this->score(1000), $this->score(5000), $this->score(9000)];
        $before = $scores;
        $category = $this->category($scores, ['protected_assignments' => [$scores[0]['assignment_id']]]);
        $calculation = new GradebookCalculation('category', categories: [$category]);
        $result = $calculation->summarize($scores, true);
        self::assertSame(5000, $result['percent_hundredths']);
        self::assertSame('50', $result['exact_percent']);
        self::assertSame([$scores[1]['assignment_id']], array_column($result['dropped_assignments'], 'assignment_id'));
        self::assertTrue($calculation->assignmentPresentation($scores[0]['assignment_id'])['drop_protected']);
        self::assertFalse($calculation->assignmentPresentation($scores[1]['assignment_id'])['drop_protected']);
        self::assertSame(1, $result['categories'][0]['protected_assignments']);
        self::assertSame($before, $scores);
    }

    public function testEveryUnprotectedTaskCanBeDroppedWhileAProtectedGradedTaskRemains(): void
    {
        $scores = [$this->score(0), $this->score(5000), $this->score(9000)];
        $result = new GradebookCalculation('category', categories: [$this->category(
            $scores,
            ['drop_lowest' => 499, 'protected_assignments' => [$scores[0]['assignment_id']]]
        )])->summarize($scores, true);
        self::assertSame(2, $result['dropped']);
        self::assertSame('0', $result['exact_percent']);
    }

    public function testPendingOrExcusedProtectedWorkCannotReplaceTheLastGradedRegularTask(): void
    {
        $scores = [$this->score(0), $this->score(5000)];
        $scores[0]['earned_hundredths'] = null;
        $category = $this->category($scores, ['drop_lowest' => 499,
            'protected_assignments' => [$scores[0]['assignment_id']]]);
        $v = new GradebookCalculation('category', categories: [$category]);
        $result = $v->summarize($scores);
        self::assertSame(0, $result['dropped']);
        self::assertSame(1, $result['pending']);
        $scores[0]['excused'] = true;
        $result = $v->summarize($scores);
        self::assertSame(0, $result['dropped']);
        self::assertSame(0, $result['pending']);
        self::assertSame(10000, $result['coverage_hundredths']);
    }

    public function testCategoryCapIncludesRegularAndBonusOnlyPointsBeforeApplyingTheWeight(): void
    {
        $scores = [$this->score(12000, bonus: 2000), $this->score(10000, 0, 10000), $this->score(8000)];
        $category = $this->category(array_slice($scores, 0, 2), ['drop_lowest' => 0, 'cap_at_hundred' => true], 5000);
        $other = $this->category([$scores[2]], ['title' => 'Fictional exam', 'drop_lowest' => 0], 5000);
        $before = $scores;
        $v = new GradebookCalculation('category', categories: [$category, $other]);
        $result = $v->summarize($scores, true);
        self::assertSame('90', $result['exact_percent']);
        self::assertSame(9000, $result['percent_hundredths']);
        self::assertSame(22000, $result['categories'][0]['raw_percent_hundredths']);
        self::assertSame(10000, $result['categories'][0]['percent_hundredths']);
        self::assertTrue($result['categories'][0]['capped']);
        self::assertSame(12000, $result['bonus_earned_hundredths']);
        self::assertSame($before, $scores);
        $category['cap_at_hundred'] = false;
        self::assertSame('150', new GradebookCalculation('category', categories: [$category, $other])
            ->summarize($scores, true)['exact_percent']);
    }

    public function testCapIsLocalAndDoesNotImposeAWholeGradebookCeiling(): void
    {
        $scores = [$this->score(15000, bonus: 5000), $this->score(12000, bonus: 2000)];
        $first = $this->category([$scores[0]], ['drop_lowest' => 0, 'cap_at_hundred' => true], 5000);
        $second = $this->category([$scores[1]], ['title' => 'Fictional second', 'drop_lowest' => 0], 5000);
        self::assertSame('110', new GradebookCalculation('category', categories: [$first, $second])
            ->summarize($scores, true)['exact_percent']);
    }

    public function testProtectionAndCapComposeWithExactArithmetic(): void
    {
        $scores = [$this->score(0), $this->score(10000), $this->score(10000, 0, 10000)];
        $category = $this->category($scores, ['protected_assignments' => [$scores[0]['assignment_id']],
            'cap_at_hundred' => true]);
        $result = new GradebookCalculation('category', categories: [$category])->summarize($scores, true);
        self::assertSame([$scores[1]['assignment_id']], array_column($result['dropped_assignments'], 'assignment_id'));
        self::assertSame('100', $result['exact_percent']);
        self::assertFalse($result['categories'][0]['capped']);
    }

    public static function malformed(): array
    {
        return array_map(static fn(string $case): array => [$case], [
            'cap_string', 'cap_null', 'protected_null', 'protected_scalar', 'protected_duplicate',
            'protected_unknown', 'protected_other_category', 'protected_bonus',
        ]);
    }

    #[DataProvider('malformed')]
    public function testMalformedOrIneligibleProtectionIsRejected(string $case): void
    {
        $scores = [$this->score(5000), $this->score(1000, 0, 1000)];
        $id = $scores[0]['assignment_id'];
        $category = $this->category($scores);
        $bonus = [];
        match ($case) {
            'cap_string' => $category['cap_at_hundred'] = '1',
            'cap_null' => $category['cap_at_hundred'] = null,
            'protected_null' => $category['protected_assignments'] = null,
            'protected_scalar' => $category['protected_assignments'] = $id,
            'protected_duplicate' => $category['protected_assignments'] = [$id, $id],
            'protected_unknown', 'protected_other_category' =>
            $category['protected_assignments'] = [EntityId::generate()->value],
            'protected_bonus' => $category['protected_assignments'] = [$scores[1]['assignment_id']],
        };
        if ($case === 'protected_bonus') {
            $bonus[] = $scores[1]['assignment_id'];
        }
        $categories = [$category];
        if ($case === 'protected_other_category') {
            $categories[0]['assignments'] = [$id];
            $categories[0]['weight_hundredths'] = 5000;
            $categories[0]['protected_assignments'] = [$scores[1]['assignment_id']];
            $categories[] = $this->category([$scores[1]], ['title' => 'Fictional other category'], 5000);
        }
        $this->expectException(ValidationException::class);
        new GradebookCalculation('category', categories: $categories, bonusAssignments: $bonus);
    }

    public function testACapDoesNotInventAnAverageFromPendingRegularWorkAndRecordedBonus(): void
    {
        $scores = [$this->score(0), $this->score(1000, 0, 1000)];
        $scores[0]['earned_hundredths'] = null;
        $v = new GradebookCalculation('category', categories: [$this->category($scores, ['cap_at_hundred' => true])]);
        $result = $v->summarize($scores, true);
        self::assertNull($result['exact_percent']);
        self::assertNull($result['categories'][0]['raw_percent_hundredths']);
        self::assertSame(1000, $result['unapplied_bonus_hundredths']);
        self::assertSame(1, $result['pending']);
        self::assertSame(1, $result['awaiting_categories']);
    }

    public function testACappedEmptyCategoryStillRequiresGradedRegularWork(): void
    {
        $result = new GradebookCalculation('category', categories: [$this->category([], ['cap_at_hundred' => true])])
            ->summarize([], true);
        self::assertNull($result['exact_percent']);
        self::assertSame(1, $result['awaiting_categories']);
    }

    public function testActualBonusTypeIsCheckedEvenWhenAnEarlierCallerDidNotClassifyIt(): void
    {
        $scores = [$this->score(5000), $this->score(1000, 0, 1000)];
        $v = new GradebookCalculation('category', categories: [$this->category(
            $scores,
            ['protected_assignments' => [$scores[1]['assignment_id']]]
        )]);
        $this->expectException(ValidationException::class);
        $v->summarize($scores);
    }
}
