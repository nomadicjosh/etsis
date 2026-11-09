<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/HiatusFixture.php';

use Domain\Admissions\Service\AdmissionsService;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\CatalogYear;
use Domain\Catalog\Service\ProgramCatalogService;
use Domain\Catalog\Service\InitialCatalogEnrollment;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\StudentCatalogService;
use Domain\Student\Service\StudentRecordService;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\Repository\SqlCatalogYearRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;

final class CatalogYearWorkflowTest extends TestCase
{
    private function admin(HiatusFixture $h): ProgramCatalogService
    {
        $f = $h->sis;
        return new ProgramCatalogService(
            new SqlCatalogYearRepository($f->sql),
            new SqlStudentRecordRepository($f->sql),
            $f->access,
            $f->transactions,
            $f->audit
        );
    }
    private function registrar(HiatusFixture $h): StudentCatalogService
    {
        $f = $h->sis;
        return new StudentCatalogService(
            new SqlCatalogYearRepository($f->sql),
            new SqlStudentRecordRepository($f->sql),
            $f->access,
            $f->transactions,
            $f->audit,
            $h->cipher,
            $f->clock
        );
    }
    private function year(HiatusFixture $h, string $code, string $start, string $end): CatalogYear
    {
        $year = new CatalogYear(
            EntityId::generate(),
            $code,
            'Fictional catalog ' . $code,
            new DateRange(new DateTimeImmutable($start), new DateTimeImmutable($end))
        );
        $h->sis->sql->insert('sis_catalog_years', ['id' => $year->id->value, 'code' => $code, 'title' => 'Historical cohort', 'starts_on' => $start, 'ends_on' => $end, 'published' => 1]);
        $h->sis->sql->insert('sis_academic_years', ['id' => $year->id->value, 'start_year' => (int) $code, 'end_year' => (int) $code + 1, 'code' => $code . '-' . ((int) $code + 1), 'starts_on' => $start, 'ends_on' => $end, 'legacy' => 1]);
        return $year;
    }
    private function release(HiatusFixture $h, CatalogYear $year, ?EntityId $program = null): EntityId
    {
        $program ??= $h->sis->program;
        $id = EntityId::generate();
        $admin = $this->admin($h);
        $admin->createRelease($id, $program, $year->id);
        $admin->publishRelease($program, $id);
        return $id;
    }
    private function student(HiatusFixture $h, string $start, ?EntityId $program = null): \Domain\Student\Student
    {
        $f = $h->sis;
        $term = EntityId::generate();
        $f->sql->insert('sis_terms', ['id' => $term->value, 'code' => 'T.' . substr($term->value, -12),
            'starts_on' => $start, 'ends_on' => new DateTimeImmutable($start)->modify('+4 months')->format('Y-m-d'),
            'add_drop_ends_on' => $start]);
        $q = $f->sql->quote(...);
        $years = $f->sql->fetch('SELECT * FROM ' . $q('sis_academic_years') . ' WHERE ' . $q('starts_on') . ' <= ? AND ' . $q('ends_on') . ' >= ?', [$start, $start], 2);
        if (count($years) === 1) {
            $f->sql->insert('sis_term_calendar', ['id' => $term->value, 'academic_year_id' => $years[0]['id'], 'term_type_id' => null, 'term_type_version' => null, 'name' => 'Historical fixture term', 'kind' => 'non_standard', 'version' => 0]);
        }
        $service = new AdmissionsService(
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->audit,
            new RandomInstitutionalIdGenerator(),
            $f->catalogEnrollment()
        );
        $person = $service->createPerson($f->user(), 'Alex', 'Example');
        $application = $service->apply($person->id, $program ?? $f->program, $term);
        $service->decide($application->id, true);
        return $service->matriculate($application->id);
    }
    private function assignment(HiatusFixture $h, EntityId $student): EntityId
    {
        return new EntityId($h->sis->sql->rows('sis_student_programs', ['student_id' => $student->value], 1)[0]['id']);
    }

    public function testStudentsKeepTheirMatriculationCatalogWhenLaterYearsAndRevisionsArePublished(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $repo = new SqlCatalogYearRepository($f->sql);
        $year24 = $this->year($h, '2024', '2024-08-01', '2025-07-31');
        $catalog24 = $this->release($h, $year24);
        $student24 = $this->student($h, '2024-08-20');
        $assignment24 = $this->assignment($h, $student24->id);
        self::assertSame($catalog24->value, $repo->binding($assignment24)['catalog_id']);
        $year25 = $this->year($h, '2025', '2025-08-01', '2026-07-31');
        $catalog25 = $this->release($h, $year25);
        $student25 = $this->student($h, '2025-08-20');
        self::assertSame($catalog25->value, $repo->binding($this->assignment($h, $student25->id))['catalog_id']);
        self::assertSame($catalog24->value, $repo->binding($assignment24)['catalog_id']);
        $updated24 = $this->release($h, $year24);
        $lateStudent24 = $this->student($h, '2024-09-01');
        self::assertSame($updated24->value, $repo->binding($this->assignment($h, $lateStudent24->id))['catalog_id']);
        self::assertSame($catalog24->value, $repo->binding($assignment24)['catalog_id']);
        self::assertTrue($repo->release($catalog24)['published']);
        self::assertFalse($repo->release($catalog24)['is_current']);
        self::assertSame('2024-2025', new SqlStudentRecordRepository($f->sql)->primaryCatalog($student24->id)['year_code']);
    }

    public function testMissingAndAmbiguousCohortsRemainUnassignedInsteadOfGuessingOrBackfilling(): void
    {
        $h = new HiatusFixture();
        $repo = new SqlCatalogYearRepository($h->sis->sql);
        $before = $this->student($h, '2024-08-20');
        $this->release($h, $this->year($h, '2024', '2024-08-01', '2025-07-31'));
        self::assertNull($repo->binding($this->assignment($h, $before->id)));
        $this->release($h, $this->year($h, '2025', '2024-08-01', '2025-07-31'));
        $ambiguous = $this->student($h, '2024-08-21');
        self::assertNull($repo->binding($this->assignment($h, $ambiguous->id)));
    }

    public function testCatalogChangeIsReasonedEncryptedRepeatableAndDoesNotChangeAcademicOrFinancialHistory(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $a = $this->release($h, $this->year($h, '2024', '2024-08-01', '2025-07-31'));
        $b = $this->release($h, $this->year($h, '2025', '2025-08-01', '2026-07-31'));
        $student = $this->student($h, '2024-09-01');
        $assignment = $this->assignment($h, $student->id);
        $service = $this->registrar($h);
        $op = EntityId::generate();
        $events = $f->sql->rows('sis_history_events');
        $credits = $f->sql->rows('sis_academic_credits');
        $note = 'Fictional approved change <script>example</script>';
        $service->change($student->id, $assignment, $b, $op, 0, $note);
        $service->change($student->id, $assignment, $b, $op, 0, $note);
        self::assertSame($b->value, $service->view($student->id, $assignment)['current']['id']);
        self::assertCount(2, $service->history($student->id, $assignment)['items']);
        self::assertSame($note, $service->history($student->id, $assignment)['items'][0]['note']);
        self::assertStringNotContainsString($note, json_encode($f->sql->rows('sis_student_catalog_changes')));
        self::assertStringNotContainsString($note, json_encode($f->sql->rows('sis_audit')));
        $service->change($student->id, $assignment, $a, EntityId::generate(), 1, 'Fictional reversal of reference');
        $service->change($student->id, $assignment, $b, $op, 0, $note);
        self::assertSame($a->value, $service->view($student->id, $assignment)['current']['id']);
        self::assertSame($student->entryTermId->value, $f->admissions->student($student->id)->entryTermId->value);
        self::assertSame($events, $f->sql->rows('sis_history_events'));
        self::assertSame($credits, $f->sql->rows('sis_academic_credits'));
        $this->expectException(ConcurrentModificationException::class);
        $service->change($student->id, $assignment, $b, EntityId::generate(), 0, 'Fictional stale reference');
    }

    public function testAnotherProgramCannotSupplyItsCatalogAndStudentReadersCannotChangeOrReadReasons(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $year = $this->year($h, '2024', '2024-08-01', '2025-07-31');
        $other = EntityId::generate();
        $f->sql->insert('sis_programs', ['id' => $other->value, 'code' => 'BA.ART', 'title' => 'Art']);
        $wrong = $this->release($h, $year, $other);
        $student = $this->student($h, '2024-09-01');
        $assignment = $this->assignment($h, $student->id);
        $service = $this->registrar($h);
        try {
            $service->change($student->id, $assignment, $wrong, EntityId::generate(), 0, 'Fictional wrong program');
            self::fail('A different program supplied the assigned curriculum.');
        } catch (ValidationException) {
            self::assertSame($f->program->value, $service->view($student->id, $assignment)['current']['program_id']);
        }
        $another = $this->student($h, '2024-09-01');
        try {
            $service->view($another->id, $assignment);
            self::fail('A nested assignment moved to another student.');
        } catch (RecordNotFoundException) {
            self::assertTrue(true);
        }
        $f->access->staff = false;
        $f->access->grants = ['sis:student:read'];
        $this->expectException(AccessDeniedException::class);
        $service->history($student->id, $assignment);
    }

    public function testAnAdditionalProgramGetsItsOwnCatalogWithoutReplacingTheOriginalProgramCatalog(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $repo = new SqlCatalogYearRepository($f->sql);
        $year24 = $this->year($h, '2024', '2024-08-01', '2025-07-31');
        $a = $this->release($h, $year24);
        $student = $this->student($h, '2024-09-01');
        $first = $this->assignment($h, $student->id);
        $other = EntityId::generate();
        $f->sql->insert('sis_programs', ['id' => $other->value, 'code' => 'BA.ART', 'title' => 'Art']);
        $b = $this->release($h, $year24, $other);
        $records = new StudentRecordService(
            new SqlStudentRecordRepository($f->sql),
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->catalogEnrollment(),
            $f->activityLedger(),
            $f->programStatusLedger()
        );
        $second = $records->assign($student->id, $other, $student->entryTermId);
        self::assertSame($a->value, $repo->binding($first)['catalog_id']);
        self::assertSame($b->value, $repo->binding($second)['catalog_id']);
        self::assertSame('2024-2025', $records->programs($student->id)['items'][0]['catalog_year']);
    }

    public function testCatalogAssignmentAuditFailureRollsBackTheEntireMatriculation(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $this->release($h, $this->year($h, '2026', '2026-08-01', '2027-07-31'));
        $year = $f->sql->one('sis_academic_years', ['start_year' => 2026]);
        $f->sql->insert('sis_term_calendar', ['id' => $f->term->value, 'academic_year_id' => $year['id'], 'term_type_id' => null, 'term_type_version' => null, 'name' => 'Historical fall', 'kind' => 'non_standard', 'version' => 0]);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willThrowException(new RuntimeException('Fictional catalog audit failure'));
        $initializer = new InitialCatalogEnrollment(new SqlCatalogYearRepository($f->sql), $audit, $f->clock);
        $service = new AdmissionsService($f->admissions, $f->access, $f->transactions, $f->audit, new RandomInstitutionalIdGenerator(), $initializer);
        $person = $service->createPerson($f->user(), 'Alex', 'Example');
        $app = $service->apply($person->id, $f->program, $f->term);
        $service->decide($app->id, true);
        try {
            $service->matriculate($app->id);
            self::fail('A partially assigned student matriculated.');
        } catch (RuntimeException $e) {
            self::assertSame('Fictional catalog audit failure', $e->getMessage());
        }
        self::assertSame([], $f->sql->rows('sis_students'));
        self::assertSame([], $f->sql->rows('sis_student_catalogs'));
        self::assertSame('accepted', $f->admissions->application($app->id)->status->value);
    }
}
