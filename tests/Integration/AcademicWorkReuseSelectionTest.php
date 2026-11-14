<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\AcademicRecord\Service\WorkPursuitService;
use Domain\Audit\Repository\AuditTrail;
use Domain\DegreeAudit\Repository\AcademicInputRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAcademicInputRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class AcademicWorkReuseSelectionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
        require_once dirname(__DIR__) . '/Support/TransferAwardFixture.php';
    }

    private function fixture(int $count = 2, bool $includeTransfer = false): array
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $s->legacyGrade(new \Domain\Gradebook\GradeDefinition(EntityId::generate(), "P", 0, true, false));
        $s->registration->configureWindow($s->term, new \Domain\Registration\RegistrationWindow(
            new \Domain\Shared\ValueObject\DateRange(
                new \DateTimeImmutable('2026-08-01'),
                new \DateTimeImmutable('2026-08-31')
            ),
            100
        ));
        $credits = $sections = $enrollments = [];
        for ($i = 0; $i < $count; $i++) {
            $section = $s->section(20, 0, $s->access->actor);
            $enrollment = $s->service->register($student, $section->id);
            $credits[] = $s->sql->one('sis_academic_credits', ['source_id' => $enrollment->id->value])['id'];
            $sections[] = $section;
            $enrollments[] = $enrollment;
        }
        if ($includeTransfer) {
            $transfer = new \TransferAwardFixture($s, $s->dropCipher, $student);
            $credits[] = $transfer->credit->value;
        }
        $old = $s->workPursuits()->activePursuit($student);
        $program = EntityId::generate();
        $s->sql->insert('sis_programs', ['id' => $program->value, 'code' => 'NEW.PROGRAM',
            'title' => 'Fictional receiving program']);
        $r = $s->admissions->student($student);
        $revision = hash('sha256', json_encode([$r->id->value, $r->personId->value, $r->programId->value,
            $r->entryTermId->value, $r->active], JSON_THROW_ON_ERROR));
        $review = $s->programAssignmentService()->reviewChange(
            $student,
            $program,
            $s->term,
            $revision,
            'Fictional registrar program change.'
        );
        $result = $s->programAssignmentService()->changeProgram($student, $review['review_token']);
        return [$s, $student, $old, new EntityId($result['assignment_id']), $credits, $sections, $enrollments];
    }

    private function service(
        SisFixture $s,
        ?AcademicInputRepository $inputs = null,
        ?AuditTrail $audit = null,
    ): WorkPursuitService {
        return new WorkPursuitService(
            $s->workPursuitLedger(),
            new \Domain\DegreeAudit\Service\AcademicInputVerifier(
                $inputs ?? new SqlAcademicInputRepository($s->sql),
                $s->academicCredits(),
                $s->transferCredits(),
                $s->enrollmentLedger()
            ),
            new SqlStudentRecordRepository($s->sql),
            $s->admissions,
            $s->primaryProgramLedger(),
            $s->programStatusLedger(),
            $s->access,
            $s->transactions,
            $s->dropCipher,
            $s->clock,
            $audit ?? $s->audit
        );
    }

    private function digest(SisFixture $s): string
    {
        $data = [];
        foreach (
            ['sis_history_events', 'sis_history_operations', 'sis_history_streams', 'sis_work_pursuit_scopes',
            'sis_academic_credits', 'sis_enrollments', 'sis_audit'] as $table
        ) {
            $data[$table] = $s->sql->rows($table);
        }
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function review(WorkPursuitService $service, EntityId $student, EntityId $assignment, array $credits): array
    {
        return $service->reviewReuse(
            $student,
            $assignment,
            $credits,
            'Fictional evidence supports these records in the new pursuit.'
        );
    }

    public function testOneReviewApprovesSelectedWorkAtomicallyWithoutMovingOriginalFactsOrRecollectingPerRecord(): void
    {
        [$s, $student, $old, $assignment, $credits] = $this->fixture(3);
        $repository = $this->createMock(AcademicInputRepository::class);
        $real = new SqlAcademicInputRepository($s->sql);
        $repository->expects(self::exactly(2))->method('inventory')->willReturnCallback($real->inventory(...));
        $service = $this->service($s, $repository);
        $before = $this->digest($s);
        $review = $this->review($service, $student, $assignment, array_reverse($credits));
        self::assertSame($before, $this->digest($s));
        $ordered = $credits;
        sort($ordered, SORT_STRING);
        self::assertSame($ordered, array_column($review['items'], 'id'));
        self::assertSame($assignment->value, $review['target']['id']);
        self::assertArrayNotHasKey('reason', $review);
        self::assertArrayNotHasKey('actor_id', $review);
        $facts = $s->sql->rows('sis_academic_credits');
        $finance = $s->financialEvents();
        $receipt = $service->commitReuse($student, $review['review_token']);
        self::assertSame(3, $receipt['approved']);
        self::assertSame($facts, $s->sql->rows('sis_academic_credits'));
        self::assertSame($finance, $s->financialEvents());
        foreach ($credits as $credit) {
            $root = $s->workPursuitLedger()->load(new EntityId($credit));
            self::assertSame($old->value, $root->projection()['owner_assignment_id']);
            self::assertTrue($root->appliesTo($assignment->value));
            self::assertSame(2, $root->playhead());
        }
        $before = $this->digest($s);
        self::assertSame($receipt, $service->commitReuse($student, $review['review_token']));
        self::assertSame($before, $this->digest($s));
    }

    public function testOriginalRetryAfterRevocationExpiryAndPursuitClosureCannotRestoreReuse(): void
    {
        [$s, $student, , $assignment, $credits] = $this->fixture();
        $service = $s->workPursuits();
        $review = $this->review($service, $student, $assignment, $credits);
        $receipt = $service->commitReuse($student, $review['review_token']);
        $revoke = $service->review(
            $student,
            new EntityId($credits[0]),
            $assignment,
            'revoke_reuse',
            'Fictional revocation.'
        );
        $service->commit($student, new EntityId($credits[0]), $revoke['review_token']);
        // Move on again using the real program-change workflow; the receiving pursuit is now closed.
        $program = EntityId::generate();
        $s->sql->insert('sis_programs', ['id' => $program->value, 'code' => 'LATER',
            'title' => 'Fictional later program']);
        $r = $s->admissions->student($student);
        $revision = hash('sha256', json_encode([$r->id->value, $r->personId->value, $r->programId->value,
            $r->entryTermId->value, $r->active], JSON_THROW_ON_ERROR));
        $change = $s->programAssignmentService()->reviewChange(
            $student,
            $program,
            $s->term,
            $revision,
            'Fictional later change.'
        );
        $s->programAssignmentService()->changeProgram($student, $change['review_token']);
        $s->clock->time = $s->clock->time->modify('+1 day');
        $before = $this->digest($s);
        self::assertSame($receipt, $service->commitReuse($student, $review['review_token']));
        self::assertSame($before, $this->digest($s));
        self::assertFalse($s->workPursuitLedger()->load(new EntityId($credits[0]))->appliesTo($assignment->value));
    }

    public function testACompetingDecisionOnTheLastRecordRollsBackTheWholeNewSelection(): void
    {
        [$s, $student, , $assignment, $credits] = $this->fixture();
        $service = $s->workPursuits();
        $review = $this->review($service, $student, $assignment, $credits);
        sort($credits, SORT_STRING);
        $last = new EntityId($credits[1]);
        $other = $service->review($student, $last, $assignment, 'approve_reuse', 'Fictional independent approval.');
        $service->commit($student, $last, $other['review_token']);
        $before = $this->digest($s);
        try {
            $service->commitReuse($student, $review['review_token']);
            self::fail('A partially stale selection committed.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->digest($s));
            self::assertSame(1, $s->workPursuitLedger()->load(new EntityId($credits[0]))->playhead());
        }
    }

    public function testFinalAuditFailureRollsBackAllApprovalsReceiptsAndEarlierAuditRows(): void
    {
        [$s, $student, , $assignment, $credits] = $this->fixture();
        $review = $this->review($s->workPursuits(), $student, $assignment, $credits);
        $audit = $this->createMock(AuditTrail::class);
        $calls = 0;
        $audit->expects(self::exactly(2))->method('record')->willReturnCallback(
            static function (EntityId $actor, string $action, EntityId $subject) use (&$calls, $s): void {
                $s->audit->record($actor, $action, $subject);
                if (++$calls === 2) {
                    throw new \RuntimeException('Fictional final audit failure.');
                }
            }
        );
        $before = $this->digest($s);
        try {
            $this->service($s, audit: $audit)->commitReuse($student, $review['review_token']);
            self::fail('Approvals committed without their final audit.');
        } catch (\RuntimeException) {
            self::assertSame($before, $this->digest($s));
        }
    }

    public function testExpiryDuringTheFinalAuditRollsBackInsteadOfAcceptingAnExpiredSelection(): void
    {
        [$s, $student, , $assignment, $credits] = $this->fixture();
        $review = $this->review($s->workPursuits(), $student, $assignment, $credits);
        $audit = $this->createMock(AuditTrail::class);
        $audit->expects(self::exactly(2))->method('record')->willReturnCallback(
            static function () use ($s): void {
                $s->clock->time = $s->clock->time->modify('+11 minutes');
            }
        );
        $before = $this->digest($s);
        try {
            $this->service($s, audit: $audit)->commitReuse($student, $review['review_token']);
            self::fail('A selection expired during confirmation.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->digest($s));
        }
    }

    public function testMaximumSelectionRemainsBoundedAndOversizedOrDuplicateSelectionCannotReadWork(): void
    {
        [$s, $student, , $assignment, $credits] = $this->fixture(20);
        $service = $s->workPursuits();
        $review = $this->review($service, $student, $assignment, $credits);
        self::assertLessThanOrEqual(65536, strlen($review['review_token']));
        self::assertSame(20, $service->commitReuse($student, $review['review_token'])['approved']);
        $repository = $this->createMock(AcademicInputRepository::class);
        $repository->expects(self::never())->method('inventory');
        foreach ([[], [$credits[0], $credits[0]], array_merge($credits, [EntityId::generate()->value])] as $invalid) {
            try {
                $this->review($this->service($s, $repository), $student, $assignment, $invalid);
                self::fail('An invalid selection was accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAnotherActorOrReadOnlyStaffCannotConfirmAnApprovalSelection(): void
    {
        [$s, $student, , $assignment, $credits] = $this->fixture();
        $review = $this->review($s->workPursuits(), $student, $assignment, $credits);
        $before = $this->digest($s);
        $s->access->actor = $s->user();
        try {
            $s->workPursuits()->commitReuse($student, $review['review_token']);
            self::fail('Another actor confirmed the selection.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $this->digest($s));
        }
        $s->access->staff = false;
        $s->access->grants = ['sis:student:read', 'sis:registration:manage'];
        $this->expectException(AccessDeniedException::class);
        $s->workPursuits()->commitReuse($student, $review['review_token']);
    }
    public function testChangedAcademicEvidenceInASelectionCannotLeaveEarlierApprovalsCommitted(): void
    {
        [$s, $student, , $assignment, $credits, $sections, $enrollments] = $this->fixture();
        $service = $s->workPursuits();
        $review = $this->review($service, $student, $assignment, $credits);
        $grade = new EntityId($s->gradeCatalog()->byCode('P', $student)['id']);
        $posting = $s->academicCredits()->review($student, $sections[1]->id, $enrollments[1]->id, $grade, 0);
        $s->academicCredits()->commit($student, $sections[1]->id, $posting['review_token']);
        $before = $this->digest($s);
        try {
            $service->commitReuse($student, $review['review_token']);
            self::fail('Changed coursework was approved using its older review.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->digest($s));
            foreach ($credits as $credit) {
                self::assertSame(1, $s->workPursuitLedger()->load(new EntityId($credit))->playhead());
            }
        }
    }

    public function testUnknownOriginalPursuitAndAnotherStudentsWorkCannotBeIncluded(): void
    {
        [$s, $student, , $assignment, $credits] = $this->fixture();
        $old = new \TransferAwardFixture($s, $s->dropCipher, $student, legacy: true);
        $before = $this->digest($s);
        try {
            $this->review($s->workPursuits(), $student, $assignment, [$credits[0], $old->credit->value]);
            self::fail('An unknown original pursuit was silently assigned during reuse.');
        } catch (ValidationException) {
            self::assertSame($before, $this->digest($s));
        }
        $other = $s->student()->id;
        $enrollment = $s->service->register($other, $s->section(20, 0)->id);
        $foreign = $s->sql->one('sis_academic_credits', ['source_id' => $enrollment->id->value])['id'];
        $before = $this->digest($s);
        try {
            $this->review($s->workPursuits(), $student, $assignment, [$credits[0], $foreign]);
            self::fail('Another student’s work was included in the selection.');
        } catch (\Domain\Shared\Exception\RecordNotFoundException) {
            self::assertSame($before, $this->digest($s));
        }
    }

    public function testAuthorityRemovedDuringConfirmationRollsBackTheSelection(): void
    {
        [$s, $student, , $assignment, $credits] = $this->fixture();
        $review = $this->review($s->workPursuits(), $student, $assignment, $credits);
        $audit = $this->createMock(AuditTrail::class);
        $audit->expects(self::exactly(2))->method('record')->willReturnCallback(
            static function () use ($s): void {
                $s->access->staff = false;
            }
        );
        $before = $this->digest($s);
        try {
            $this->service($s, audit: $audit)->commitReuse($student, $review['review_token']);
            self::fail('Revoked authority committed the selection.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $this->digest($s));
        }
    }

    public function testRealRoutesBindOneReviewedSelectionToItsStudentAndReturnPrivateResponses(): void
    {
        [$s, $student, , $assignment, $credits] = $this->fixture();
        $container = new \Qubus\Injector\Psr11\Container(\Qubus\Injector\Config\InjectorFactory::create([
            \Qubus\Injector\Injector::STANDARD_ALIASES => [
                \Psr\Http\Message\ResponseFactoryInterface::class => \Qubus\Routing\Factories\ResponseFactory::class,
                \Qubus\Routing\Interfaces\MiddlewareResolver::class =>
                    \Qubus\Routing\Route\InjectorMiddlewareResolver::class,
            ],
        ]));
        $container->share(new \Application\Http\Controller\SisWorkPursuitController($s->workPursuits()));
        $router = new \Qubus\Routing\Router(new \Qubus\Routing\Route\RouteCollector(), $container);
        (require dirname(__DIR__, 2) . '/routes/web/sis-workflows.php')($router);
        $handler = new \Qubus\Routing\Handlers\CallableRequestHandler($router->match(...));
        $base = "/sis/students/{$student->value}/academic-work-pursuits/reuse/";
        $request = new \Qubus\Http\ServerRequest([], [], $base . 'review/', 'POST');
        $response = new \Application\Http\Middleware\SisPrivacyMiddleware()->process($request->withParsedBody([
            'assignment_id' => $assignment->value, 'credit_ids' => $credits, 'reason' => 'Fictional reviewed group.'
        ]), $handler);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('private, no-store', $response->getHeaderLine('Cache-Control'));
        $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(2, $data['items']);
        $request = new \Qubus\Http\ServerRequest([], [], $base . 'commit/', 'POST');
        $response = new \Application\Http\Middleware\SisPrivacyMiddleware()->process(
            $request->withParsedBody(['review_token' => $data['review_token']]),
            $handler
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['approved']);
        $other = $s->student()->id;
        $request = new \Qubus\Http\ServerRequest(
            [],
            [],
            "/sis/students/{$other->value}/academic-work-pursuits/reuse/commit/",
            'POST'
        );
        $response = new \Application\Http\Middleware\SisPrivacyMiddleware()->process(
            $request->withParsedBody(['review_token' => $data['review_token']]),
            $handler
        );
        self::assertSame(422, $response->getStatusCode());
    }

    public function testInstitutionalAndTransferWorkShareTheSameReviewWithoutAwardingOrChargingAgain(): void
    {
        [$s, $student, $old, $assignment, $credits] = $this->fixture(1, includeTransfer: true);
        $service = $s->workPursuits();
        $review = $this->review($service, $student, $assignment, $credits);
        self::assertEqualsCanonicalizing(['institutional', 'transfer'], array_column($review['items'], 'credit_type'));
        $facts = [$s->sql->rows('sis_academic_credits'), $s->sql->rows('sis_transfer_awards'), $s->financialEvents()];
        self::assertSame(2, $service->commitReuse($student, $review['review_token'])['approved']);
        self::assertSame($facts, [
            $s->sql->rows('sis_academic_credits'), $s->sql->rows('sis_transfer_awards'), $s->financialEvents()
        ]);
        foreach ($credits as $credit) {
            $root = $s->workPursuitLedger()->load(new EntityId($credit));
            self::assertSame($old->value, $root->projection()['owner_assignment_id']);
            self::assertTrue($root->appliesTo($assignment->value));
        }
    }

    public function testExpiredUnacceptedSelectionRollsBackBeforeCollectingAcademicWorkAgain(): void
    {
        [$s, $student, , $assignment, $credits] = $this->fixture();
        $review = $this->review($s->workPursuits(), $student, $assignment, $credits);
        $repository = $this->createMock(AcademicInputRepository::class);
        $repository->expects(self::never())->method('inventory');
        $s->clock->time = $s->clock->time->modify('+20 minutes');
        $before = $this->digest($s);
        try {
            $this->service($s, $repository)->commitReuse($student, $review['review_token']);
            self::fail('An expired first confirmation committed.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->digest($s));
        }
    }

    public function testClosedReceivingPursuitIsIdentifiedAndCannotReceiveANewApprovalSelection(): void
    {
        [$s, $student, $old, , $credits] = $this->fixture();
        $service = $s->workPursuits();
        self::assertSame('closed', $service->page($student, assignment: $old)['receiving']['status']);
        $before = $this->digest($s);
        try {
            $this->review($service, $student, $old, $credits);
            self::fail('A closed receiving pursuit accepted a new review.');
        } catch (ValidationException) {
            self::assertSame($before, $this->digest($s));
        }
    }
}
