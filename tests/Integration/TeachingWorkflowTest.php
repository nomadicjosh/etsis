<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/SisFixture.php';

use Application\Http\Controller\SisOperationsController;
use Domain\Access\Repository\AccessRepository;
use Domain\Communications\Repository\CampaignRepository;
use Domain\Communications\Service\CampaignService;
use Domain\Communications\Service\MailTransport;
use Domain\Communications\Service\MessageCipher;
use Domain\Gradebook\Assignment;
use Domain\Gradebook\Service\GradebookService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Workspace\Service\OperationsService;
use Infrastructure\Persistence\Repository\SqlGradebookRepository;
use Infrastructure\Persistence\Repository\SqlOperationsRepository;
use PHPUnit\Framework\TestCase;
use Qubus\Http\ServerRequest;

final class TeachingWorkflowTest extends TestCase
{
    private function gradebook(SisFixture $f): GradebookService
    {
        return new GradebookService(new SqlGradebookRepository($f->sql), $f->registration, $f->admissions,
            $f->access, $f->transactions, $f->audit);
    }

    private function operations(SisFixture $f): OperationsService
    {
        $permissions = $this->createStub(AccessRepository::class);
        $permissions->method('allows')->willReturnCallback(static fn (string $id, string $name): bool =>
            in_array($name, $f->access->grants, true));
        $transport = $this->createMock(MailTransport::class);
        $transport->expects(self::never())->method('send');
        $campaigns = new CampaignService($this->createStub(CampaignRepository::class),
            $this->createStub(MessageCipher::class), $transport, $f->access, $f->transactions, $f->audit);

        return new OperationsService(new SqlOperationsRepository($f->sql), $f->access, $permissions,
            $f->registration, $f->admissions, $campaigns);
    }

    public function testAssignmentEditsPreserveScoresRejectStaleWritesAndRespectFaculty(): void
    {
        $f = new SisFixture();
        $student = $f->student($f->user());
        $section = $f->section(20, 0, $f->access->actor);
        $f->service->register($student->id, $section->id, true);
        $service = $this->gradebook($f);
        $assignment = new Assignment(EntityId::generate(), $section->id, 'Fictional quiz', 1000,
            new DateTimeImmutable('2026-08-11'));
        $service->addAssignment($assignment);
        $service->score($assignment->id, $student->id, 825, 0);
        $record = $service->assignment($assignment->id);
        try {
            $service->editAssignment($assignment->id, 'Changed title', 800, new DateTimeImmutable('2026-08-12'), $record['revision']);
            self::fail('Possible points were reduced below an earned score.');
        } catch (ValidationException $error) {
            self::assertSame('possible_hundredths', $error->errors[0]['field']);
        }
        $service->editAssignment($assignment->id, 'Updated quiz', 1200, new DateTimeImmutable('2026-08-12'), $record['revision']);
        self::assertSame(825, $service->scorePage($student->id, $section->id)['items'][0]['earned_hundredths']);
        self::assertSame(1200, $service->scorePage($student->id, $section->id)['items'][0]['possible_hundredths']);
        try {
            $service->editAssignment($assignment->id, 'Stale edit', 1000, new DateTimeImmutable('2026-08-11'), $record['revision']);
            self::fail('A stale assignment edit was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame('Updated quiz', $service->assignment($assignment->id)['title']);
        }
        $f->access->actor = $f->user();
        $f->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $service->assignment($assignment->id);
    }

    public function testAttendanceCorrectionsAreVersionedOwnerReadableAndNotWritableByStudent(): void
    {
        $f = new SisFixture();
        $owner = $f->user(); $student = $f->student($owner);
        $section = $f->section(20, 0, $f->access->actor);
        $f->service->register($student->id, $section->id, true);
        $service = $this->gradebook($f); $day = new DateTimeImmutable('2026-08-11');
        $service->attendance($student->id, $section->id, $day, 'present');
        $service->attendance($student->id, $section->id, $day, 'present');
        self::assertCount(1, $f->sql->rows('sis_attendance'));
        $history = $service->attendanceHistory($student->id, $section->id);
        self::assertSame(1, $history['counts']['present']);
        $revision = $history['items'][0]['revision'];
        $service->attendance($student->id, $section->id, $day, 'late', $revision);
        try {
            $service->attendance($student->id, $section->id, $day, 'absent', $revision);
            self::fail('A stale attendance correction was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame(1, $service->attendanceHistory($student->id, $section->id)['counts']['late']);
        }
        $f->access->actor = $owner; $f->access->staff = false;
        self::assertSame('late', $service->attendanceHistory($student->id, $section->id)['items'][0]['status']);
        $this->expectException(AccessDeniedException::class);
        $service->attendance($student->id, $section->id, $day, 'absent');
    }

    public function testGradebookPagesAreBoundedAndPreserveUngradedVersusZero(): void
    {
        $f = new SisFixture();
        $student = $f->student(); $section = $f->section(20, 0, $f->access->actor);
        $f->service->register($student->id, $section->id, true);
        $service = $this->gradebook($f);
        for ($i = 0; $i < 55; ++$i) {
            $id = EntityId::generate();
            $service->addAssignment(new Assignment($id, $section->id, 'Assignment ' . $i, 1000,
                new DateTimeImmutable('2026-08-11')));
            if ($i === 0) $service->score($id, $student->id, 0, 0);
        }
        $first = $service->scorePage($student->id, $section->id);
        $second = $service->scorePage($student->id, $section->id, new EntityId($first['next_after']));
        self::assertCount(50, $first['items']); self::assertCount(5, $second['items']);
        self::assertCount(55, array_unique(array_column([...$first['items'], ...$second['items']], 'assignment_id')));
        self::assertSame(0, $first['items'][0]['earned_hundredths']);
        self::assertNull($first['items'][1]['earned_hundredths']);
    }

    public function testRosterDownloadIsOneAuthorizedPageWithSpreadsheetFormulaProtection(): void
    {
        $f = new SisFixture();
        $student = $f->student($f->user()); $section = $f->section(100, 0, $f->access->actor);
        $f->service->register($student->id, $section->id, true);
        $f->sql->update('sis_persons', ['given_name' => '=1+1'], ['id' => $student->personId->value]);
        $f->sql->update('sis_person_identifiers', ['institutional_id' => '00001234'], ['person_id' => $student->personId->value]);
        $controller = new SisOperationsController($this->operations($f));
        $request = new ServerRequest([], [], '/sis/ui/exports/roster/' . $section->id->value . '/', 'POST')->withParsedBody([]);
        $response = $controller->rosterCsv($request, $section->id->value);
        $csv = (string) $response->getBody();
        self::assertStringContainsString("'=1+1", $csv);
        self::assertStringContainsString('00001234', $csv);
        self::assertStringContainsString('Final grade', $csv);
        self::assertStringNotContainsString($student->id->value, $csv);
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        for ($i = 0; $i < 54; ++$i) {
            $f->service->register($f->student($f->user())->id, $section->id, true);
        }
        $first = $this->operations($f)->page('roster', $section->id);
        $reader = League\Csv\Reader::fromString((string) $controller->rosterCsv($request, $section->id->value)->getBody());
        $reader->setHeaderOffset(0);
        self::assertCount(50, iterator_to_array($reader->getRecords()));
        $reader = League\Csv\Reader::fromString((string) $controller->rosterCsv(
            $request->withParsedBody(['after' => $first['next_after']]), $section->id->value)->getBody());
        $reader->setHeaderOffset(0);
        self::assertCount(5, iterator_to_array($reader->getRecords()));
        $f->access->actor = $f->user(); $f->access->staff = false;
        $f->access->grants = ['admin:dashboard', 'sis:registration:manage'];
        self::assertStringNotContainsString('Final grade', (string) $controller->rosterCsv($request, $section->id->value)->getBody());
        $f->access->grants = ['admin:dashboard'];
        $this->expectException(AccessDeniedException::class);
        $controller->rosterCsv($request, $section->id->value);
    }
    public function testScoreListsAndOverlapChecksUseBoundedQueries(): void
    {
        $connection = new AcademicCountingConnection(['driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:']);
        $f = new SisFixture($connection);
        $student = $f->student(); $section = $f->section(20, 0, $f->access->actor);
        $f->service->register($student->id, $section->id, true);
        $repository = new SqlGradebookRepository($f->sql);
        for ($i = 0; $i < 60; ++$i) {
            $repository->addAssignment(new Assignment(EntityId::generate(), $section->id,
                'Fictional assignment ' . $i, 1000, new DateTimeImmutable('2026-08-11')));
        }
        $pdo = $connection->pdo;
        $pdo->prepares = 0;
        self::assertCount(51, $repository->scores($student->id, $section->id, null, 51));
        self::assertSame(1, $pdo->prepares);
        $bookings = new \Infrastructure\Persistence\Repository\SqlBookingRepository($f->sql);
        $room = EntityId::generate(); $bookings->addRoom($room, 'Fictional room', 20);
        $slot = new \Domain\Scheduling\TimeSlot(new DateTimeImmutable('2026-08-11T10:00:00Z'),
            new DateTimeImmutable('2026-08-11T11:00:00Z'));
        $pdo->prepares = 0;
        self::assertFalse($bookings->conflicts($room, $slot));
        self::assertSame(1, $pdo->prepares);
        $times = new \Infrastructure\Persistence\Repository\SqlTimeRepository($f->sql);
        $employee = EntityId::generate(); $times->addEmployee($employee, $student->personId, 'Fictional assistant');
        $pdo->prepares = 0;
        $times->add(new \Domain\HumanResources\TimeEntry(EntityId::generate(), $employee, $slot));
        self::assertSame(2, $pdo->prepares);
    }

    public function testScoringReloadsAssignmentAndFacultyStateAfterAcquiringLocks(): void
    {
        $f = new SisFixture();
        $student = $f->student($f->user()); $section = $f->section(20, 0, $f->access->actor);
        $f->service->register($student->id, $section->id, true);
        $repo = new SqlGradebookRepository($f->sql);
        $assignment = new Assignment(EntityId::generate(), $section->id, 'Fictional quiz', 1000,
            new DateTimeImmutable('2026-08-11'));
        $repo->addAssignment($assignment);
        $registration = $this->createStub(\Domain\Registration\Repository\RegistrationRepository::class);
        $registration->method('section')->willReturn($section);
        $registration->method('enrollment')->willReturn($f->registration->enrollment($student->id, $section->id));
        $registration->method('lockSection')->willReturnCallback(static function () use ($repo, $assignment): void {
            // A preceding writer lowered the maximum while this request waited for its lock.
            $repo->saveAssignment(new Assignment($assignment->id, $assignment->sectionId, $assignment->title, 500,
                $assignment->dueOn));
        });
        $service = new GradebookService($repo, $registration, $f->admissions, $f->access, $f->transactions, $f->audit);
        try {
            $service->score($assignment->id, $student->id, 800, 0);
            self::fail('A score exceeded the maximum committed by the preceding writer.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $f->sql->rows('sis_scores'));
        }

        $replacementFaculty = $f->user();
        $f->access->staff = false;
        $registration = $this->createStub(\Domain\Registration\Repository\RegistrationRepository::class);
        $registration->method('section')->willReturnCallback(fn () => $f->registration->section($section->id));
        $registration->method('enrollment')->willReturn($f->registration->enrollment($student->id, $section->id));
        $registration->method('lockSection')->willReturnCallback(static function () use ($f, $section, $replacementFaculty): void {
            $f->sql->update('sis_sections', ['faculty_user_id' => $replacementFaculty->value], ['id' => $section->id->value]);
        });
        $service = new GradebookService($repo, $registration, $f->admissions, $f->access, $f->transactions, $f->audit);
        try {
            $service->score($assignment->id, $student->id, 100, 0);
            self::fail('A replaced faculty member retained score write access after acquiring the lock.');
        } catch (AccessDeniedException) {
            self::assertSame([], $f->sql->rows('sis_scores'));
        }
    }

    public function testLockStatementsCompileForEachSupportedDialectWithoutPlainSnapshotReads(): void
    {
        foreach (['mysql', 'pgsql', 'oci'] as $driver) {
            self::assertSame('SELECT "id" FROM "sis_sections" WHERE "id" = ? FOR UPDATE',
                \Infrastructure\Persistence\SqlRowLock::select('"sis_sections"', '"id"', $driver));
        }
        self::assertSame('SELECT [id] FROM [sis_sections] WITH (UPDLOCK, ROWLOCK) WHERE [id] = ?',
            \Infrastructure\Persistence\SqlRowLock::select('[sis_sections]', '[id]', 'sqlsrv'));
        self::assertSame('SELECT "id" FROM "sis_sections" WHERE "id" = ?',
            \Infrastructure\Persistence\SqlRowLock::select('"sis_sections"', '"id"', 'sqlite'));
    }

}


/** Statement counts only: query parameters and record payloads are never captured. */
final class AcademicCountingPdo extends PDO
{
    public int $prepares = 0;
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        ++$this->prepares;
        return parent::prepare($query, $options);
    }
}
final class AcademicCountingConnection extends \Qubus\Expressive\Connection\Pdo\Sqlite
{
    protected function createPdo(array $config): PDO
    {
        $pdo = new AcademicCountingPdo('sqlite::memory:');
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }
}
