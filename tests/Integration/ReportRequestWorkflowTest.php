<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/ReportRequestFixture.php';

use Domain\Reporting\ValueObject\ReportRequestBrief;
use Domain\Reporting\ValueObject\ReportRequestChange;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class ReportRequestWorkflowTest extends TestCase
{
    private function create(ReportRequestFixture $r, ?EntityId $id = null): EntityId
    {
        $r->asRequester();
        $id ??= EntityId::generate();
        $r->service()->create($id, $this->brief());
        return $id;
    }
    private function brief(): ReportRequestBrief
    {
        return new ReportRequestBrief('Fictional fall headcount', "How many students are enrolled?\nGroup by program.", 'Plan fictional teaching resources.', '2026-09-01');
    }
    private function change(ReportRequestFixture $r, EntityId $id, string $action, string $note = 'Fictional case message'): array
    {
        return $r->service()->change($id, EntityId::generate(), $r->repository->find($id)->version, new ReportRequestChange($action, $note));
    }
    private function deliver(ReportRequestFixture $r, EntityId $id, EntityId $report): array
    {
        $plan = $r->service()->deliveryReview($id, $report);
        return $r->service()->change(
            $id,
            new EntityId($plan['operation_id']),
            $plan['version'],
            new ReportRequestChange('deliver', 'Fictional reviewed handoff', $report, $plan['delivery_revision'])
        );
    }
    public function testRequesterCreatesPrivateEncryptedQuestionWithoutAuthoringOrStudentReadAndRetriesSafely(): void
    {
        $r = new ReportRequestFixture();
        $id = $this->create($r);
        $service = $r->service();
        self::assertSame('open', $service->view($id)['status']);
        self::assertSame('Fictional fall headcount', $service->page(false, null)['items'][0]['title']);
        self::assertArrayNotHasKey('question', $service->page(false, null)['items'][0]);
        $receipt = $service->receipt($id, $id);
        $r->sis->clock->time = new DateTimeImmutable('2026-09-02'); // Creation retry remains valid after its requested date.
        self::assertSame($receipt, $service->create($id, $this->brief()));
        self::assertCount(1, $r->sis->sql->rows('sis_report_requests'));
        self::assertCount(1, $r->sis->sql->rows('sis_report_request_actions'));
        $raw = json_encode([$r->sis->sql->rows('sis_report_requests'), $r->sis->sql->rows('sis_report_request_actions'), $r->sis->sql->rows('sis_audit')], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Fictional fall', $raw);
        self::assertStringNotContainsString('How many students', $raw);
        self::assertStringNotContainsString('Plan fictional', $raw);
        self::assertFalse($service->view($id)['actions']['claim']);
        try {
            $service->page(true);
            self::fail('Requester read the team queue.');

        } catch (AccessDeniedException) {
            self::assertCount(1, $service->page(false, null)['items']);
        }
        $this->expectException(ConcurrentModificationException::class);
        $service->create($id, new ReportRequestBrief('Different', 'Changed question', 'Changed purpose'));
    }
    public function testSpecialistClaimsClarifiesAndRequesterRepliesBeforeReviewedAtomicSharing(): void
    {
        $r = new ReportRequestFixture();
        $id = $this->create($r);
        $r->asSpecialist();
        self::assertCount(1, $r->service()->page(true)['items']);
        self::assertSame('in_progress', $this->change($r, $id, 'claim', '')['status']);
        self::assertSame('waiting', $this->change($r, $id, 'clarify', 'Which academic term?')['status']);
        $r->asRequester();
        self::assertTrue($r->service()->view($id)['actions']['reply']);
        self::assertSame('in_progress', $this->change($r, $id, 'reply', 'The fictional fall term, please.')['status']);
        $r->asSpecialist();
        $report = $r->sharing->create('Fictional reviewed headcount', null, null, true);
        self::assertFalse($r->reports->isSharedWith($report->id, $r->requester));
        $result = $this->deliver($r, $id, $report->id);
        self::assertSame('delivered', $result['status']);
        self::assertSame($report->id->value, $result['report_id']);
        self::assertTrue($r->reports->isSharedWith($report->id, $r->requester));
        $r->asRequester();
        self::assertTrue($r->sharing->view($report->id)['can_run']);
        self::assertFalse($r->sharing->view($report->id)['can_manage']);
        self::assertCount(1, $r->sharing->run($report->id)['items']);
        self::assertSame(['created', 'claim', 'clarify', 'reply', 'deliver'], array_column($r->service()->history($id)['items'], 'action'));
        self::assertSame('Which academic term?', $r->service()->history($id)['items'][2]['note']);
    }
    public function testAnotherRequesterCannotReadMutateBrowseHistoryOrRecoverSomeoneElsesReceipt(): void
    {
        $r = new ReportRequestFixture();
        $id = $this->create($r);
        $r->sis->access->actor = $r->sis->user();
        foreach (
            [fn() => $r->service()->view($id), fn() => $r->service()->history($id), fn() => $r->service()->receipt($id, $id),
            fn() => $this->change($r, $id, 'note'), fn() => $r->service()->page(false, null, $id)] as $call
        ) {
            try {
                $call();
                self::fail('Another requester’s case was accessible.');

            } catch (AccessDeniedException) {
                self::assertSame([], $r->service()->page(false, null)['items']);
            }
        }
    }
    public function testReportingPermissionMustStillExistForEveryReadAndReceipt(): void
    {
        $r = new ReportRequestFixture();
        $id = $this->create($r);
        $r->sis->access->grants = [];
        foreach ([fn() => $r->service()->view($id), fn() => $r->service()->page(false), fn() => $r->service()->history($id), fn() => $r->service()->receipt($id, $id)] as $call) {
            try {
                $call();
                self::fail('Lost permissions were ignored.');

            } catch (AccessDeniedException) {
                self::assertCount(1, $r->sis->sql->rows('sis_report_requests'));
            }
        }
    }
    public function testCurrentAssignmentAndVersionPreventConflictingSpecialistActionsAndTakeoverNeedsAReason(): void
    {
        $r = new ReportRequestFixture();
        $id = $this->create($r);
        $r->asSpecialist();
        $this->change($r, $id, 'claim', '');
        $r->sis->access->actor = $r->sis->user();
        try {
            $this->change($r, $id, 'claim', '');
            self::fail('Unexplained takeover succeeded.');

        } catch (ValidationException) {
            self::assertSame(1, $r->repository->find($id)->version);
        }
        $this->change($r, $id, 'claim', 'Covering this request while the specialist is away.');
        $r->asSpecialist();
        try {
            $r->service()->change($id, EntityId::generate(), 1, new ReportRequestChange('clarify', 'Old specialist question'));
            self::fail('Stale update succeeded.');
        } catch (ConcurrentModificationException) {
            self::assertSame(2, $r->repository->find($id)->version);
        }
        $this->expectException(DomainException::class);
        $this->change($r, $id, 'clarify');
    }
    public function testNoReportIsSharedBeforeAssignmentOrWithoutOwnershipEvenForReportManagers(): void
    {
        $r = new ReportRequestFixture();
        $id = $this->create($r);
        $r->asSpecialist();
        $report = $r->sharing->create('Specialist report', null, null, null);
        try {
            $r->service()->deliveryReview($id, $report->id);
            self::fail('Unassigned manager could share.');

        } catch (DomainException) {
            self::assertFalse($r->reports->isSharedWith($report->id, $r->requester));
        }
        $other = $r->sis->user();
        $r->sis->access->actor = $other;
        $this->change($r, $id, 'claim', '');
        $this->expectException(AccessDeniedException::class);
        $r->service()->deliveryReview($id, $report->id);
    }
    public function testReportDefinitionChangesInvalidateDeliveryButUnchangedRetryDoesNotRestoreRevokedShares(): void
    {
        $r = new ReportRequestFixture();
        $id = $this->create($r);
        $r->asSpecialist();
        $this->change($r, $id, 'claim');
        $report = $r->sharing->create('Versioned fictional report', null, null, null);
        $r->sis->sql->insert('sis_report_queries', ['id' => $report->id->value, 'version' => 0, 'encrypted_content' => $r->sis->dropCipher->seal('{"fictional":true}')]);
        $plan = $r->service()->deliveryReview($id, $report->id);
        $r->sis->sql->update('sis_report_queries', ['version' => 1], ['id' => $report->id->value]);
        try {
            $r->service()->change($id, new EntityId($plan['operation_id']), 1, new ReportRequestChange('deliver', 'Reviewed', $report->id, $plan['delivery_revision']));
            self::fail('Stale definition shared.');
        } catch (ConcurrentModificationException) {
            self::assertFalse($r->reports->isSharedWith($report->id, $r->requester));
        }
        $plan = $r->service()->deliveryReview($id, $report->id);
        $op = new EntityId($plan['operation_id']);
        $change = new ReportRequestChange('deliver', 'Reviewed again', $report->id, $plan['delivery_revision']);
        $receipt = $r->service()->change($id, $op, 1, $change);
        $r->sharing->unshare($report->id, $r->requester);
        $r->sis->sql->update('sis_report_queries', ['version' => 2], ['id' => $report->id->value]);
        self::assertSame($receipt, $r->service()->change($id, $op, 1, $change));
        self::assertFalse($r->reports->isSharedWith($report->id, $r->requester));
        self::assertSame($receipt, $r->service()->receipt($id, $op));
        $r->asRequester();
        $this->expectException(AccessDeniedException::class);
        $r->service()->receipt($id, $op);
    }
    public function testAuditFailureRollsBackSharingStatusAndImmutableActionHistory(): void
    {
        $r = new ReportRequestFixture();
        $id = $this->create($r);
        $r->asSpecialist();
        $this->change($r, $id, 'claim');
        $report = $r->sharing->create('Fictional private report', null, null, null);
        $failure = new class implements \Domain\Audit\Repository\AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                throw new RuntimeException('Fictional audit failure');
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        $service = $r->service($failure);
        $plan = $service->deliveryReview($id, $report->id);
        $before = $r->sis->sql->rows('sis_audit');
        try {
            $service->change($id, new EntityId($plan['operation_id']), 1, new ReportRequestChange('deliver', 'Fictional explanation', $report->id, $plan['delivery_revision']));
            self::fail('Audit failure committed.');
        } catch (RuntimeException $e) {
            self::assertSame('Fictional audit failure', $e->getMessage());
        }
        self::assertSame('in_progress', $r->service()->view($id)['status']);
        self::assertFalse($r->reports->isSharedWith($report->id, $r->requester));
        self::assertCount(2, $r->sis->sql->rows('sis_report_request_actions'));
        self::assertSame($before, $r->sis->sql->rows('sis_audit'));
    }
    public function testCancelReopenAndLaterCommentsKeepEarlierSharesAndImmutableConversation(): void
    {
        $r = new ReportRequestFixture();
        $id = $this->create($r);
        $r->asSpecialist();
        $this->change($r, $id, 'claim');
        $report = $r->sharing->create('Fictional answer', null, null, null);
        $this->deliver($r, $id, $report->id);
        $r->asRequester();
        $this->change($r, $id, 'reopen', 'Please include a different program.');
        self::assertSame('open', $r->service()->view($id)['status']);
        self::assertTrue($r->reports->isSharedWith($report->id, $r->requester));
        self::assertSame('cancelled', $this->change($r, $id, 'cancel', 'Question no longer needed.')['status']);
        self::assertSame('cancelled', $this->change($r, $id, 'note', 'Keep this as fictional history.')['status']);
        self::assertCount(6, $r->service()->history($id)['items']);
        self::assertTrue($r->reports->isSharedWith($report->id, $r->requester));
    }
    public function testActionReplayChecksActorPayloadAndCurrentPermission(): void
    {
        $r = new ReportRequestFixture();
        $id = $this->create($r);
        $r->asSpecialist();
        $op = EntityId::generate();
        $change = new ReportRequestChange('claim', '');
        $receipt = $r->service()->change($id, $op, 0, $change);
        self::assertSame($receipt, $r->service()->change($id, $op, 0, $change));
        try {
            $r->service()->change($id, $op, 0, new ReportRequestChange('claim', 'Changed payload'));
            self::fail('Operation reference was reused.');
        } catch (ConcurrentModificationException) {
            self::assertCount(2, $r->sis->sql->rows('sis_report_request_actions'));
        }
        $r->sis->access->grants = ['sis:reporting:run'];
        $this->expectException(AccessDeniedException::class);
        $r->service()->change($id, $op, 0, $change);
    }
    public function testRequestAndHistoryPagesAreBoundedAndCursorsStayInAuthorizedScope(): void
    {
        $r = new ReportRequestFixture();
        $id = $this->create($r);
        $r->asSpecialist();
        for ($i = 0; $i < 51; $i++) {
            $this->change($r, $id, 'note', 'Fictional paged conversation ' . $i);
        }
        $first = $r->service()->history($id);
        self::assertCount(50, $first['items']);
        self::assertSame(49, $first['next_after_version']);
        $next = $r->service()->history($id, $first['next_after_version']);
        self::assertCount(2, $next['items']);
        self::assertNull($next['next_after_version']);
        for ($i = 0; $i < 50; $i++) {
            $r->service()->create(EntityId::generate(), $this->brief());
        }
        $page = $r->service()->page(true, null);
        self::assertCount(50, $page['items']);
        self::assertCount(1, $r->service()->page(true, null, new EntityId($page['next_after']))['items']);
        $anchor = new EntityId($page['next_after']);
        $this->change($r, $anchor, 'claim');
        self::assertCount(1, $r->service()->page(true, 'open', $anchor)['items']); // A claimed anchor does not break paging through the live queue.
        $r->asRequester();
        self::assertCount(1, $r->service()->page(false, null)['items']);
        $this->expectException(ValidationException::class);
        $r->service()->history($id, 999);
    }
    public function testBriefAndActionValidationRejectInvalidDatesControlCharactersAndUnreviewedDelivery(): void
    {
        $r = new ReportRequestFixture();
        $r->asRequester();
        foreach (
            [fn() => new ReportRequestBrief('', 'Question', 'Purpose'), fn() => new ReportRequestBrief('Title', "Question\0", 'Purpose'),
            fn() => new ReportRequestBrief('Title', 'Question', 'Purpose', '2026-02-30'), fn() => $r->service()->create(EntityId::generate(), new ReportRequestBrief('Title', 'Question', 'Purpose', '2026-01-01')),
            fn() => new ReportRequestChange('deliver', 'No preview', EntityId::generate()), fn() => new ReportRequestChange('note', ''), fn() => new ReportRequestChange('unexpected', 'Message')] as $call
        ) {
            try {
                $call();
                self::fail('Invalid request input accepted.');

            } catch (ValidationException) {
                self::assertSame([], $r->sis->sql->rows('sis_report_requests'));
            }
        }
    }
    public function testSharingARequestAnswerNeverGrantsAdvancedReportSourcePermissions(): void
    {
        $r = new ReportRequestFixture();
        $id = $this->create($r);
        $r->asSpecialist();
        $this->change($r, $id, 'claim');
        $f = $r->sis;
        $f->access->grants[] = 'sis:student:read';
        $relational = new \Domain\Reporting\Service\RelationalReportService(
            $r->reports,
            new \Infrastructure\Persistence\Repository\SqlRelationalReportRepository($f->sql),
            new \Infrastructure\Reporting\SqlRelationalReportSource($f->sql),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock
        );
        $report = EntityId::generate();
        $relational->save($report, EntityId::generate(), -1, 'Fictional advanced program report', [
            'dataset' => 'programs', 'columns' => [['field' => 'institutional_id', 'label' => 'Student ID', 'aggregate' => 'none']],
            'joins' => [], 'filters' => null, 'having' => null, 'distinct' => false, 'sort' => []]);
        $this->deliver($r, $id, $report);
        $r->asRequester();
        self::assertTrue($r->reports->isSharedWith($report, $r->requester));
        try {
            $relational->run($report, 0);
            self::fail('Handoff granted source-data access.');
        } catch (AccessDeniedException) {
            self::assertSame(['sis:reporting:run'], $f->access->grants);
        }
        $f->access->grants[] = 'sis:student:read';
        self::assertCount(1, $relational->run($report, 0)['items']);
    }

    public function testFailedRequestCreationLeavesNoMetadataOrConversation(): void
    {
        $r = new ReportRequestFixture();
        $r->asRequester();
        $failure = new class implements \Domain\Audit\Repository\AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                throw new RuntimeException('Fictional create failure');
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        try {
            $r->service($failure)->create(EntityId::generate(), $this->brief());
            self::fail('Failed creation committed.');
        } catch (RuntimeException $e) {
            self::assertSame('Fictional create failure', $e->getMessage());
        }
        self::assertSame([], $r->sis->sql->rows('sis_report_requests'));
        self::assertSame([], $r->sis->sql->rows('sis_report_request_actions'));
    }

    public function testHandoffRejectsUnreviewedDefinitionWhenARepeatableReadSnapshotWouldOtherwiseHideIt(): void
    {
        $r = new ReportRequestFixture();
        $id = $this->create($r);
        $r->asSpecialist();
        $this->change($r, $id, 'claim');
        $f = $r->sis;
        $report = $r->sharing->create('Fictional concurrently edited report', null, null, null);
        $f->sql->insert('sis_report_queries', ['id' => $report->id->value, 'version' => 0, 'encrypted_content' => $f->dropCipher->seal('{"fictional":true}')]);
        // Emulate the important repeatable-read behavior through ports: the first ordinary read
        // freezes the definition until transaction end, while a locking read sees a newer commit.
        $locked = false;
        $snapshot = null;
        $definitionVersion = 0;
        $requests = $this->createStub(\Domain\Reporting\Repository\ReportRequestRepository::class);
        $requests->method('lock')->willReturnCallback($r->repository->lock(...));
        $requests->method('find')->willReturnCallback(function (EntityId $key) use ($r, $f, $report, &$locked, &$snapshot) {
            if ($f->connection->pdo->inTransaction() && !$locked && $snapshot === null) {
                $snapshot = $f->sql->one('sis_report_queries', ['id' => $report->id->value]);
            }
            return $r->repository->find($key);
        });
        $requests->method('content')->willReturnCallback($r->repository->content(...));
        $requests->method('operation')->willReturnCallback($r->repository->operation(...));
        $reports = $this->createStub(\Domain\Reporting\Repository\ReportRepository::class);
        $reports->method('lock')->willReturnCallback(function (EntityId $key) use ($r, $f, &$locked, &$definitionVersion) {
            $r->reports->lock($key);
            if (!$locked) {
                $definitionVersion++;
                $f->sql->update('sis_report_queries', ['version' => $definitionVersion], ['id' => $key->value]);
            }
            $locked = true;
        });
        $reports->method('get')->willReturnCallback($r->reports->get(...));
        $queries = $this->createStub(\Domain\Reporting\Repository\RelationalReportRepository::class);
        $queries->method('get')->willReturnCallback(function (EntityId $key) use ($f, &$snapshot) {
            return $snapshot ?? $f->sql->one('sis_report_queries', ['id' => $key->value]);
        });
        $service = new \Domain\Reporting\Service\ReportRequestService(
            $requests,
            $reports,
            $queries,
            $r->sharing,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock
        );
        $plan = $service->deliveryReview($id, $report->id);
        self::assertSame(1, $plan['report']['definition_version']);
        $locked = false;
        $snapshot = null;
        try {
            $service->change($id, new EntityId($plan['operation_id']), 1, new ReportRequestChange('deliver', 'Fictional reviewed answer', $report->id, $plan['delivery_revision']));
            self::fail('An unreviewed definition was shared.');
        } catch (ConcurrentModificationException) {
            self::assertFalse($r->reports->isSharedWith($report->id, $r->requester));
        }
        self::assertSame('in_progress', $r->repository->find($id)->status);
    }
}
