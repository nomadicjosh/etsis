<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/SisFixture.php';

use Application\Http\Controller\SisReportController;
use Domain\Audit\Repository\AuditTrail;
use Domain\Reporting\Repository\ReportRepository;
use Domain\Reporting\Service\ReportService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use League\Csv\Reader;
use PHPUnit\Framework\TestCase;
use Qubus\Http\ServerRequest;

final class ReportWorkspaceTest extends TestCase
{
    private function service(SisFixture $f, ?AuditTrail $audit = null, ?ReportRepository $repository = null): ReportService
    {
        return new ReportService(
            $repository ?? new SqlReportRepository($f->sql),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit
        );
    }

    public function testAllMatchingStudentsCanBePagedBeyondTheFormerFiveHundredLimit(): void
    {
        $f = new SisFixture();
        $f->transactions->run(function () use ($f): void {
            for ($i = 0; $i < 513; ++$i) {
                $f->student();
            }
        });
        $service = $this->service($f);
        $report = $service->create('Fictional student population', null, null, null);
        $after = null;
        $ids = [];
        $sizes = [];
        do {
            $page = $service->run($report->id, $after);
            self::assertSame(50, $page['limit']);
            self::assertLessThanOrEqual(50, count($page['items']));
            $sizes[] = count($page['items']);
            array_push($ids, ...array_column($page['items'], 'student_id'));
            $after = $page['next_after'] === null ? null : new EntityId($page['next_after']);
        } while ($after !== null);
        self::assertCount(513, array_unique($ids));
        self::assertSame(13, end($sizes));
        $sorted = $ids;
        sort($sorted);
        self::assertSame($sorted, $ids);
        self::assertSame($ids[50], $service->run($report->id, new EntityId($ids[49]))['items'][0]['student_id']);
    }

    public function testCombinedFiltersKeepFalseAndUseOriginalEntryTermAndCurrentPrimaryProgram(): void
    {
        $f = new SisFixture();
        $matching = $f->student();
        $active = $f->student();
        $wrongTerm = $f->student();
        $wrongProgram = $f->student();
        foreach ([$matching, $wrongTerm, $wrongProgram] as $student) {
            $f->sql->update('sis_students', ['active' => 0], ['id' => $student->id->value]);
        }
        $otherTerm = EntityId::generate();
        $f->sql->insert('sis_terms', ['id' => $otherTerm->value, 'code' => '27/SP', 'starts_on' => '2027-01-01',
            'ends_on' => '2027-05-01', 'add_drop_ends_on' => '2027-01-10']);
        $otherProgram = EntityId::generate();
        $f->sql->insert('sis_programs', ['id' => $otherProgram->value, 'code' => 'BA.ART', 'title' => 'Arts']);
        $f->sql->update('sis_students', ['entry_term_id' => $otherTerm->value], ['id' => $wrongTerm->id->value]);
        $f->sql->update('sis_students', ['primary_program_id' => $otherProgram->value], ['id' => $wrongProgram->id->value]);
        $service = $this->service($f);
        $report = $service->create('Inactive originals', $f->program, $f->term, false);
        $description = $service->view($report->id);
        self::assertFalse($description['active']);
        self::assertSame('BSCS', $description['program_code']);
        self::assertSame('2026FA', $description['entry_term_code']);
        $row = $service->run($report->id)['items'];
        self::assertCount(1, $row);
        self::assertSame($matching->id->value, $row[0]['student_id']);
        self::assertFalse($row[0]['active']);
        self::assertArrayNotHasKey('given_name', $row[0]);
        self::assertArrayNotHasKey('owner_user_id', $row[0]);
        self::assertArrayNotHasKey('balance_minor', $row[0]);
        $f->sql->update(
            'sis_person_identifiers',
            ['institutional_id' => '00009999'],
            ['person_id' => $matching->personId->value]
        );
        self::assertSame('00009999', $service->run($report->id)['items'][0]['institutional_id']);
        self::assertSame([], $service->run($report->id, new EntityId('7' . str_repeat('Z', 25)))['items']);
    }

    public function testSharingNeverSubstitutesForExecutionPermissionAndRevocationProtectsEveryRead(): void
    {
        $f = new SisFixture();
        $f->student();
        $service = $this->service($f);
        $owner = $f->access->actor;
        $report = $service->create('Fictional shared students', null, null, null);
        $recipient = $f->user();
        $service->share($report->id, $recipient);
        $f->access->actor = $recipient;
        $f->access->staff = false;
        $f->access->grants = ['sis:student:read', 'sis:transfer:manage'];
        foreach (['view', 'run', 'export'] as $method) {
            try {
                $service->$method($report->id);
                self::fail('An unrelated grant authorized report execution.');
            } catch (AccessDeniedException) {
                self::assertNotContains('sis:reporting:run', $f->access->grants);
            }
        }
        $f->access->grants = ['sis:reporting:run'];
        self::assertFalse($service->view($report->id)['can_manage']);
        self::assertCount(1, $service->run($report->id)['items']);
        self::assertCount(1, $service->export($report->id)['items']);
        $f->access->actor = $owner;
        $f->access->staff = true;
        $service->unshare($report->id, $recipient);
        $f->access->actor = $recipient;
        $f->access->staff = false;
        foreach (['view', 'run', 'export'] as $method) {
            try {
                $service->$method($report->id);
                self::fail('Revoked sharing still exposed report data.');
            } catch (AccessDeniedException) {
                self::assertCount(1, $f->sql->rows('sis_reports'));
            }
        }
    }

    public function testDefinitionManagementAndExecutionAreSeparateEvenForTheOwner(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $report = $service->create('Managed definition', null, null, null);
        $f->access->staff = false;
        $f->access->grants = ['sis:reporting:manage'];
        self::assertTrue($service->view($report->id)['can_manage']);
        self::assertFalse($service->view($report->id)['can_run']);
        try {
            $service->run($report->id);
            self::fail('Managing a report implicitly authorized execution.');
        } catch (AccessDeniedException) {
            self::assertCount(1, $f->sql->rows('sis_reports'));
        }
        $f->access->actor = $f->user();
        $this->expectException(AccessDeniedException::class);
        $service->view($report->id);
    }

    public function testPermissionIsRecheckedAfterTheSerializationLockBeforeReadingRows(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $repository = new SqlReportRepository($f->sql);
        $report = $service->create('Lock check', null, null, null);
        $recipient = $f->user();
        $service->share($report->id, $recipient);
        $f->access->actor = $recipient;
        $f->access->staff = false;
        $f->access->grants = ['sis:reporting:run'];
        $calls = [];
        $guarded = $this->createMock(ReportRepository::class);
        $guarded->method('lock')->willReturnCallback(static function (EntityId $id) use ($repository, $recipient, &$calls): void {
            $calls[] = 'lock';
            $repository->unshare($id, $recipient); // Preceding revocation visible after lock acquisition.
            $repository->lock($id);
        });
        $guarded->method('get')->willReturnCallback(static function (EntityId $id) use ($repository, &$calls) {
            $calls[] = 'read';
            return $repository->get($id);
        });
        $guarded->method('isSharedWith')->willReturnCallback($repository->isSharedWith(...));
        $guarded->expects(self::never())->method('run');
        try {
            $this->service($f, repository: $guarded)->run($report->id);
            self::fail('A preceding revocation was missed.');
        } catch (AccessDeniedException) {
            self::assertSame(['lock', 'read'], $calls);
        }
    }

    public function testCsvIsBoundedUsesCodesAndNeutralizesSpreadsheetFormulas(): void
    {
        $f = new SisFixture();
        for ($i = 0; $i < 53; ++$i) {
            $f->student();
        }
        $student = $f->sql->rows('sis_students')[0];
        $f->sql->update('sis_person_identifiers', ['institutional_id' => '=1+1'], ['person_id' => $student['person_id']]);
        $service = $this->service($f);
        $report = $service->create('Fictional export', null, null, null);
        $request = (new ServerRequest([], [], '/sis/reports/' . $report->id->value . '/export/', 'POST'))
            ->withParsedBody(['after' => '']);
        $response = new SisReportController($service)->export($request, $report->id->value);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('student-report-page.csv', $response->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('sandbox', $response->getHeaderLine('Content-Security-Policy'));
        $csv = Reader::fromString((string) $response->getBody());
        $csv->setEscape('');
        $records = iterator_to_array($csv->getRecords());
        self::assertCount(51, $records);
        self::assertSame(['Institutional ID', 'Primary program', 'Entry term', 'Student activity'], $records[0]);
        self::assertSame(["'=1+1", 'BSCS', '2026FA', 'Active'], $records[1]);
        self::assertNotContains($student['id'], array_column($records, 0));
        $audit = $f->audit->forSubject($report->id);
        self::assertSame('report.page_exported', end($audit)['action']);
        self::assertStringNotContainsString('=1+1', json_encode($audit, JSON_THROW_ON_ERROR));
    }

    public function testAuditFailureDoesNotReturnAnUnauditedResultsPage(): void
    {
        $f = new SisFixture();
        $f->student();
        $report = $this->service($f)->create('Fictional audit', null, null, null);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willThrowException(new RuntimeException('Fictional audit failure'));
        $this->expectException(RuntimeException::class);
        $this->service($f, $audit)->run($report->id);
    }
}
