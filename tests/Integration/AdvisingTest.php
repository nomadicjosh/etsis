<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Advising\Service\AdvisorService;
use Domain\Advising\ValueObject\AdvisorAssignments;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAdvisorRepository;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class AdvisingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }
    private function service(SisFixture $s, ?\Domain\Audit\Repository\AuditTrail $audit = null): AdvisorService
    {
        return new AdvisorService(new SqlAdvisorRepository($s->sql), $s->admissions,
            new SqlStudentRecordRepository($s->sql), new SqlPersonAccountRepository($s->sql, $s->admissions,
                new \Infrastructure\Identity\RandomInstitutionalIdGenerator()), $s->programStatusLedger(),
            $s->primaryProgramLedger(), \EventStoreFixture::history($s->connection->queryBuilder()),
            $s->access, $s->transactions, $audit ?? $s->audit, $s->clock,
            new \Domain\Shared\Service\ReviewProof($s->dropCipher, $s->clock));
    }
    private function advisor(SisFixture $s): \Domain\Person\Person
    {
        $person = $s->legacyPerson($s->user(), 'Fictional', 'Advisor');
        $s->sql->insert('sis_employees', ['id' => EntityId::generate()->value, 'person_id' => $person->id->value,
            'job_title' => 'Fictional advisor', 'active' => 1]);
        return $person;
    }
    private function row(EntityId $person, string $role = 'primary'): array
    {
        return ['id' => EntityId::generate()->value, 'advisor_person_id' => $person->value, 'role' => $role,
            'starts_on' => '2026-01-01', 'ends_on' => null];
    }
    private function pursuit(SisFixture $s, EntityId $student): EntityId
    {
        return new EntityId($s->sql->rows('sis_student_programs', ['student_id' => $student->value], 1)[0]['id']);
    }
    private function confirm(AdvisorService $service, EntityId $student, EntityId $pursuit, array $rows): array
    {
        $review = $service->review($student, $pursuit, $rows, $service->view($student, $pursuit)['revision'], 'Fictional advising change.');
        $service->commit($student, $pursuit, new EntityId($review['operation_id']), $review['rows'],
            $review['revision'], $review['reason'], $review['review_token']);
        return $review;
    }
    public function testAssignmentsAreReviewedRetainedAndGiveOnlyCurrentScopedAdvisorAccess(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $pursuit = $this->pursuit($s, $student->id);
        $advisor = $this->advisor($s);
        $service = $this->service($s);
        $before = $s->sql->rows('sis_history_events');
        $draft = $service->view($student->id, $pursuit);
        self::assertSame([], $draft['rows']);
        $review = $service->review($student->id, $pursuit, [$this->row($advisor->id)], $draft['revision'], 'Fictional assignment.');
        self::assertSame($before, $s->sql->rows('sis_history_events'));
        $result = $service->commit($student->id, $pursuit, new EntityId($review['operation_id']), $review['rows'],
            $review['revision'], $review['reason'], $review['review_token']);
        self::assertSame('updated', $result['status']);
        self::assertSame($result, $service->commit($student->id, $pursuit, new EntityId($review['operation_id']), $review['rows'],
            $review['revision'], $review['reason'], $review['review_token']));
        $s->access->actor = $advisor->ownerUserId;
        $s->access->staff = false;
        self::assertSame($advisor->ownerUserId->value, $service->requireAdvisor($student->id, $pursuit)->value);
        self::assertCount(1, $service->mine()['items']);
        $this->expectException(\Domain\Shared\Exception\AccessDeniedException::class);
        $s->access->requirePermission('sis:student:read');
    }

    public function testPrimaryAndDuplicateAdvisorDateRangesCannotOverlap(): void
    {
        $first = $this->row(EntityId::generate());
        $second = $this->row(EntityId::generate());
        $first['ends_on'] = '2026-08-31';
        $second['starts_on'] = '2026-09-01';
        self::assertCount(2, new AdvisorAssignments([$first, $second])->rows);
        $second['starts_on'] = '2026-08-31';
        $this->expectException(\InvalidArgumentException::class);
        new AdvisorAssignments([$first, $second]);
    }

    public function testDatedPrimaryAndAdditionalAdvisorsGainOnlyTheirCurrentPursuit(): void
    {
        $s = new SisFixture(); $student = $s->student(); $p = $this->pursuit($s, $student->id);
        $old = $this->advisor($s); $next = $this->advisor($s); $extra = $this->advisor($s);
        $rows = [$this->row($old->id), $this->row($next->id), $this->row($extra->id, 'additional')];
        $rows[0]['ends_on'] = '2026-08-09'; $rows[1]['starts_on'] = '2026-08-10';
        $service = $this->service($s); $this->confirm($service, $student->id, $p, $rows);
        $s->access->staff = false;
        foreach ([$next, $extra] as $advisor) {
            $s->access->actor = $advisor->ownerUserId;
            self::assertSame($advisor->ownerUserId->value, $service->requireAdvisor($student->id, $p)->value);
            self::assertCount(1, $service->mine()['items']);
        }
        $s->access->actor = $old->ownerUserId;
        self::assertSame([], $service->mine()['items']);
        $this->expectException(\Domain\Shared\Exception\AccessDeniedException::class);
        $service->requireAdvisor($student->id, $p);
    }

    public function testOwnerViewsArePrivateAndDoNotExposeStaffReasonsOrReviews(): void
    {
        $s = new SisFixture(); $student = $s->student(); $p = $this->pursuit($s, $student->id);
        $service = $this->service($s); $this->confirm($service, $student->id, $p, [$this->row($this->advisor($s)->id)]);
        $s->access->staff = false;
        $before = $s->sql->rows('sis_history_events');
        $data = $service->ownerView($student->id, $p);
        self::assertCount(1, $data['items']);
        self::assertStringNotContainsString('Fictional advising change.', json_encode($data, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('intent', json_encode($data, JSON_THROW_ON_ERROR));
        self::assertSame($before, $s->sql->rows('sis_history_events'));
        $s->access->actor = $s->user(); $s->access->staff = true;
        $this->expectException(\Domain\Shared\Exception\AccessDeniedException::class);
        $service->ownerView($student->id, $p);
    }

    public function testEndingAdvisingRevokesAccessWhileOriginalRetriesPreserveLaterDecisions(): void
    {
        $s = new SisFixture(); $student = $s->student(); $p = $this->pursuit($s, $student->id);
        $staff = $s->access->actor; $advisor = $this->advisor($s); $service = $this->service($s);
        $rows = [$this->row($advisor->id)]; $first = $this->confirm($service, $student->id, $p, $rows);
        $rows[0]['ends_on'] = '2026-08-09'; $this->confirm($service, $student->id, $p, $rows);
        $before = $s->sql->rows('sis_history_events');
        $s->clock->time = $s->clock->time->modify('+1 day');
        self::assertSame(1, $service->commit($student->id, $p, new EntityId($first['operation_id']), $first['rows'],
            $first['revision'], $first['reason'], $first['review_token'])['version']);
        self::assertSame($before, $s->sql->rows('sis_history_events'));
        self::assertSame('2026-08-09', $service->view($student->id, $p)['rows'][0]['ends_on']);
        $s->access->actor = $advisor->ownerUserId; $s->access->staff = false;
        $this->expectException(\Domain\Shared\Exception\AccessDeniedException::class);
        $service->requireAdvisor($student->id, $p);
    }

    public function testProgramChangeClosesAdvisorAccessAndKeepsTheOriginalPursuitHistory(): void
    {
        $s = new SisFixture(); $student = $s->student(); $p = $this->pursuit($s, $student->id);
        $advisor = $this->advisor($s); $service = $this->service($s);
        $first = $this->confirm($service, $student->id, $p, [$this->row($advisor->id)]);
        $program = EntityId::generate();
        $s->sql->insert('sis_programs', ['id' => $program->value, 'code' => 'FICTIONAL.NEW', 'title' => 'Fictional next program']);
        $revision = hash('sha256', json_encode([$student->id->value, $student->personId->value,
            $student->programId->value, $student->entryTermId->value, $student->active], JSON_THROW_ON_ERROR));
        $review = $s->programAssignmentService()->reviewChange($student->id, $program, $s->term, $revision, 'Fictional program change.');
        $new = $s->programAssignmentService()->changeProgram($student->id, $review['review_token']);
        self::assertFalse($service->view($student->id, $p)['editable']);
        self::assertSame([], $service->view($student->id, new EntityId($new['assignment_id']))['rows']);
        self::assertSame(1, $service->commit($student->id, $p, new EntityId($first['operation_id']), $first['rows'],
            $first['revision'], $first['reason'], $first['review_token'])['version']);
        $s->access->actor = $advisor->ownerUserId; $s->access->staff = false;
        self::assertSame([], $service->mine()['items']);
        $this->expectException(\Domain\Shared\Exception\AccessDeniedException::class);
        $service->requireAdvisor($student->id, $p);
    }

    public function testProjectionDriftCannotGrantAnotherPersonAdvisingAccess(): void
    {
        $s = new SisFixture(); $student = $s->student(); $p = $this->pursuit($s, $student->id);
        $advisor = $this->advisor($s); $other = $this->advisor($s); $service = $this->service($s);
        $this->confirm($service, $student->id, $p, [$this->row($advisor->id)]);
        $s->sql->update('sis_student_advisors', ['advisor_person_id' => $other->id->value], ['pursuit_id' => $p->value]);
        $s->access->actor = $other->ownerUserId; $s->access->staff = false;
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $service->requireAdvisor($student->id, $p);
    }

    public function testFinalAuditRevocationRollsBackTheDecisionAndProjectionTogether(): void
    {
        $s = new SisFixture(); $student = $s->student(); $p = $this->pursuit($s, $student->id);
        $advisor = $this->advisor($s);
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($s, $advisor): void {
            $s->sql->update('sis_employees', ['active' => 0], ['person_id' => $advisor->id->value]);
        });
        $service = $this->service($s, $audit); $before = $s->sql->rows('sis_history_events');
        $review = $service->review($student->id, $p, [$this->row($advisor->id)], $service->view($student->id, $p)['revision'], 'Fictional final recheck.');
        try {
            $service->commit($student->id, $p, new EntityId($review['operation_id']), $review['rows'],
                $review['revision'], $review['reason'], $review['review_token']);
            self::fail('A revoked advisor was accepted after the final audit.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, $s->sql->rows('sis_history_events'));
            self::assertSame([], $s->sql->rows('sis_student_advisors'));
            self::assertSame(1, (int) $s->sql->rows('sis_employees', ['person_id' => $advisor->id->value], 1)[0]['active']);
        }
    }

    public function testPendingReviewsRejectExpiryAndChangedDates(): void
    {
        $s = new SisFixture(); $student = $s->student(); $p = $this->pursuit($s, $student->id);
        $service = $this->service($s); $rows = [$this->row($this->advisor($s)->id)];
        $first = $service->review($student->id, $p, $rows, $service->view($student->id, $p)['revision'], 'Fictional pending review.');
        $this->confirm($service, $student->id, $p, $rows);
        try {
            $service->commit($student->id, $p, new EntityId($first['operation_id']), $first['rows'],
                $first['revision'], $first['reason'], $first['review_token']);
            self::fail('A stale pending review replaced later advising.');
        } catch (\Domain\Shared\Exception\ConcurrentModificationException) { self::assertTrue(true); }
        $rows[0]['ends_on'] = '2026-12-31';
        $review = $service->review($student->id, $p, $rows, $service->view($student->id, $p)['revision'], 'Fictional updated date.');
        $s->clock->time = $s->clock->time->modify('+20 minutes');
        $this->expectException(\Domain\Shared\Exception\ConcurrentModificationException::class);
        $service->commit($student->id, $p, new EntityId($review['operation_id']), $review['rows'],
            $review['revision'], $review['reason'], $review['review_token']);
    }

    public function testAssignedAdvisorReadsTheRetainedProgramAndCanonicalDegreeProgressWithoutEditingRights(): void
    {
        require_once dirname(__DIR__) . '/Support/StudentRequirementsFixture.php';
        require_once dirname(__DIR__) . '/Support/DegreeProgressFixture.php';
        $f = new \StudentRequirementsFixture(); $s = $f->f->sis; $f->assign();
        $advisor = $this->advisor($s); $service = $this->service($s);
        $this->confirm($service, $f->student, $f->assignment, [$this->row($advisor->id)]);
        $requirements = $f->service(advisors: $service);
        $specializations = $s->specializations($f->f->history, $service);
        $reader = \DegreeProgressFixture::service($s, $f->f->history, $requirements,
            $f->f->catalog->fixture->cipher, specializations: $specializations);
        $expected = new \Domain\Portal\ValueObject\StudentDegreeProgress($reader->student($f->student, $f->assignment))->describe();
        $s->access->actor = $advisor->ownerUserId; $s->access->staff = false;
        $before = $s->sql->rows('sis_history_events');
        $result = new \Domain\Portal\ValueObject\StudentDegreeProgress($reader->student($f->student, $f->assignment))->describe();
        self::assertSame($expected, $result);
        self::assertArrayHasKey('requirements', $requirements->view($f->student, $f->assignment));
        self::assertSame($before, $s->sql->rows('sis_history_events'));
        $this->expectException(\Domain\Shared\Exception\AccessDeniedException::class);
        $requirements->review($f->student, $f->assignment, $f->f->degree, $f->version,
            new \Domain\DegreeAudit\ValueObject\PursuitStart('2026-01-31'), 'Fictional forbidden edit.');
    }

    public function testAdvisingManagementRequiresItsOwnDutyAndStudentReadTogether(): void
    {
        $s = new SisFixture(); $student = $s->student(); $p = $this->pursuit($s, $student->id); $service = $this->service($s);
        $s->access->staff = false;
        foreach ([[AdvisorService::PERMISSION], ['sis:student:read']] as $grants) {
            $s->access->grants = $grants;
            try { $service->view($student->id, $p); self::fail('One duty granted advising maintenance.'); }
            catch (\Domain\Shared\Exception\AccessDeniedException) { self::assertTrue(true); }
        }
        $s->access->grants = [AdvisorService::PERMISSION, 'sis:student:read'];
        self::assertTrue($service->view($student->id, $p)['editable']);
    }
}
