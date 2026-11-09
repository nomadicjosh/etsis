<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/HiatusFixture.php';

use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\Service\FinancialReviewService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Enum\HiatusCode;
use Domain\Student\StudentHiatus;
use Infrastructure\Persistence\Repository\SqlFinancialReviewRepository;
use PHPUnit\Framework\TestCase;

final class FinancialReviewWorkflowTest extends TestCase
{
    private function service(HiatusFixture $h, ?AuditTrail $audit = null): FinancialReviewService
    {
        $f = $h->sis;
        return new FinancialReviewService(
            new SqlFinancialReviewRepository($f->sql),
            $h->repository,
            $f->admissions,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $h->cipher,
            $f->clock
        );
    }

    private function source(HiatusFixture $h): StudentHiatus
    {
        $hiatus = new StudentHiatus(
            EntityId::generate(),
            $h->sis->student()->id,
            HiatusCode::Illness,
            new DateRange(new DateTimeImmutable('2026-08-01'))
        );
        $h->history->create($hiatus, 'Fictional sensitive medical case comment');
        return $hiatus;
    }

    public function testRegistrarRequestsOneHandOffAndSeesProgressWithoutFinanceNotesOrPrivateCaseDetails(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $source = $this->source($h);
        $service = $this->service($h);
        $events = $f->sql->rows('sis_history_events');
        $f->access->staff = false;
        $f->access->grants = ['sis:registration:manage'];
        $result = $service->request($source->studentId, $source->id);
        self::assertSame($result, $service->request($source->studentId, $source->id));
        self::assertSame($result, $service->progress($source->studentId, $source->id));
        self::assertCount(1, $f->sql->rows('sis_financial_reviews'));
        self::assertCount(1, $f->sql->rows('sis_audit', ['action' => 'finance.review_requested']));
        $f->access->grants = ['sis:finance:post'];
        $view = $service->view(new EntityId($result['id']));
        self::assertArrayNotHasKey('code', $view['source']);
        self::assertStringNotContainsString('medical', json_encode($view));
        $op = EntityId::generate();
        $service->change(new EntityId($result['id']), $op, 0, 'claim', 'Fictional private finance note');
        $f->access->grants = ['sis:registration:manage'];
        $progress = $service->progress($source->studentId, $source->id);
        self::assertSame('in_review', $progress['status']);
        self::assertArrayNotHasKey('note', $progress);
        self::assertArrayNotHasKey('assigned_to', $progress);
        self::assertSame($events, $f->sql->rows('sis_history_events'));
        $this->expectException(AccessDeniedException::class);
        $service->history(new EntityId($result['id']));
    }

    public function testFinanceClaimCompleteAndReopenAreVersionedEncryptedAndRepeatableWithoutPostingMoney(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $source = $this->source($h);
        $service = $this->service($h);
        $id = new EntityId($service->request($source->studentId, $source->id)['id']);
        $events = $f->sql->rows('sis_history_events');
        $op = EntityId::generate();
        $claimed = $service->change($id, $op, 0, 'claim', 'Fictional balance review');
        self::assertSame($claimed, $service->change($id, $op, 0, 'claim', 'Fictional balance review'));
        $completed = $service->change($id, EntityId::generate(), 1, 'complete', 'Fictional no adjustment necessary');
        self::assertSame('completed', $completed['status']);
        $service->change($id, EntityId::generate(), 2, 'reopen', 'Fictional further review');
        $history = $service->history($id)['items'];
        self::assertCount(3, $history);
        self::assertSame('Fictional further review', $history[0]['note']);
        self::assertSame('open', $service->view($id)['status']);
        self::assertSame($events, $f->sql->rows('sis_history_events'));
        self::assertStringNotContainsString('Fictional balance review', json_encode($f->sql->rows('sis_financial_review_actions')));
        self::assertStringNotContainsString('Fictional no adjustment', json_encode($f->sql->rows('sis_audit')));
    }

    public function testOtherReviewerMustClaimBeforeCompletingAndStaleReviewCannotOverwriteAssignment(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $source = $this->source($h);
        $service = $this->service($h);
        $id = new EntityId($service->request($source->studentId, $source->id)['id']);
        $service->change($id, EntityId::generate(), 0, 'claim', 'Fictional first claim');
        $f->access->actor = $f->user();
        try {
            $service->change($id, EntityId::generate(), 1, 'complete', 'Fictional wrong assignee');
            self::fail('A reviewer completed another reviewer task without claiming it.');
        } catch (DomainException) {
            self::assertSame(1, $service->view($id)['version']);
        }
        try {
            $service->change($id, EntityId::generate(), 0, 'claim', 'Fictional stale claim');
            self::fail('A stale assignment was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame(1, $service->view($id)['version']);
        }
        $service->change($id, EntityId::generate(), 1, 'claim', 'Fictional explicit takeover');
        self::assertTrue($service->view($id)['assigned_to_me']);
    }

    public function testStudentOwnerAndAcademicReaderCannotAccessStaffHandOffsOrFinanceQueue(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $source = $this->source($h);
        $service = $this->service($h);
        $id = new EntityId($service->request($source->studentId, $source->id)['id']);
        $f->access->staff = false;
        $f->access->grants = ['sis:student:read', 'sis:finance:read'];
        foreach (['request', 'progress', 'page', 'view', 'history', 'change'] as $action) {
            try {
                match ($action) {
                    'request' => $service->request($source->studentId, $source->id),
                    'progress' => $service->progress($source->studentId, $source->id),
                    'page' => $service->page(null), 'view' => $service->view($id), 'history' => $service->history($id),
                    'change' => $service->change($id, EntityId::generate(), 0, 'claim', 'Fictional denied claim'),
                };
                self::fail('A private office review was exposed.');
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
    }

    public function testHandOffCannotBeMovedToAnotherStudentAndVoidedCaseIsVisibleForFinanceFollowUp(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $source = $this->source($h);
        $service = $this->service($h);
        $other = $f->student();
        try {
            $service->request($other->id, $source->id);
            self::fail('The source case moved between students.');
        } catch (RecordNotFoundException) {
            self::assertSame([], $f->sql->rows('sis_financial_reviews'));
        }
        $result = $service->request($source->studentId, $source->id);
        $h->history->void($source->studentId, $source->id, EntityId::generate(), $source->revision(), 'Fictional duplicate');
        self::assertTrue($service->view(new EntityId($result['id']))['source']['voided']);
        self::assertSame($result, $service->request($source->studentId, $source->id));
    }

    public function testQueueAndPrivateDecisionHistoryAreBoundedAndCursorPaged(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $source = $this->source($h);
        $service = $this->service($h);
        $repository = new SqlFinancialReviewRepository($f->sql);
        $id = new EntityId($service->request($source->studentId, $source->id)['id']);
        for ($i = 0; $i < 51; ++$i) {
            $extra = new StudentHiatus(
                EntityId::generate(),
                $source->studentId,
                HiatusCode::Leave,
                new DateRange(new DateTimeImmutable('2026-08-01'))
            );
            $h->repository->add($extra);
            $repository->add(new \Domain\Finance\FinancialReview(
                EntityId::generate(),
                $source->studentId,
                $extra->id,
                $f->access->actor,
                $f->clock->now()->format(DATE_ATOM)
            ));
        }
        $page = $service->page('open');
        self::assertCount(50, $page['items']);
        $next = $service->page('open', new EntityId($page['next_after']));
        self::assertCount(2, $next['items']);
        self::assertNull($next['next_after']);
        for ($i = 0; $i < 53; ++$i) {
            $service->change($id, EntityId::generate(), $i, 'claim', 'Fictional progress ' . $i);
        }
        $history = $service->history($id);
        self::assertCount(50, $history['items']);
        $nextHistory = $service->history($id, new EntityId($history['next_after']));
        self::assertCount(3, $nextHistory['items']);
        self::assertNull($nextHistory['next_after']);
        self::assertSame([], array_intersect(array_column($history['items'], 'id'), array_column($nextHistory['items'], 'id')));
    }

    public function testLateAuditFailureRollsBackBothFinanceDecisionAndEncryptedHistory(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $source = $this->source($h);
        $id = new EntityId($this->service($h)->request($source->studentId, $source->id)['id']);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willThrowException(new RuntimeException('Fictional finance audit failure'));
        try {
            $this->service($h, $audit)->change($id, EntityId::generate(), 0, 'claim', 'Fictional failed decision');
            self::fail('A partially audited decision committed.');
        } catch (RuntimeException $error) {
            self::assertSame('Fictional finance audit failure', $error->getMessage());
        }
        self::assertSame('open', $this->service($h)->view($id)['status']);
        self::assertSame([], $f->sql->rows('sis_financial_review_actions'));
    }
}
