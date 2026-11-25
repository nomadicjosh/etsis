<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\StandingProposalFixture;

final class StandingProposalTest extends TestCase
{
    private function fixture(bool $publish = true): StandingProposalFixture
    {
        require_once dirname(__DIR__) . '/Support/StandingProposalFixture.php';
        return new StandingProposalFixture($publish);
    }

    public function testNoPolicyOrUndefinedGpaProducesNoStandingAndWritesNoHistory(): void
    {
        $f = $this->fixture(false);
        $s = $f->sis;
        foreach ([false, true] as $publish) {
            if ($publish) {
                $f->publish();
            }
            $before = $f->facts();
            try {
                $s->standingService()->propose($f->student, $f->pursuit);
                self::fail('An undefined assessment produced a standing.');
            } catch (ValidationException $e) {
                self::assertSame('standing_proposal', $e->errors[0]['field']);
            }
            self::assertSame($before, $f->facts());
        }
    }

    public function testExactGpaFloorProposesAndConfirmsANewPeriodWithReproducibleProtectedEvidence(): void
    {
        $f = $this->fixture();
        $f->work();
        $s = $f->sis;
        $service = $s->standingService();
        $before = $f->facts();
        $proposal = $service->propose($f->student, $f->pursuit);
        self::assertSame(2000, $proposal['gpa_milli']);
        self::assertSame('GOOD', $proposal['standing']['code']);
        self::assertArrayNotHasKey('attempts', $proposal);
        self::assertSame($before, $f->facts());
        $view = $service->view($f->student, $f->pursuit);
        $review = $service->review(
            $f->student,
            $f->pursuit,
            [$f->row($proposal)],
            $view['revision'],
            'Fictional GPA proposal acceptance.',
            proposal: $proposal['proposal']
        );
        $receipt = $service->commit(
            $f->student,
            $f->pursuit,
            new EntityId($review['operation_id']),
            $review['rows'],
            $review['revision'],
            $review['reason'],
            $review['review_token'],
            proposal: $review['proposal']
        );
        self::assertSame('updated', $receipt['status']);
        $last = $s->standingLedger()->latest($f->student, $f->pursuit);
        $proof = \Domain\Student\StudentStanding::proposalEvidence($last->event);
        self::assertSame(3000, $proof->data['gpa_credits_milli']);
        self::assertSame(6000, $proof->data['quality_points_milli']);
        self::assertSame('GOOD', $proof->band()['code']);
        $f->rules['enabled'] = false;
        $f->publish();
        $f->work('A');
        $history = $service->timeline($f->student, $f->pursuit);
        self::assertSame(2000, $history['items'][0]['proposal']['gpa_milli']);
        self::assertStringNotContainsString('source_positions', json_encode($history, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($f->student->value, json_encode($proof, JSON_THROW_ON_ERROR));
        $before = $f->facts();
        self::assertSame($receipt, $service->commit(
            $f->student,
            $f->pursuit,
            new EntityId($review['operation_id']),
            $review['rows'],
            $review['revision'],
            $review['reason'],
            $review['review_token'],
            proposal: $review['proposal']
        ));
        self::assertSame($before, $f->facts());
    }

    public static function grades(): array
    {
        return [['F', 0, 'REVIEW'], ['A', 4000, 'GOOD']];
    }

    #[DataProvider('grades')]
    public function testZeroAndHighVerifiedGpaUseTheInstitutionalBand(string $grade, int $gpa, string $code): void
    {
        $f = $this->fixture();
        $f->work($grade);
        $proposal = $f->sis->standingService()->propose($f->student, $f->pursuit);
        self::assertSame($gpa, $proposal['gpa_milli']);
        self::assertSame($code, $proposal['standing']['code']);
    }

    public function testMinimumGpaCreditsRequiresEnoughVerifiedWorkWithoutAssigningStanding(): void
    {
        $f = $this->fixture();
        $f->work();
        $f->rules['minimum_gpa_credits_milli'] = 6000;
        $f->publish();
        $before = $f->facts();
        try {
            $f->sis->standingService()->propose($f->student, $f->pursuit);
            self::fail('Insufficient GPA credits produced standing.');
        } catch (ValidationException) {
            self::assertSame($before, $f->facts());
        }
        $f->work();
        self::assertSame(6000, $f->sis->standingService()->propose($f->student, $f->pursuit)['gpa_credits_milli']);
        self::assertSame([], $f->sis->sql->rows('sis_student_standing'));
    }

    public static function changes(): array
    {
        return [['work'], ['policy'], ['definition'], ['day'], ['expired'], ['authority']];
    }

    private function change(StandingProposalFixture $f, string $change): void
    {
        $s = $f->sis;
        match ($change) {
            'work' => $f->work(), // Same resulting GPA; new academic source still requires a new proposal.
            'policy' => $f->publish(), // Same bands; a new publication must not replace a reviewed source.
            'definition' => $s->standingService()->saveDefinition(
                new EntityId($f->definitions[1]['id']),
                'GOOD', 'Fictional renamed standing', true, $f->definitions[1]['version']
            ),
            'day' => $s->clock->time = $s->clock->time->modify('+1 day'),
            'expired' => $s->clock->time = $s->clock->time->modify('+21 minutes'),
            'authority' => $s->access->staff = false,
            default => throw new \LogicException('Unknown fictional change.'),
        };
    }

    #[DataProvider('changes')]
    public function testAChangedSourceOrExpiredProposalCannotBeReviewed(string $change): void
    {
        $f = $this->fixture();
        $f->work();
        $s = $f->sis;
        $service = $s->standingService();
        $proposal = $service->propose($f->student, $f->pursuit);
        $view = $service->view($f->student, $f->pursuit);
        $this->change($f, $change);
        $before = $f->facts();
        try {
            $service->review(
                $f->student,
                $f->pursuit,
                [$f->row($proposal)],
                $view['revision'],
                'Fictional stale proposal.',
                proposal: $proposal['proposal']
            );
            self::fail('A stale or unauthorized proposal was reviewed.');
        } catch (
            ValidationException | \Domain\Shared\Exception\ConcurrentModificationException
            | \Domain\Shared\Exception\AccessDeniedException
        ) {
            self::assertSame($before, $f->facts());
        }
    }

    #[DataProvider('changes')]
    public function testFinalSourceAuthorityAndExpiryFailuresRollBackTheWholeStandingTransaction(string $change): void
    {
        $f = $this->fixture();
        $f->work();
        $s = $f->sis;
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willReturnCallback(fn() => $this->change($f, $change));
        $service = $s->standingService($audit);
        $proposal = $service->propose($f->student, $f->pursuit);
        $review = $service->review(
            $f->student,
            $f->pursuit,
            [$f->row($proposal)],
            $service->view($f->student, $f->pursuit)['revision'],
            'Fictional proposal audit race.',
            proposal: $proposal['proposal']
        );
        $before = $f->facts();
        try {
            $service->commit(
                $f->student,
                $f->pursuit,
                new EntityId($review['operation_id']),
                $review['rows'],
                $review['revision'],
                $review['reason'],
                $review['review_token'],
                proposal: $review['proposal']
            );
            self::fail('A changed source committed after required audit.');
        } catch (
            ValidationException | \Domain\Shared\Exception\ConcurrentModificationException
            | \Domain\Shared\Exception\AccessDeniedException
        ) {
            self::assertSame($before, $f->facts());
        }
    }

    public function testARequiredAuditFailureRollsBackTheDecisionAndReceipt(): void
    {
        $f = $this->fixture();
        $f->work();
        $s = $f->sis;
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willThrowException(new \RuntimeException('Fictional audit failure.'));
        $service = $s->standingService($audit);
        $proposal = $service->propose($f->student, $f->pursuit);
        $review = $service->review(
            $f->student,
            $f->pursuit,
            [$f->row($proposal)],
            $service->view($f->student, $f->pursuit)['revision'],
            'Fictional required audit.',
            proposal: $proposal['proposal']
        );
        $before = $f->facts();
        try {
            $service->commit(
                $f->student,
                $f->pursuit,
                new EntityId($review['operation_id']),
                $review['rows'],
                $review['revision'],
                $review['reason'],
                $review['review_token'],
                proposal: $review['proposal']
            );
            self::fail('A failed required audit committed.');
        } catch (\RuntimeException) {
            self::assertSame($before, $f->facts());
        }
    }

    public function testProposalReadsShareTheReplayBudgetAndCannotReturnPartialFacts(): void
    {
        $f = $this->fixture();
        $f->work();
        $before = $f->facts();
        try {
            $f->sis->standingProposals()->calculate(
                $f->student,
                $f->pursuit,
                EntityId::generate(),
                new \Domain\Shared\EventSourcing\HistoryReadBudget(1, 1)
            );
            self::fail('The proposal exceeded its shared replay budget.');
        } catch (\Domain\Shared\EventSourcing\HistoryReadLimitException) {
            self::assertSame($before, $f->facts());
        }
    }

    public static function evidenceDamage(): array
    {
        return [['totals'], ['source'], ['policy'], ['attempt'], ['route']];
    }

    #[DataProvider('evidenceDamage')]
    public function testRetainedEvidenceRejectsAlteredCalculationsPoliciesAndRouting(string $damage): void
    {
        $f = $this->fixture();
        $f->work();
        $s = $f->sis;
        $period = EntityId::generate();
        $evidence = $s->standingProposals()->calculate(
            $f->student,
            $f->pursuit,
            $period,
            new \Domain\Shared\EventSourcing\HistoryReadBudget()
        );
        switch ($damage) {
            case 'totals':
                $evidence['quality_points_milli']++;
                break;
            case 'source':
                $evidence['source_positions'] = [];
                break;
            case 'policy':
                $evidence['rules']['bands'][1]['minimum_gpa_milli'] = 2500;
                break;
            case 'attempt':
                $evidence['attempts'][0]['grade']['points_milli'] = 4000;
                break;
        }
        $before = $f->facts();
        try {
            $proof = new \Domain\Student\ValueObject\StandingProposalEvidence($evidence);
            if ($damage === 'route') {
                $proof->routing(EntityId::generate()->value, $f->pursuit->value, []);
            }
            $s->standingPolicies()->verifyEvidence($proof, new \Domain\Shared\EventSourcing\HistoryReadBudget());
            self::fail('Damaged standing proposal evidence was authenticated.');
        } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
            self::assertSame($before, $f->facts());
        }
    }
}
