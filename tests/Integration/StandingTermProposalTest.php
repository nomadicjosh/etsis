<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Tests\Support\StandingTermFixture;

final class StandingTermProposalTest extends TestCase
{
    private function fixture(): StandingTermFixture
    {
        require_once dirname(__DIR__) . '/Support/StandingTermFixture.php';
        return new StandingTermFixture();
    }

    public function testVerifiedPostingTermSelectsWorkRatherThanTheOriginalRegistrationTerm(): void
    {
        $f = $this->fixture();
        $s = $f->base->sis;
        $fall = $f->propose($s->term);
        $spring = $f->propose($f->other);
        self::assertSame(2000, $fall['gpa_milli']);
        self::assertSame('REVIEW', $fall['standing']['code']);
        self::assertSame(4000, $spring['gpa_milli']);
        self::assertSame('GOOD', $spring['standing']['code']);
        self::assertSame(3000, $spring['gpa_credits_milli']);
        self::assertSame(['basis' => 'term', 'term_code' => '26/SP'], $spring['assessment']);
        self::assertSame($f->other->value, $spring['proposal']['term_id']);
    }

    public function testCumulativePolicyStillUsesAllVerifiedPursuitWorkAndRejectsAnUnreviewedTerm(): void
    {
        $f = $this->fixture();
        $f->base->rules['gpa_basis'] = 'cumulative';
        $f->base->publish();
        self::assertSame(3000, $f->propose()['gpa_milli']);
        self::assertSame(6000, $f->propose()['gpa_credits_milli']);
        $before = $f->facts();
        try {
            $f->propose($f->other);
            self::fail('A cumulative policy silently ignored a selected term.');
        } catch (ValidationException $error) {
            self::assertStringContainsString('cumulative GPA', $error->errors[0]['message']);
            self::assertSame($before, $f->facts());
        }
    }

    public function testAMissingOrEmptyTermCannotProduceAZeroOrPartialGpa(): void
    {
        $f = $this->fixture();
        $before = $f->facts();
        try {
            $f->propose();
            self::fail('A term policy silently used cumulative GPA.');
        } catch (ValidationException $error) {
            self::assertStringContainsString('Select a posting term', $error->errors[0]['message']);
            self::assertSame($before, $f->facts());
        }
        $s = $f->base->sis;
        $empty = EntityId::generate();
        $s->sql->insert('sis_terms', array_replace(
            $s->sql->one('sis_terms', ['id' => $s->term->value]),
            ['id' => $empty->value, 'code' => 'EMPTY']
        ));
        $before = $f->facts();
        try {
            $f->propose($empty);
            self::fail('An empty term invented a zero GPA.');
        } catch (ValidationException $error) {
            self::assertStringContainsString('undefined GPA', $error->errors[0]['message']);
            self::assertSame($before, $f->facts());
        }
    }

    public function testMinimumCreditsApplyToTheSelectedTermRatherThanTheCumulativeTotal(): void
    {
        $f = $this->fixture();
        $f->base->rules['minimum_gpa_credits_milli'] = 6000;
        $f->base->publish();
        try {
            $f->propose($f->other);
            self::fail('Cumulative credits satisfied a term minimum.');
        } catch (ValidationException $error) {
            self::assertStringContainsString('minimum GPA credits', $error->errors[0]['message']);
        }
        $f->move($f->first, $f->other);
        self::assertSame(6000, $f->propose($f->other)['gpa_credits_milli']);
        self::assertSame(3000, $f->propose($f->other)['gpa_milli']);
    }

    public function testProgramOverrideControlsItsOwnGpaBasis(): void
    {
        $f = $this->fixture();
        $s = $f->base->sis;
        $program = $s->sql->one('sis_programs', ['id' => $s->program->value]);
        $override = $f->base->rules;
        $override['gpa_basis'] = 'cumulative';
        $f->base->rules['overrides'] = [['kind' => 'program',
            'target' => array_intersect_key($program, array_flip(['id', 'code', 'title'])), 'rules' => $override]];
        $f->base->publish();
        self::assertSame('program', $f->propose()['scope']['kind']);
        self::assertSame(['basis' => 'cumulative'], $f->propose()['assessment']);
        self::assertSame(3000, $f->propose()['gpa_milli']);
    }

    public function testAcceptedTermEvidenceSurvivesLaterPostingPolicyAndCalendarChanges(): void
    {
        $f = $this->fixture();
        $proposal = $f->propose($f->other);
        $review = $f->accept($proposal);
        $s = $f->base->sis;
        $last = $s->standingLedger()->latest($f->base->student, $f->base->pursuit);
        $proof = \Domain\Student\StudentStanding::proposalEvidence($last->event);
        self::assertSame($f->other->value, $proof->data['assessment']['term']['id']);
        self::assertCount(1, $proof->data['attempts']);
        self::assertSame($f->other->value, $proof->data['attempts'][0]['posting_term_id']);
        $f->move($f->second, $s->term);
        $s->sql->update('sis_terms', ['code' => 'RENAMED'], ['id' => $f->other->value]);
        $f->base->rules['enabled'] = false;
        $f->base->publish();
        $s->clock->time = $s->clock->time->modify('+1 day');
        $before = $f->facts();
        $history = $s->standingService()->timeline($f->base->student, $f->base->pursuit);
        self::assertSame(['basis' => 'term', 'term_code' => '26/SP'], $history['items'][0]['proposal']['assessment']);
        self::assertStringNotContainsString('posting_term_id', json_encode($history, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($f->other->value, json_encode($proof, JSON_THROW_ON_ERROR));
        self::assertSame('updated', $s->standingService()->commit(
            $f->base->student,
            $f->base->pursuit,
            new EntityId($review['operation_id']),
            $review['rows'],
            $review['revision'],
            $review['reason'],
            $review['review_token'],
            proposal: $review['proposal']
        )['status']);
        self::assertSame($before, $f->facts());
    }

    public static function damage(): array
    {
        return [['missing'], ['basis'], ['term'], ['attempt'], ['transfer']];
    }

    #[DataProvider('damage')]
    public function testFrozenAssessmentMustMatchThePublishedBasisAndEveryContributingAttempt(string $damage): void
    {
        $f = $this->fixture();
        $base = $f->base;
        $evidence = $base->sis->standingProposals()->calculate(
            $base->student,
            $base->pursuit,
            EntityId::generate(),
            new HistoryReadBudget(),
            $f->other
        );
        match ($damage) {
            'missing' => $evidence['assessment'] = null,
            'basis' => $evidence['assessment'] = ['basis' => 'cumulative', 'term' => null],
            'term' => $evidence['assessment']['term']['id'] = $base->sis->term->value,
            'attempt' => $evidence['attempts'][0]['posting_term_id'] = $base->sis->term->value,
            'transfer' => $evidence['attempts'][0]['credit_type'] = 'transfer',
        };
        $before = $f->facts();
        try {
            new \Domain\Student\ValueObject\StandingProposalEvidence($evidence);
            self::fail('Damaged or mismatched frozen assessment was accepted.');
        } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
            self::assertSame($before, $f->facts());
        }
    }

    public static function changes(): array
    {
        return [['posting'], ['calendar'], ['basis'], ['selected_term']];
    }

    #[DataProvider('changes')]
    public function testPendingProposalsRejectPostingCalendarBasisAndTermSubstitutionChanges(string $change): void
    {
        $f = $this->fixture();
        $b = $f->base;
        $s = $b->sis;
        $proposal = $f->propose($f->other);
        $view = $s->standingService()->view($b->student, $b->pursuit);
        match ($change) {
            'posting' => $f->move($f->second, $s->term),
            'calendar' => $s->sql->update('sis_terms', ['code' => 'NEW-LABEL'], ['id' => $f->other->value]),
            'basis' => $b->rules['gpa_basis'] = 'cumulative',
            'selected_term' => $proposal['proposal']['term_id'] = $s->term->value,
        };
        if ($change === 'basis') {
            $b->publish();
        }
        $before = $f->facts();
        try {
            $s->standingService()->review(
                $b->student,
                $b->pursuit,
                [$b->row($proposal)],
                $view['revision'],
                'Fictional stale assessment.',
                proposal: $proposal['proposal']
            );
            self::fail('An outdated or substituted term proposal was reviewed.');
        } catch (ValidationException | ConcurrentModificationException) {
            self::assertSame($before, $f->facts());
        }
    }

    public function testPostingChangesDuringFinalStandingAuditRollBackTheWholeCoupledTransaction(): void
    {
        $f = $this->fixture();
        $b = $f->base;
        $s = $b->sis;
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willReturnCallback(fn() => $f->move($f->second, $s->term));
        $service = $s->standingService($audit);
        $proposal = $f->propose($f->other);
        $review = $service->review(
            $b->student,
            $b->pursuit,
            [$b->row($proposal)],
            $service->view($b->student, $b->pursuit)['revision'],
            'Fictional final term race.',
            proposal: $proposal['proposal']
        );
        $before = $f->facts();
        try {
            $service->commit(
                $b->student,
                $b->pursuit,
                new EntityId($review['operation_id']),
                $review['rows'],
                $review['revision'],
                $review['reason'],
                $review['review_token'],
                proposal: $review['proposal']
            );
            self::fail('A final posting change escaped rollback.');
        } catch (ValidationException | ConcurrentModificationException) {
            self::assertSame($before, $f->facts());
        }
    }

    public function testTermChoicesAreBoundedAndNeedStandingDutiesRatherThanCatalogAuthority(): void
    {
        $f = $this->fixture();
        $b = $f->base;
        $s = $b->sis;
        $template = $s->sql->one('sis_terms', ['id' => $s->term->value]);
        for ($i = 0; $i < 24; $i++) {
            $s->sql->insert('sis_terms', array_replace($template, ['id' => EntityId::generate()->value,
                'code' => 'SCOPE.' . $i]));
        }
        $s->access->staff = false;
        $s->access->grants = ['sis:standing:manage', 'sis:student:read'];
        $service = $s->standingService();
        $before = $f->facts();
        $first = $service->proposalTerms($b->student, $b->pursuit, 'scope.');
        self::assertCount(20, $first['items']);
        self::assertCount(4, $service->proposalTerms(
            $b->student,
            $b->pursuit,
            'scope.',
            new EntityId($first['next_after'])
        )['items']);
        self::assertSame([], $service->proposalTerms($b->student, $b->pursuit, '%')['items']);
        self::assertSame($before, $f->facts());
        $s->access->grants = ['sis:student:read'];
        $this->expectException(\Domain\Shared\Exception\AccessDeniedException::class);
        $service->proposalTerms($b->student, $b->pursuit);
    }


    public function testRetainedRepeatRulesAreAppliedWithinTheSelectedTermWithoutBorrowingALaterTermsGrade(): void
    {
        $f = $this->fixture();
        $b = $f->base;
        $r = $b->requirements;
        $definition = $r->f->definition;
        $definition['policies']['repeat_gpa'] = 'highest_grade';
        $definition['policies']['repeat_attempt_order'] = 'academic_period';
        $view = $r->f->degrees->view($r->f->degree);
        $r->f->degrees->save(
            $r->f->degree,
            $view['revision'],
            new \Domain\DegreeAudit\ValueObject\AuditRequirementsV3($definition)
        );
        $r->version = new EntityId($r->f->publishDegree()['operation_id']);
        $r->assign();
        $f->repeat($f->first, 'A', $f->other);
        self::assertSame(2000, $f->propose($b->sis->term)['gpa_milli']);
        self::assertSame(4000, $f->propose($f->other)['gpa_milli']);
        $f->repeat($f->first, 'A', $b->sis->term);
        self::assertSame(4000, $f->propose($b->sis->term)['gpa_milli']);
        self::assertSame(3000, $f->propose($b->sis->term)['gpa_credits_milli']);
    }


    public function testVerifiedFailingTermWorkCanProduceZeroGpaWithoutUsingOtherTermsCredits(): void
    {
        $f = $this->fixture();
        $f->move($f->first, $f->other);
        $f->base->work('F');
        $proposal = $f->propose($f->base->sis->term);
        self::assertSame(0, $proposal['gpa_milli']);
        self::assertSame(3000, $proposal['gpa_credits_milli']);
        self::assertSame('REVIEW', $proposal['standing']['code']);
    }
}
