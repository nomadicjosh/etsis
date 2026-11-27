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
use Infrastructure\Reporting\SqlRelationalReportSource;
use Infrastructure\Reporting\StudentContactReportSchema;
use PersonManagementFixture;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class StudentContactReportTest extends TestCase
{
    private function fixture(): array
    {
        require_once dirname(__DIR__) . '/Support/PersonManagementFixture.php';
        $f = new PersonManagementFixture();
        $s = $f->sis;
        $person = $f->person();
        $application = $s->legacyApplication($person->id, $s->program, $s->term);
        $s->legacyAdmissionDecision($application->id, true);
        $student = $s->legacyStudent($application->id);
        return [$f, $student, $person];
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

    private function definition(): array
    {
        return ['dataset' => 'students', 'columns' => [
            ['field' => 'student_id', 'label' => 'Student', 'aggregate' => 'none'],
            ['field' => 'current_account_email', 'label' => 'Contact', 'aggregate' => 'none'],
        ]];
    }

    private function filter(): array
    {
        return ['condition' => 'AND', 'rules' => [
            ['id' => 'current_account_email', 'operator' => 'ends_with', 'value' => '@example.invalid'],
        ]];
    }

    public function testActualEmailCorrectionChangesLiveReportsAndPreservesStaticValuesAndHistory(): void
    {
        [$f, $student, $person] = $this->fixture();
        $s = $f->sis;
        $service = $this->service($s);
        $beforeEmail = $f->accounts->view($person->id);
        $definition = $this->definition();
        $definition['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'active', 'operator' => 'equal', 'value' => true],
        ]];
        self::assertSame(
            [['c0' => $student->id->value, 'c1' => $beforeEmail['email']]],
            $service->preview($definition, 0)['items']
        );
        $id = EntityId::generate();
        $service->save($id, $id, -1, 'Fictional student contacts', $definition);
        $service->configureReport($id, EntityId::generate(), 0, 'static', null);
        $events = $s->sql->rows('sis_history_events');
        $f->accounts->email($person->id, 'corrected@example.invalid', $beforeEmail['version']);
        self::assertSame('corrected@example.invalid', $service->preview($definition, 0)['items'][0]['c1']);
        self::assertSame($beforeEmail['email'], $service->run($id, 0)['items'][0]['c1']);
        foreach ($events as $event) {
            self::assertSame($event, $s->sql->one('sis_history_events', ['id' => $event['id']]));
        }
        $accepted = $s->sql->rows('sis_history_events');
        $service->preview($definition, 0);
        $service->run($id, 0);
        self::assertSame($accepted, $s->sql->rows('sis_history_events'));
    }

    public function testUnlinkedOlderPeopleStayUnknownWithoutBorrowingAnAccountOrCreatingHistory(): void
    {
        [$f] = $this->fixture();
        $s = $f->sis;
        $person = $f->person(false);
        $application = $s->legacyApplication($person->id, $s->program, $s->term);
        $s->legacyAdmissionDecision($application->id, true);
        $student = $s->legacyStudent($application->id);
        $events = $s->sql->rows('sis_history_events');
        $definition = $this->definition();
        $definition['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'student_id', 'operator' => 'equal', 'value' => $student->id->value],
        ]];
        self::assertSame(
            [['c0' => $student->id->value, 'c1' => null]],
            $this->service($s)->preview($definition, 0)['items']
        );
        self::assertSame($events, $s->sql->rows('sis_history_events'));
        self::assertNull($s->admissions->person($person->id)->ownerUserId);
    }

    public function testContactAuthorityProtectsMetadataHiddenFiltersConditionsMatchesAndCopies(): void
    {
        [$f] = $this->fixture();
        $s = $f->sis;
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:manage', 'sis:reporting:run', 'sis:student:read'];
        $service = $this->service($s);
        $graph = $service->graph('students', [])['dataset'];
        self::assertArrayNotHasKey('current_account_email', $graph['fields']);
        self::assertArrayNotHasKey('account_contact', $graph['relationships']);
        $base = ['dataset' => 'students', 'columns' => [
            ['field' => 'student_id', 'label' => 'Student', 'aggregate' => 'none'],
        ]];
        $cases = [
            $this->definition(), $base + ['filters' => $this->filter()],
            $base + ['joins' => ['account_contact' => 'inner']],
            $base + ['join_filters' => ['account_contact' => $this->filter()]],
            $base + ['existence' => ['account_contact' => ['mode' => 'any', 'filters' => $this->filter()]]],
            ['dataset' => 'students', 'columns' => [
                ['field' => 'student_id', 'label' => 'Matching', 'aggregate' => 'count_distinct',
                    'conditions' => $this->filter()],
            ]],
            ['dataset' => 'students', 'columns' => [
                ['field' => 'student_id', 'label' => 'Label', 'aggregate' => 'none', 'label_mapping' => [
                    'branches' => [['when' => $this->filter(), 'label' => 'Institution contact']], 'otherwise' => null,
                ]],
            ]],
            ['dataset' => 'students', 'aliases' => [
                'copy1' => ['relation' => 'account_contact', 'label' => 'Contact copy'],
            ], 'columns' => [
                ['field' => 'copy1__current_account_email', 'label' => 'Contact', 'aggregate' => 'none'],
            ]],
        ];
        foreach ($cases as $definition) {
            try {
                $service->preview($definition, 0);
                self::fail('Contact predicates or copies bypassed their source duty.');
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
        $s->access->grants[] = StudentContactReportSchema::PERMISSION;
        foreach ($cases as $definition) {
            self::assertCount(1, $service->preview($definition, 0)['items']);
        }
        $s->access->grants = ['sis:reporting:manage', 'sis:reporting:run', StudentContactReportSchema::PERMISSION];
        $this->expectException(AccessDeniedException::class);
        $service->preview($this->definition(), 0);
    }

    public function testSharedStaticResultsRecheckCurrentContactAndStudentGrants(): void
    {
        [$f] = $this->fixture();
        $s = $f->sis;
        $service = $this->service($s);
        $id = EntityId::generate();
        $service->save($id, $id, -1, 'Fictional retained contacts', $this->definition());
        $service->configureReport($id, EntityId::generate(), 0, 'static', null);
        $recipient = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($id, $recipient);
        $s->access->actor = $recipient;
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run', 'sis:student:read'];
        try {
            $service->run($id, 0);
            self::fail('Sharing granted contact authority.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $s->access->grants[] = StudentContactReportSchema::PERMISSION;
        self::assertCount(1, $service->run($id, 0)['items']);
        $s->access->grants = ['sis:reporting:run', StudentContactReportSchema::PERMISSION];
        $this->expectException(AccessDeniedException::class);
        $service->run($id, 0);
    }

    public function testCopiedContactsAndRegistrationMatchesKeepDistinctStudentGrain(): void
    {
        [$f, $student] = $this->fixture();
        $s = $f->sis;
        $s->service->register($student->id, $s->section(fee: 0)->id);
        $s->service->register($student->id, $s->section(fee: 0)->id);
        $definition = $this->definition();
        $definition['aliases'] = ['copy1' => ['relation' => 'account_contact', 'label' => 'Same canonical contact']];
        $definition['columns'][] = ['field' => 'copy1__current_account_email', 'label' => 'Copied contact',
            'aggregate' => 'none'];
        $definition['existence'] = ['registration' => ['mode' => 'any']];
        $rows = $this->service($s)->preview($definition, 0)['items'];
        self::assertCount(1, $rows);
        self::assertSame($rows[0]['c1'], $rows[0]['c2']);
        $definition = ['dataset' => 'students', 'columns' => [
            ['field' => 'student_id', 'label' => 'Students', 'aggregate' => 'count_distinct'],
        ], 'existence' => ['registration' => ['mode' => 'any']], 'filters' => $this->filter()];
        self::assertSame([['c0' => 1]], $this->service($s)->preview($definition, 0)['items']);
    }

    public function testCompleteStaticAndLiveCapturesPreserveEmailScopeAndSpreadsheetSafety(): void
    {
        [$f, , $person] = $this->fixture();
        $s = $f->sis;
        $f->accounts->email($person->id, '=fictional@example.invalid', $f->accounts->view($person->id)['version']);
        $service = $this->service($s);
        $id = EntityId::generate();
        $service->save($id, $id, -1, 'Fictional safe contact export', $this->definition());
        $service->configureReport($id, EntityId::generate(), 0, 'static', null);
        $lifecycles = new \Infrastructure\Persistence\Repository\SqlReportLifecycleRepository($s->sql);
        $capture = new \Infrastructure\Reporting\SqlReportExportCapture(
            new \Infrastructure\Reporting\SqlReportResultReader(
                $s->connection->queryBuilder(),
                new SqlRelationalReportSource($s->sql)
            ),
            new \Domain\Reporting\Service\ReportSnapshotReader($lifecycles, $s->dropCipher),
            $s->dropCipher,
        );
        $query = new ReportQuery($this->definition(), AcademicReportSchema::datasets()['students']);
        foreach ([null, $lifecycles->get($id)] as $lifecycle) {
            $artifact = $capture->capture(EntityId::generate(), $id, $query, $lifecycle);
            $packet = json_decode($s->dropCipher->open($artifact->chunks[1]), true, flags: JSON_THROW_ON_ERROR);
            self::assertStringContainsString("'=fictional@example.invalid", $packet['content']);
            self::assertStringNotContainsString('=fictional@example.invalid', implode('', $artifact->chunks));
        }
    }
    private function exports(
        SisFixture $s,
        ?\Domain\Reporting\Repository\ReportExportCapture $capture = null,
    ): \Domain\Reporting\Service\ReportExportService {
        $permissions = $this->createStub(\Domain\Access\Repository\AccessRepository::class);
        $permissions->method('allows')->willReturnCallback(static fn(string $actor, string $permission): bool =>
            $permission !== 'sis:reporting:admin' &&
            ($s->access->staff || in_array($permission, $s->access->grants, true)));
        $lifecycles = new \Infrastructure\Persistence\Repository\SqlReportLifecycleRepository($s->sql);
        $capture ??= new \Infrastructure\Reporting\SqlReportExportCapture(
            new \Infrastructure\Reporting\SqlReportResultReader(
                $s->connection->queryBuilder(),
                new SqlRelationalReportSource($s->sql)
            ),
            new \Domain\Reporting\Service\ReportSnapshotReader($lifecycles, $s->dropCipher),
            $s->dropCipher,
        );
        return new \Domain\Reporting\Service\ReportExportService(
            new SqlReportRepository($s->sql, $s->clock),
            new SqlRelationalReportRepository($s->sql),
            new SqlRelationalReportSource($s->sql),
            $lifecycles,
            new \Infrastructure\Persistence\Repository\SqlReportExportRepository($s->sql),
            $capture,
            $s->access,
            $permissions,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
        );
    }

    public function testQueuedAndPreparedExportsRecheckContactAuthorityAndKeepChunksEncrypted(): void
    {
        [$f] = $this->fixture();
        $s = $f->sis;
        $report = EntityId::generate();
        $this->service($s)->save($report, $report, -1, 'Fictional private contacts', $this->definition());
        $exports = $this->exports($s);
        $job = EntityId::generate();
        $exports->request($report, $job, 0);
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run', 'sis:student:read'];
        self::assertSame(1, $exports->work(true)['failed']);
        self::assertSame([], $s->sql->rows('sis_report_export_chunks'));
        $s->access->grants[] = StudentContactReportSchema::PERMISSION;
        $job = EntityId::generate();
        $exports->request($report, $job, 0);
        $events = $s->sql->rows('sis_history_events');
        self::assertSame(1, $exports->work(true)['ready']);
        self::assertStringContainsString('@example.invalid', implode('', $exports->download($report, $job)->chunks));
        self::assertStringNotContainsString('@example.invalid', json_encode($s->sql->rows('sis_report_export_chunks')));
        self::assertSame($events, $s->sql->rows('sis_history_events'));
        $s->access->grants = ['sis:reporting:run', 'sis:student:read'];
        $this->expectException(AccessDeniedException::class);
        $exports->download($report, $job);
    }

    public function testEveryApprovedSourceUsesTheExactCurrentPersonLink(): void
    {
        [$f, $student] = $this->fixture();
        $s = $f->sis;
        $s->service->register($student->id, $s->section(fee: 0)->id);
        $account = \Domain\Finance\StudentAccount::open(
            new \Domain\Finance\ValueObject\AccountId(),
            $student->id,
            $s->term,
            'USD'
        );
        $account->post(
            EntityId::generate(),
            \Domain\Finance\Enum\EntryType::Charge,
            new \Domain\Finance\ValueObject\Money(100, 'USD'),
            $s->access->actor
        );
        $s->saveAccount($account);
        $events = $s->sql->rows('sis_history_events');
        foreach (['students', 'programs', 'applications', 'credits', 'accounts'] as $dataset) {
            $definition = ['dataset' => $dataset, 'columns' => [
                ['field' => 'current_account_email', 'label' => 'Contact', 'aggregate' => 'none'],
            ]];
            $rows = $this->service($s)->preview($definition, 0)['items'];
            self::assertCount(1, $rows, $dataset);
            self::assertStringEndsWith('@example.invalid', $rows[0]['c0']);
        }
        self::assertSame($events, $s->sql->rows('sis_history_events'));
    }
    public function testMessagingSelectionKeepsTheOriginalContactDutyWithoutCopyingEmailIntoTheHandoff(): void
    {
        [$f, $student] = $this->fixture();
        $s = $f->sis;
        $service = $this->service($s);
        $report = EntityId::generate();
        $service->save($report, $report, -1, 'Fictional contact selection', $this->definition());
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run', 'sis:student:read', StudentContactReportSchema::PERMISSION,
            'sis:communications:manage', 'sis:person:manage'];
        $action = \Domain\Reporting\Enum\StudentPopulationAction::Messaging;
        $events = $s->sql->rows('sis_history_events');
        $campaigns = $s->sql->rows('sis_campaigns');
        $page = $service->population($report, 0, $action);
        self::assertSame([$student->id->value], array_column($page['items'], 'id'));
        self::assertStringNotContainsString('@example.invalid', json_encode($page));
        self::assertSame($events, $s->sql->rows('sis_history_events'));
        self::assertSame($campaigns, $s->sql->rows('sis_campaigns'));
        $s->access->grants = ['sis:reporting:run', 'sis:student:read',
            'sis:communications:manage', 'sis:person:manage'];
        $this->expectException(AccessDeniedException::class);
        $service->selectStudents($report, [$student->id->value], $page['report_version'], $action);
    }

    public function testContactRevocationDuringCapturePreventsPublishingThePreparedChunks(): void
    {
        [$f] = $this->fixture();
        $s = $f->sis;
        $report = EntityId::generate();
        $this->service($s)->save($report, $report, -1, 'Fictional late-revoked contacts', $this->definition());
        $this->exports($s)->request($report, EntityId::generate(), 0);
        $lifecycles = new \Infrastructure\Persistence\Repository\SqlReportLifecycleRepository($s->sql);
        $builder = new \Infrastructure\Reporting\SqlReportExportCapture(
            new \Infrastructure\Reporting\SqlReportResultReader(
                $s->connection->queryBuilder(),
                new SqlRelationalReportSource($s->sql)
            ),
            new \Domain\Reporting\Service\ReportSnapshotReader($lifecycles, $s->dropCipher),
            $s->dropCipher,
        );
        $capture = $this->createStub(\Domain\Reporting\Repository\ReportExportCapture::class);
        $revoke = static function ($job, $id, $query, $static) use ($s, $builder) {
            $artifact = $builder->capture($job, $id, $query, $static);
            $s->access->staff = false;
            $s->access->grants = ['sis:reporting:run', 'sis:student:read'];
            return $artifact;
        };
        $capture->method('capture')->willReturnCallback($revoke);
        $result = $this->exports($s, $capture)->work(true);
        self::assertSame(0, $result['ready']);
        self::assertSame(1, $result['failed']);
        self::assertSame([], $s->sql->rows('sis_report_export_chunks'));
    }
}
