<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\LoadDeterminationService;
use Domain\Student\Service\LoadDeterminationLedger;
use Domain\Shared\Service\ReviewProof;
use Infrastructure\Persistence\Repository\SqlLoadDeterminationRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use Infrastructure\Persistence\Repository\SqlEnrollmentLoadRepository;
use Infrastructure\Persistence\Repository\SqlEnrollmentLoadPolicyRepository;

final class StandingLoadFixture
{
    public StandingProposalFixture $base;
    public EntityId $record;

    public function __construct()
    {
        require_once __DIR__ . '/StandingProposalFixture.php';
        $this->base = new StandingProposalFixture(false);
        $this->base->work();
        $this->publishLoad();
        $this->record = $this->record();
        $this->base->rules['load_requirement'] = ['minimum_credits_milli' => 3000,
            'maximum_credits_milli' => 3000, 'classification_code' => 'FULL'];
        $this->base->publish();
    }

    public function publishLoad(int $full = 3000): void
    {
        $service = $this->base->sis->loadPolicies();
        $review = $service->review(null, ['bands' => [
            ['code' => 'PART', 'label' => 'Fictional part time', 'min_credits_milli' => 0],
            ['code' => 'FULL', 'label' => 'Fictional full time', 'min_credits_milli' => $full],
        ]], $service->view()['revision'], 'Fictional load policy.');
        $service->publish(
            null,
            new EntityId($review['operation_id']),
            $review['definition'],
            $review['revision'],
            $review['reason'],
            $review['review_token']
        );
    }

    public function loads(): LoadDeterminationService
    {
        $s = $this->base->sis;
        $history = \EventStoreFixture::history($s->connection->queryBuilder());
        return new LoadDeterminationService(
            $s->enrollmentLoad(),
            new SqlLoadDeterminationRepository($s->sql),
            new SqlStudentRecordRepository($s->sql),
            $s->admissions,
            new SqlEnrollmentLoadRepository($s->sql),
            new SqlEnrollmentLoadPolicyRepository($s->sql),
            $history,
            $s->access,
            new class implements \Domain\Advising\Service\AdvisorProgressAccess {
                public function requireAdvisor(EntityId $student, EntityId $pursuit): EntityId
                {
                    throw new \Domain\Shared\Exception\AccessDeniedException();
                }
            },
            $s->transactions,
            $s->audit,
            $s->clock,
            new ReviewProof($s->dropCipher, $s->clock),
            new LoadDeterminationLedger(new SqlLoadDeterminationRepository($s->sql), $history)
        );
    }

    public function record(): EntityId
    {
        $f = $this->base;
        $service = $this->loads();
        $review = $service->review($f->student, $f->pursuit, $f->sis->term, 'Fictional accepted load.');
        $id = new EntityId($review['operation_id']);
        $service->confirm(
            $f->student,
            $f->pursuit,
            $f->sis->term,
            $id,
            $review['revision'],
            $review['reason'],
            $review['review_token']
        );
        return $id;
    }

    public function proposal(): array
    {
        return $this->base->sis->standingService()->propose(
            $this->base->student,
            $this->base->pursuit,
            load: $this->record
        );
    }

    public function review(array $proposal): array
    {
        $f = $this->base;
        $service = $f->sis->standingService();
        return $service->review(
            $f->student,
            $f->pursuit,
            [$f->row($proposal)],
            $service->view($f->student, $f->pursuit)['revision'],
            'Fictional retained load standing.',
            proposal: $proposal['proposal']
        );
    }

    public function commit(array $review, ?\Domain\Audit\Repository\AuditTrail $audit = null): array
    {
        $f = $this->base;
        return $f->sis->standingService($audit)->commit(
            $f->student,
            $f->pursuit,
            new EntityId($review['operation_id']),
            $review['rows'],
            $review['revision'],
            $review['reason'],
            $review['review_token'],
            proposal: $review['proposal']
        );
    }
}
