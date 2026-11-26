<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\DegreeAudit\ValueObject\GroupWorkEligibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PursuitCompletionTest extends TestCase
{
    private function policy(array $changes = []): GroupWorkEligibility
    {
        return new GroupWorkEligibility(array_replace(
            GroupWorkEligibility::DEFAULT,
            ['completed_since_pursuit_start' => true],
            $changes
        ));
    }

    public function testTheRecordedStartIsAnInclusiveLowerBoundIndependentOfAgeLimits(): void
    {
        $policy = $this->policy();
        self::assertSame(
            'completed_before_pursuit_start',
            $policy->completionStatus('2026-01-30', '2026-01-31')?->value
        );
        self::assertNull($policy->completionStatus('2026-01-31', '2026-01-31'));
        self::assertNull($policy->completionStatus('2026-02-01', '2026-01-31'));
        self::assertSame(
            ['pursuit_started_on' => '2026-01-31', 'earliest_completion_on' => '2026-01-31'],
            $policy->recencyContext('2026-01-31')
        );
        $requirements = ['group_details' => [['definition' => ['work_eligibility' => $policy->facts]]]];
        self::assertTrue(GroupWorkEligibility::requiresPursuitStart($requirements));
        self::assertFalse(GroupWorkEligibility::requiresEvaluationDate($requirements));
    }

    public function testMissingCompletionAndPursuitDatesStayUnknownWithoutInventingADate(): void
    {
        $policy = $this->policy();
        self::assertSame('completion_date_unknown', $policy->completionStatus(null, '2026-01-31')?->value);
        self::assertSame('pursuit_start_unknown', $policy->completionStatus('2026-01-31')?->value);
        self::assertSame(['pursuit_started_on' => null, 'earliest_completion_on' => null], $policy->recencyContext());
    }

    public function testFixedWindowsAndExpirationRemainIndependentAndKnownExclusionsStayDecisive(): void
    {
        $policy = $this->policy(['completed_from' => '2026-02-01', 'completed_through' => '2026-12-31',
            'max_age_months_at_pursuit_start' => 60, 'max_age_months_at_evaluation' => 12]);
        self::assertSame('completed_before_window', $policy->completionStatus('2026-01-31')?->value);
        self::assertSame('completed_after_window', $policy->completionStatus('2027-01-01')?->value);
        self::assertSame(
            'completed_before_pursuit_start',
            $policy->completionStatus('2026-02-01', '2026-02-02')?->value
        );
        self::assertSame('evaluation_date_unknown', $policy->completionStatus('2026-02-02', '2026-02-02')?->value);
        self::assertNull($policy->completionStatus('2026-02-02', '2026-02-02', '2027-02-02'));
        self::assertSame(
            'completion_expired',
            $policy->completionStatus('2026-02-02', '2026-02-02', '2027-02-03')?->value
        );
        self::assertSame('2026-02-02', $policy->recencyContext('2026-02-02')['earliest_completion_on']);
    }

    public function testFalseAndEarlierAbsentRulesPreserveTheirExactShapeAndDoNotRequireAStart(): void
    {
        $old = ['credit_origin' => 'any', 'completed_from' => null, 'completed_through' => null];
        foreach ([$old, $old + ['completed_since_pursuit_start' => false]] as $facts) {
            $policy = new GroupWorkEligibility($facts);
            self::assertSame($facts, $policy->facts);
            self::assertNull($policy->completionStatus());
            self::assertFalse(GroupWorkEligibility::requiresPursuitStart([
                'group_details' => [['definition' => ['work_eligibility' => $facts]]],
            ]));
        }
    }

    public static function invalidChoices(): array
    {
        return [[null], [0], [1], ['true'], ['false'], [[]]];
    }

    #[DataProvider('invalidChoices')]
    public function testOnlyAnExplicitBooleanCanEnableOrDisableTheRule(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policy(['completed_since_pursuit_start' => $value]);
    }

    public function testInvalidSuppliedStartDatesFailInsteadOfBecomingAnUnknownOrAnInferredDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policy()->completionStatus('2026-03-01', '2026-02-30');
    }
}
