<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Reporting\Service\RelationalReportService;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Reporting\AcademicReportSchema;
use Infrastructure\Reporting\ReportSelectCompiler;
use Infrastructure\Reporting\SqlRelationalReportSource;
use PHPUnit\Framework\TestCase;
use SisFixture;
use Tests\Support\AdmissionCaseworkFixture;

final class AdmissionCaseworkReportingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CampusFixture.php';
        require_once dirname(__DIR__) . '/Support/AdmissionCaseworkFixture.php';
    }

    private function service(SisFixture $s): RelationalReportService
    {
        return new RelationalReportService(
            new SqlReportRepository($s->sql, $s->clock),
            new SqlRelationalReportRepository($s->sql),
            new SqlRelationalReportSource($s->sql),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
            $s->reportLifecycles(),
        );
    }

    private function definition(array $fields): array
    {
        return ['dataset' => 'applications', 'columns' => array_map(
            static fn(string $field): array => ['field' => $field, 'label' => $field, 'aggregate' => 'none'],
            $fields,
        )];
    }

    public function testCurrentCaseworkKeepsDecisionAndWorkingStatusSeparateAndPrivateContentExcluded(): void
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $f->service->save($f->application, [
            'office' => 'Admissions <b>fictional</b>', 'work_status' => 'waiting',
            'follow_up_on' => '2026-09-10', 'private_notes' => 'private fixture note',
            'next_step' => 'private fixture next-step', 'items' => [[
                'key' => 'task', 'label' => 'private fixture task', 'instructions' => 'private fixture instructions',
                'needed' => true, 'visible' => false, 'status' => 'pending', 'due_on' => '2026-09-01',
            ]],
        ], 0, 'me');
        $events = $s->sql->rows('sis_history_events');
        $cases = $s->sql->rows('sis_admission_cases');
        $definition = $this->definition(['application_id', 'status', 'case_office', 'case_work_status', 'case_due_on']);
        self::assertSame(
            [['c0' => $f->application->value, 'c1' => 'submitted',
            'c2' => 'Admissions <b>fictional</b>', 'c3' => 'waiting', 'c4' => '2026-09-01']],
            $this->service($s)->preview($definition, 0)['items']
        );
        $sql = new ReportSelectCompiler($s->sql)->compile(
            new ReportQuery($definition, AcademicReportSchema::datasets()['applications']),
        )['sql'];
        self::assertStringNotContainsString('encrypted_payload', $sql);
        self::assertStringNotContainsString('sis_history', $sql);
        self::assertSame($events, $s->sql->rows('sis_history_events'));
        self::assertSame($cases, $s->sql->rows('sis_admission_cases'));
        $metadata = json_encode($this->service($s)->catalog(), JSON_THROW_ON_ERROR);
        foreach (['private_notes', 'next_step', 'encrypted_payload', 'instructions', 'request_hash'] as $private) {
            self::assertStringNotContainsString($private, $metadata);
        }
    }

    public function testMissingCaseworkStaysNullAndDistinctCountsSurviveJoinedRegistrations(): void
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $otherPerson = $s->legacyPerson($s->user(), 'Another', 'Applicant');
        $other = $s->legacyApplication($otherPerson->id, $s->program, $s->term);
        $f->service->save($f->application, ['office' => 'Admissions', 'work_status' => 'open'], 0, 'me');
        $query = $this->definition(['application_id', 'case_work_status']);
        $query['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'case_reference', 'operator' => 'is_null'],
        ]];
        self::assertSame([['c0' => $other->id->value, 'c1' => null]], $this->service($s)->preview($query, 0)['items']);
        $query = $this->definition(['case_office', 'case_work_status']);
        $query['columns'][] = ['field' => 'application_id', 'label' => 'Applications', 'aggregate' => 'count_distinct'];
        $s->legacyAdmissionDecision($f->application, true);
        $student = $s->legacyStudent($f->application);
        $s->service->register($student->id, $s->section()->id);
        $s->service->register($student->id, $s->section()->id);

        $query['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'case_work_status', 'operator' => 'in', 'value' => ['open', 'waiting']],
            ['id' => 'registration_status', 'operator' => 'in', 'value' => ['A', 'N']],
        ]];
        self::assertSame(
            [['c0' => 'Admissions', 'c1' => 'open', 'c2' => 1]],
            $this->service($s)->preview($query, 0)['items']
        );
        $f->service->save($f->application, ['office' => 'Admissions', 'work_status' => 'done'], 1, 'keep');
        self::assertSame([], $this->service($s)->preview($query, 0)['items']);
    }

    public function testAssigneeFieldsAndHiddenFiltersRequireIndependentPersonAuthority(): void
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $f->service->save($f->application, ['office' => 'Admissions'], 0, 'me');
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:manage', 'sis:reporting:run', 'sis:admissions:manage'];
        $service = $this->service($s);
        $source = array_values(array_filter(
            $service->catalog()['datasets'],
            static fn(array $d): bool => $d['id'] === 'applications'
        ))[0];
        self::assertArrayHasKey('case_work_status', $source['fields']);
        self::assertArrayNotHasKey('case_assignee_name', $source['fields']);
        $query = $this->definition(['application_id']);
        $query['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'case_assigned_to', 'operator' => 'is_null'],
        ]];
        $this->expectException(AccessDeniedException::class);
        $service->preview($query, 0);
    }

    public function testSavedSharedAndStaticResultsPreserveCurrentGrantsAndCapturedRouting(): void
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $f->service->save($f->application, ['office' => 'Original office', 'work_status' => 'open'], 0, 'me');
        $service = $this->service($s);
        $query = $this->definition(['application_id', 'case_office', 'case_work_status']);
        $dynamic = EntityId::generate();
        $static = EntityId::generate();
        foreach ([$dynamic, $static] as $report) {
            $service->save($report, EntityId::generate(), -1, 'Fictional office follow-up', $query);
        }
        $service->configureReport($static, EntityId::generate(), 0, 'static', null);
        $f->service->save($f->application, ['office' => 'New office', 'work_status' => 'waiting'], 1, 'keep');
        self::assertSame('New office', $service->run($dynamic, 0)['items'][0]['c1']);
        self::assertSame('Original office', $service->run($static, 0)['items'][0]['c1']);
        $recipient = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($static, $recipient);
        $s->access->actor = $recipient;
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run', 'sis:admissions:manage'];
        self::assertCount(1, $service->run($static, 0)['items']);
        $s->access->grants = ['sis:reporting:run'];
        $this->expectException(AccessDeniedException::class);
        $service->run($static, 0);
    }
    public function testAssignedNamesUseCanonicalPersonLinkAndDueDatesKeepCalendarTypes(): void
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $f->service->save($f->application, ['office' => 'Admissions', 'follow_up_on' => '2026-09-10'], 0, 'me');
        $query = $this->definition(['case_assigned_to', 'case_assignee_name', 'case_due_on']);
        $query['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'case_due_on', 'operator' => 'less_or_equal', 'value' => '2026-09-10'],
        ]];
        self::assertSame([['c0' => $s->access->actor->value, 'c1' => 'Fictional Case applicant',
            'c2' => '2026-09-10']], $this->service($s)->preview($query, 0)['items']);
        $query['filters']['rules'][0]['value'] = '2026-09-09';
        self::assertSame([], $this->service($s)->preview($query, 0)['items']);
    }
}
