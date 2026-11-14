<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__) . '/Support/StudentRequirementsFixture.php';
require_once dirname(__DIR__) . '/Support/TransferAwardFixture.php';

use DateTimeImmutable;
use Domain\AcademicRecord\Exception\WorkPursuitRequiredException;
use Domain\AcademicRecord\Service\WorkPursuitService;
use Domain\Audit\Repository\AuditTrail;
use Domain\Gradebook\GradeDefinition;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;
use StudentRequirementsFixture;
use TransferAwardFixture;

final class AcademicWorkPursuitTest extends TestCase
{
    private function assignment(SisFixture $s, EntityId $student): EntityId
    {
        return new EntityId($s->sql->rows('sis_student_programs', ['student_id' => $student->value], 1)[0]['id']);
    }

    private function another(SisFixture $s, EntityId $student): EntityId
    {
        $id = EntityId::generate();
        $s->sql->insert('sis_programs', ['id' => $id->value, 'code' => 'NEXT.' . substr($id->value, -6),
            'title' => 'Fictional second program']);
        return $s->assignProgram($student, $id, $s->term);
    }

    private function work(SisFixture $s, EntityId $student, ?EntityId $assignment = null): array
    {
        $section = $s->section(faculty: $s->access->actor);
        $enrollment = $s->service->register($student, $section->id, assignment: $assignment);
        $credit = new EntityId($s->sql->one('sis_academic_credits', ['source_id' => $enrollment->id->value])['id']);
        return [$section, $enrollment, $credit];
    }

    private function decision(SisFixture $s, EntityId $student, EntityId $credit, EntityId $target, string $action): array
    {
        return $s->workPursuits()->review($student, $credit, $target, $action, 'Fictional registrar evidence and approval.');
    }

    private function apply(SisFixture $s, EntityId $student, EntityId $credit, EntityId $target, string $action): array
    {
        $review = $this->decision($s, $student, $credit, $target, $action);
        return $s->workPursuits()->commit($student, $credit, $review['review_token']);
    }

    private function primary(SisFixture $s, EntityId $student, EntityId $assignment): void
    {
        $r = $s->admissions->student($student);
        $revision = hash('sha256', json_encode([$r->id->value, $r->personId->value,
            $r->programId->value, $r->entryTermId->value, $r->active], JSON_THROW_ON_ERROR));
        $service = $s->primaryProgramService();
        $review = $service->review($student, $assignment, $revision, 'Fictional primary pursuit change.');
        $service->commit($student, $assignment, $review['review_token']);
    }

    private function close(SisFixture $s, EntityId $student, EntityId $assignment): void
    {
        $r = $s->sql->one('sis_student_programs', ['id' => $assignment->value]);
        $revision = hash('sha256', json_encode([$r['id'], $r['student_id'], $r['program_id'],
            $r['entry_term_id'], $r['status']], JSON_THROW_ON_ERROR));
        $service = $s->programStatusService();
        $review = $service->review($student, $assignment, false, $revision, 'Fictional completed or former pursuit.');
        $service->commit($student, $assignment, $review['review_token']);
    }

    public function testNewWorkIsOwnedAndAnotherPursuitDoesNotReceiveItWithoutApproval(): void
    {
        $f = new StudentRequirementsFixture();
        $s = $f->f->sis;
        [, , $credit] = $this->work($s, $f->student);
        $next = $this->another($s, $f->student);
        $inputs = $s->academicInputs($f->service);
        self::assertSame([$credit->value], array_column($inputs->collect($f->student, $f->assignment)->items, 'id'));
        self::assertSame([], $inputs->collect($f->student, $next)->items);
        $before = $s->sql->rows('sis_academic_credits');
        $this->apply($s, $f->student, $credit, $next, 'approve_reuse');
        self::assertSame([$credit->value], array_column($inputs->collect($f->student, $next)->items, 'id'));
        self::assertSame($f->assignment->value, $s->workPursuitLedger()->load($credit)->projection()['owner_assignment_id']);
        self::assertSame($before, $s->sql->rows('sis_academic_credits'));
        $this->apply($s, $f->student, $credit, $next, 'revoke_reuse');
        self::assertSame([], $inputs->collect($f->student, $next)->items);
        self::assertCount(1, $inputs->collect($f->student, $f->assignment)->items);
    }

    public function testPrimaryChangesClosureAndRegistrationRetriesNeverMoveTheOriginalWork(): void
    {
        $f = new StudentRequirementsFixture();
        $s = $f->f->sis;
        [$section, $enrollment, $credit] = $this->work($s, $f->student);
        $next = $this->another($s, $f->student);
        $this->primary($s, $f->student, $next);
        $this->close($s, $f->student, $f->assignment);
        $before = $s->sql->rows('sis_history_events');
        self::assertSame($enrollment->id->value, $s->service->register($f->student, $section->id)->id->value);
        self::assertSame($before, $s->sql->rows('sis_history_events'));
        self::assertSame([], $s->academicInputs($f->service)->collect($f->student, $next)->items);
        $this->apply($s, $f->student, $credit, $next, 'approve_reuse');
        self::assertCount(1, $s->academicInputs($f->service)->collect($f->student, $next)->items);
        self::assertSame($f->assignment->value, $s->workPursuitLedger()->load($credit)->projection()['owner_assignment_id']);
        [, , $newCredit] = $this->work($s, $f->student);
        self::assertSame($next->value, $s->workPursuitLedger()->load($newCredit)->projection()['owner_assignment_id']);
    }

    public function testDistinctPursuitRecordsAndTransferAwardsRetainTheirOwnOwnership(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $first = $this->assignment($s, $student);
        $next = $this->another($s, $student);
        [, , $credit] = $this->work($s, $student, $next);
        self::assertSame($next->value, $s->workPursuitLedger()->load($credit)->projection()['owner_assignment_id']);
        $transfer = new TransferAwardFixture($s, $s->dropCipher, $student);
        self::assertSame($first->value, $s->workPursuitLedger()->load($transfer->credit)->projection()['owner_assignment_id']);
        $this->apply($s, $student, $transfer->credit, $next, 'approve_reuse');
        $this->primary($s, $student, $next);
        // The original award remains first-pursuit work after a primary change.
        self::assertSame($first->value, $s->workPursuitLedger()->load($transfer->credit)->projection()['owner_assignment_id']);
    }

    public function testOlderUnknownWorkBlocksAuditsUntilExplicitAttributionWithoutInventingGradeHistory(): void
    {
        $f = new StudentRequirementsFixture();
        $s = $f->f->sis;
        $old = new TransferAwardFixture($s, $s->dropCipher, $f->student, legacy: true);
        $before = $s->sql->rows('sis_history_events');
        try {
            $s->academicInputs($f->service)->collect($f->student, $f->assignment);
            self::fail('An unknown pursuit must not leak into an audit.');
        } catch (WorkPursuitRequiredException) {
            self::assertSame($before, $s->sql->rows('sis_history_events'));
        }
        $page = $s->workPursuits()->page($f->student);
        self::assertNull($page['items'][0]['owner']);
        $s->attributeWork($f->student, $old->credit, $f->assignment);
        $result = $s->academicInputs($f->service)->collect($f->student, $f->assignment);
        self::assertCount(1, $result->items);
        self::assertFalse($result->items[0]['history_recorded']);
        self::assertNull($result->items[0]['grade']);
        self::assertTrue($result->items[0]['earlier_history_unknown']);
        self::assertSame(1, $s->workPursuitLedger()->load($old->credit)->playhead());
        self::assertFalse(new \Domain\Registration\Service\PrerequisiteService($s->inputVerifier(), $s->workPursuits())
            ->met($f->student, $f->assignment, [$old->course->value]));
    }

    public function testApprovedReuseIsRequiredForRegistrationPrerequisitesAndCannotChangeGradesOrBills(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $first = $this->assignment($s, $student);
        [$prior, $enrollment, $credit] = $this->work($s, $student);
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'P', 0, true, false));
        $grade = $s->gradeCatalog()->byCode('P', $student);
        $review = $s->academicCredits()->review($student, $prior->id, $enrollment->id, new EntityId($grade['id']), 0);
        $s->academicCredits()->commit($student, $prior->id, $review['review_token']);
        $next = $this->another($s, $student);
        $section = $s->section();
        $s->registration->addPrerequisite($section->courseId, $prior->courseId);
        $this->primary($s, $student, $next);
        $this->close($s, $student, $first);
        $before = $s->sql->rows('sis_enrollments');
        try {
            $s->service->register($student, $section->id, true);
            self::fail('A past pursuit cannot satisfy a prerequisite without approval.');
        } catch (\DomainException) {
            self::assertSame($before, $s->sql->rows('sis_enrollments'));
        }
        $facts = $s->sql->rows('sis_academic_credits');
        $accounts = $s->sql->rows('sis_student_accounts');
        $this->apply($s, $student, $credit, $next, 'approve_reuse');
        self::assertSame($facts, $s->sql->rows('sis_academic_credits'));
        self::assertSame($accounts, $s->sql->rows('sis_student_accounts'));
        $s->service->register($student, $section->id, true);
        self::assertCount(2, $s->sql->rows('sis_enrollments'));
        $this->apply($s, $student, $credit, $next, 'revoke_reuse');
        $later = $s->section();
        $s->registration->addPrerequisite($later->courseId, $prior->courseId);
        $this->expectException(\DomainException::class);
        $s->service->register($student, $later->id, true);
    }

    public function testOriginalReviewRetryAfterRevocationAndExpiryDoesNotRestoreReuse(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        [, , $credit] = $this->work($s, $student);
        $next = $this->another($s, $student);
        $review = $this->decision($s, $student, $credit, $next, 'approve_reuse');
        $receipt = $s->workPursuits()->commit($student, $credit, $review['review_token']);
        $this->apply($s, $student, $credit, $next, 'revoke_reuse');
        $s->clock->time = $s->clock->time->modify('+1 hour');
        $before = $s->sql->rows('sis_history_events');
        self::assertSame($receipt, $s->workPursuits()->commit($student, $credit, $review['review_token']));
        self::assertSame($before, $s->sql->rows('sis_history_events'));
        self::assertFalse($s->workPursuitLedger()->load($credit)->appliesTo($next->value));
    }

    public function testExpiredFirstConfirmationRollsBackAllScopeEffects(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        [, , $credit] = $this->work($s, $student);
        $next = $this->another($s, $student);
        $review = $this->decision($s, $student, $credit, $next, 'approve_reuse');
        $before = $s->sql->rows('sis_history_events');
        $s->clock->time = $s->clock->time->modify('+21 minutes');
        try {
            $s->workPursuits()->commit($student, $credit, $review['review_token']);
            self::fail('An expired review was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows('sis_history_events'));
            self::assertFalse($s->workPursuitLedger()->load($credit)->appliesTo($next->value));
        }
    }

    public function testAuditFailureRollsBackEventsProjectionAndReceipt(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        [, , $credit] = $this->work($s, $student);
        $next = $this->another($s, $student);
        $review = $this->decision($s, $student, $credit, $next, 'approve_reuse');
        $audit = $this->createMock(AuditTrail::class);
        $audit->expects(self::once())->method('record')->willThrowException(new \RuntimeException('Fictional audit failure.'));
        $before = $s->sql->rows('sis_history_events');
        $receipts = $s->sql->rows('sis_history_operations');
        try {
            $s->workPursuits($audit)->commit($student, $credit, $review['review_token']);
            self::fail('Scope committed without its required audit.');
        } catch (\RuntimeException) {
            self::assertSame($before, $s->sql->rows('sis_history_events'));
            self::assertSame($receipts, $s->sql->rows('sis_history_operations'));
            self::assertSame(1, $s->workPursuitLedger()->load($credit)->playhead());
        }
    }

    public function testPermissionIsIndependentAndAnotherStudentCannotBeSelected(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        [, , $credit] = $this->work($s, $student);
        $next = $this->another($s, $student);
        $other = $s->student()->id;
        $s->access->staff = false;
        $s->access->grants = ['sis:student:read', 'sis:registration:manage'];
        try {
            $this->decision($s, $student, $credit, $next, 'approve_reuse');
            self::fail('Registration access granted reuse approval.');
        } catch (AccessDeniedException) {
            self::assertSame(1, $s->workPursuitLedger()->load($credit)->playhead());
        }
        $s->access->grants[] = WorkPursuitService::PERMISSION;
        $this->expectException(ValidationException::class);
        $this->decision($s, $student, $credit, $this->assignment($s, $other), 'approve_reuse');
    }

    public function testMissingOrChangedScopeProjectionCannotFeedAnAuditAndReplayIsBounded(): void
    {
        $f = new StudentRequirementsFixture();
        $s = $f->f->sis;
        [, , $credit] = $this->work($s, $f->student);
        try {
            $s->workPursuitLedger()->load($credit, new HistoryReadBudget(1, 1));
            self::assertTrue(true);
        } catch (HistoryReadLimitException) {
            self::fail('A one-event scope fits a one-event allowance.');
        }
        $s->sql->execute('DELETE FROM sis_work_pursuit_scopes WHERE id = ?', [$credit->value]);
        self::assertSame('inconsistent', $s->academicInputs($f->service)->collect($f->student, $f->assignment)->status->value);
        $this->expectException(CorruptHistoryException::class);
        $s->workPursuitLedger()->load($credit);
    }
    public function testCorrectionRetainsTheOldDecisionAndDoesNotApproveTheFormerOwnerImplicitly(): void
    {
        $f = new StudentRequirementsFixture();
        $s = $f->f->sis;
        [, , $credit] = $this->work($s, $f->student);
        $next = $this->another($s, $f->student);
        $first = $s->workPursuitLedger()->load($credit)->projection()['decision_id'];
        $this->apply($s, $f->student, $credit, $next, 'correct_attribution');
        $root = $s->workPursuitLedger()->load($credit);
        self::assertSame(2, $root->playhead());
        self::assertFalse($root->appliesTo($f->assignment->value));
        self::assertTrue($root->appliesTo($next->value));
        self::assertSame([], $root->projection()['reuse']);
        self::assertNotSame($first, $root->projection()['decision_id']);
        self::assertSame([], $s->academicInputs($f->service)->collect($f->student, $f->assignment)->items);
    }

    public function testReviewDoesNotWriteAndAcademicChangesInvalidateThePendingReuseConfirmation(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        [$section, $enrollment, $credit] = $this->work($s, $student);
        $next = $this->another($s, $student);
        $events = $s->sql->rows('sis_history_events');
        $review = $this->decision($s, $student, $credit, $next, 'approve_reuse');
        self::assertArrayNotHasKey('reason', $review);
        self::assertArrayNotHasKey('actor_id', $review);
        self::assertSame($events, $s->sql->rows('sis_history_events'));
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'P', 0, true, false));
        $grade = $s->gradeCatalog()->byCode('P', $student);
        $gradeReview = $s->academicCredits()->review($student, $section->id, $enrollment->id, new EntityId($grade['id']), 0);
        $s->academicCredits()->commit($student, $section->id, $gradeReview['review_token']);
        $events = $s->sql->rows('sis_history_events');
        try {
            $s->workPursuits()->commit($student, $credit, $review['review_token']);
            self::fail('Changed academic evidence must require another review.');
        } catch (ConcurrentModificationException) {
            self::assertSame($events, $s->sql->rows('sis_history_events'));
            self::assertSame(1, $s->workPursuitLedger()->load($credit)->playhead());
        }
    }

    public function testAnotherActorCannotConfirmAReviewedReuseAndAClosedRecipientCannotReceiveNewReuse(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        [, , $credit] = $this->work($s, $student);
        $next = $this->another($s, $student);
        $review = $this->decision($s, $student, $credit, $next, 'approve_reuse');
        $actor = $s->access->actor;
        $s->access->actor = $s->user();
        try {
            $s->workPursuits()->commit($student, $credit, $review['review_token']);
            self::fail('An actor-bound token was accepted by another actor.');
        } catch (AccessDeniedException) {
            self::assertSame(1, $s->workPursuitLedger()->load($credit)->playhead());
        }
        $s->access->actor = $actor;
        $this->close($s, $student, $next);
        $events = $s->sql->rows('sis_history_events');
        try {
            $s->workPursuits()->commit($student, $credit, $review['review_token']);
            self::fail('The receiving pursuit closed after review.');
        } catch (ValidationException) {
            self::assertSame($events, $s->sql->rows('sis_history_events'));
        }
    }

    public function testPrerequisiteFastPathDoesNotReadAnyAcademicHistoryAndZeroCreditPassesAreEligible(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $assignment = $this->assignment($s, $student);
        $repo = $this->createMock(\Domain\DegreeAudit\Repository\AcademicInputRepository::class);
        $repo->expects(self::never())->method('inventory');
        $verifier = new \Domain\DegreeAudit\Service\AcademicInputVerifier($repo,
            $s->academicCredits(), $s->transferCredits(), $s->enrollmentLedger());
        $service = new \Domain\Registration\Service\PrerequisiteService($verifier, $s->workPursuits());
        self::assertTrue($service->met($student, $assignment, []));
        $course = new \Domain\Catalog\Course(EntityId::generate(), 'ZERO.101', 'Fictional orientation', 0);
        $s->registration->addCourse($course);
        $section = new \Domain\Registration\Section(EntityId::generate(), $course->id, $s->term,
            $s->access->actor, 'ZERO.101.01', 20, true, true, new \Domain\Finance\ValueObject\Money(0, 'USD'));
        $s->registration->addSection($section);
        $enrollment = $s->service->register($student, $section->id);
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'P', 0, true, false));
        $grade = $s->gradeCatalog()->byCode('P', $student);
        $review = $s->academicCredits()->review($student, $section->id, $enrollment->id, new EntityId($grade['id']), 0);
        $s->academicCredits()->commit($student, $section->id, $review['review_token']);
        self::assertTrue(new \Domain\Registration\Service\PrerequisiteService($s->inputVerifier(), $s->workPursuits())
            ->met($student, $assignment, [$course->id->value]));
    }

    public function testOwnerRegistrationUsesTheCurrentPursuitWithoutGrantingOfficeApprovalAccess(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $assignment = $this->assignment($s, $student);
        $s->access->staff = false;
        [, , $credit] = $this->work($s, $student);
        self::assertSame($assignment->value, $s->workPursuitLedger()->load($credit)->projection()['owner_assignment_id']);
        $this->expectException(AccessDeniedException::class);
        $s->workPursuits()->page($student);
    }

    public function testPrivateHttpRoutesReturnOnlyCuratedChoicesAndMaskUnknownPursuitErrors(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        [, , $credit] = $this->work($s, $student);
        $container = new \Qubus\Injector\Psr11\Container(\Qubus\Injector\Config\InjectorFactory::create([
            \Qubus\Injector\Injector::STANDARD_ALIASES => [
                \Psr\Http\Message\ResponseFactoryInterface::class => \Qubus\Routing\Factories\ResponseFactory::class,
                \Qubus\Routing\Interfaces\MiddlewareResolver::class => \Qubus\Routing\Route\InjectorMiddlewareResolver::class,
            ],
        ]));
        $container->share(new \Application\Http\Controller\SisWorkPursuitController($s->workPursuits()));
        $router = new \Qubus\Routing\Router(new \Qubus\Routing\Route\RouteCollector(), $container);
        (require dirname(__DIR__, 2) . '/routes/web/sis-workflows.php')($router);
        $request = new \Qubus\Http\ServerRequest([], [], "/sis/students/{$student->value}/academic-work-pursuits/", 'POST');
        $response = new \Application\Http\Middleware\SisPrivacyMiddleware()->process(
            $request->withParsedBody([]), new class ($router) implements \Psr\Http\Server\RequestHandlerInterface {
                public function __construct(private \Qubus\Routing\Psr7Router $router) {}
                public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
                {
                    return $this->router->match($request);
                }
            });
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('private, no-store', $response->getHeaderLine('Cache-Control'));
        $json = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($credit->value, $json['items'][0]['id']);
        self::assertStringNotContainsString('actor_id', json_encode($json, JSON_THROW_ON_ERROR));
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                throw new WorkPursuitRequiredException();
            }
        };
        $response = new \Application\Http\Middleware\SisPrivacyMiddleware()->process($request, $handler);
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('records office', (string) $response->getBody());
        self::assertStringNotContainsString('Exception', (string) $response->getBody());
    }
}
