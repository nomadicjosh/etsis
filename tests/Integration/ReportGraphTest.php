<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Reporting\Service\RelationalReportService;
use Domain\Reporting\ValueObject\ReportGraph;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Reporting\AcademicReportSchema;
use Infrastructure\Reporting\ReportSelectCompiler;
use Infrastructure\Reporting\SqlRelationalReportSource;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class ReportGraphTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }
    private function service(
        SisFixture $s,
        ?\Domain\Reporting\Repository\RelationalReportSource $source = null,
    ): RelationalReportService {
        return new RelationalReportService(
            new SqlReportRepository($s->sql, $s->clock),
            new SqlRelationalReportRepository($s->sql),
            $source ?? new SqlRelationalReportSource($s->sql),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
            $s->reportLifecycles()
        );
    }
    private function definition(): array
    {
        return ['dataset' => 'students', 'aliases' => [
            'copy1' => ['relation' => 'registration', 'label' => 'Spring registrations'],
        ], 'columns' => [['field' => 'student_id', 'label' => 'Student', 'aggregate' => 'none']],
            'existence' => [
                'registration' => ['mode' => 'any', 'filters' => ['condition' => 'AND', 'rules' => [
                    ['id' => 'registration_term_code', 'operator' => 'equal', 'value' => '26/FA'],
                ]]],
                'copy1' => ['mode' => 'any', 'filters' => ['condition' => 'AND', 'rules' => [
                    ['id' => 'copy1__registration_term_code', 'operator' => 'equal', 'value' => '27/SP'],
                ]]],
            ]];
    }
    /** Fictional current projection rows for reporting; no fabricated historical events. */
    private function registration(SisFixture $s, EntityId $student, string $code): void
    {
        $term = $s->sql->rows('sis_terms', ['code' => $code], 1)[0]['id'] ?? null;
        if ($term === null) {
            $term = EntityId::generate()->value;
            $s->sql->insert('sis_terms', ['id' => $term, 'code' => $code,
                'starts_on' => '2027-01-01', 'ends_on' => '2027-05-01', 'add_drop_ends_on' => '2027-01-14']);
        }
        $section = $s->section(20, 0);
        $s->sql->update('sis_sections', ['term_id' => $term], ['id' => $section->id->value]);
        $s->sql->insert('sis_enrollments', ['id' => EntityId::generate()->value, 'student_id' => $student->value,
            'section_id' => $section->id->value, 'term_id' => $term, 'status' => 'A',
            'registered_at' => '2026-08-10T12:00:00+00:00', 'status_at' => '2026-08-10T12:00:00+00:00',
            'version' => 0]);
    }
    public function testRepeatedMatchingBranchesFindStudentsInBothTermsWithoutMultiplyingThem(): void
    {
        $s = new SisFixture();
        $both = $s->student();
        $fall = $s->student();
        $spring = $s->student();
        $this->registration($s, $both->id, '26/FA');
        $this->registration($s, $both->id, '27/SP');
        $this->registration($s, $both->id, '27/SP');
        $this->registration($s, $fall->id, '26/FA');
        $this->registration($s, $spring->id, '27/SP');
        $service = $this->service($s);
        $events = $s->sql->rows('sis_history_events');
        self::assertSame([['c0' => $both->id->value]], $service->preview($this->definition(), 0)['items']);
        $id = EntityId::generate();
        $service->save($id, $id, -1, 'Fictional two-term students', $this->definition());
        self::assertSame($both->id->value, $service->population($id, 0)['items'][0]['id']);
        self::assertSame($both->id->value, $service->selectStudents($id, [$both->id->value], 0)['items'][0]['id']);
        self::assertSame($events, $s->sql->rows('sis_history_events'));
    }
    public function testRepeatedOutputJoinsExposeTheirTrueGrainAndDistinctStudentCountsRemainAvailable(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $this->registration($s, $student->id, '26/FA');
        $this->registration($s, $student->id, '27/SP');
        $this->registration($s, $student->id, '27/SP');
        $query = $this->definition();
        unset($query['existence']);
        $query['columns'][] = ['field' => 'registration_term_code', 'label' => 'Fall', 'aggregate' => 'none'];
        $query['columns'][] = ['field' => 'copy1__registration_term_code', 'label' => 'Spring', 'aggregate' => 'none'];
        $query['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'registration_term_code', 'operator' => 'equal', 'value' => '26/FA'],
            ['id' => 'copy1__registration_term_code', 'operator' => 'equal', 'value' => '27/SP'],
        ]];
        $rows = $this->service($s)->preview($query, 0)['items'];
        self::assertCount(2, $rows);
        self::assertSame(['c0' => $student->id->value, 'c1' => '26/FA', 'c2' => '27/SP'], $rows[0]);
        $query['columns'] = [['field' => 'student_id', 'label' => 'Students', 'aggregate' => 'count_distinct']];
        self::assertSame([['c0' => 1]], $this->service($s)->preview($query, 0)['items']);
    }
    public function testSourceMetadataHidesPhysicalStorageAndKeepsProtectedCopiedFieldsOut(): void
    {
        $s = new SisFixture();
        $schemas = AcademicReportSchema::datasets();
        $schemas['students']['fields']['registration_term_code']['permissions'] = ['sis:catalog:manage'];
        $source = $this->createMock(\Domain\Reporting\Repository\RelationalReportSource::class);
        $source->method('datasets')->willReturn($schemas);
        $source->expects(self::never())->method('execute');
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:manage', 'sis:student:read'];
        $data = $this->service($s, $source)->graph('students', $this->definition()['aliases'])['dataset'];
        self::assertArrayNotHasKey('copy1__registration_term_code', $data['fields']);
        self::assertArrayHasKey('copy1__registration_status', $data['fields']);
        self::assertSame('copy1', $data['relationships']['copy1__registration_term']['parent']);
        self::assertStringNotContainsString('sis_enrollments', json_encode($data, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('column', $data['fields']['copy1__registration_status']);
        self::assertArrayNotHasKey('permissions', $data['fields']['copy1__registration_status']);
    }
    public function testCopyingAProtectedSourceDoesNotBypassItsRelationshipPermission(): void
    {
        $s = new SisFixture();
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:manage', 'sis:catalog:manage'];
        $this->expectException(AccessDeniedException::class);
        $this->service($s)->graph('sections', ['copy1' => ['relation' => 'enrollment', 'label' => 'Other students']]);
    }
    public function testCopyLabelsNeverBecomeSqlIdentifiersOrStatements(): void
    {
        $s = new SisFixture();
        $query = $this->definition();
        $query['aliases']['copy1']['label'] = "<b>Spring'); DROP TABLE students; --</b>";
        $typed = new ReportQuery($query, AcademicReportSchema::datasets()['students']);
        $plan = new ReportSelectCompiler($s->sql)->compile($typed);
        self::assertStringNotContainsString('DROP TABLE', $plan['sql']);
        self::assertStringContainsString('copy1__registration_term', $plan['sql']);
        self::assertSame(['26/FA', '27/SP'], $plan['parameters']);
    }
    public function testCopySourceNamesLimitsAndInjectedMetadataAreValidated(): void
    {
        $schema = AcademicReportSchema::datasets()['students'];
        $cases = [
            ['copy1' => ['relation' => 'sis_history_events', 'label' => 'Unsafe']],
            ['copy4' => ['relation' => 'registration', 'label' => 'Fourth']],
            ['copy1' => ['relation' => 'registration', 'label' => "bad\nlabel"]],
            ['copy1' => ['relation' => 'registration', 'label' => 'A', 'table' => 'sis_history_events']],
            ['copy1' => ['relation' => 'registration', 'label' => 'A'],
                'copy2' => ['relation' => 'copy1', 'label' => 'B']],
        ];
        foreach ($cases as $case) {
            try {
                ReportGraph::expand($schema, $case);
                self::fail('An unapproved or recursive copy was accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $schema['fields']['copy1__registration_status'] = $schema['fields']['registration_status'];
        $this->expectException(ValidationException::class);
        ReportGraph::expand($schema, $this->definition()['aliases']);
    }
    public function testStaticCopiedReportsKeepTheirCapturedResultsAfterLiveMembershipChanges(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $this->registration($s, $student->id, '26/FA');
        $this->registration($s, $student->id, '27/SP');
        $service = $this->service($s);
        $id = EntityId::generate();
        $service->save($id, $id, -1, 'Fictional retained two-term report', $this->definition());
        $service->configureReport($id, EntityId::generate(), 0, 'static', null);
        $s->sql->execute('DELETE FROM sis_enrollments');
        self::assertCount(1, $service->run($id, 0)['items']);
        self::assertSame([], $service->preview($this->definition(), 0)['items']);
    }
    public function testAdmissionsApplicationsHaveTheirOwnGrainAndSeparateDataAuthority(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $query = ['dataset' => 'applications', 'columns' => [
            ['field' => 'application_id', 'label' => 'Application', 'aggregate' => 'none'],
            ['field' => 'full_name', 'label' => 'Applicant', 'aggregate' => 'none'],
            ['field' => 'status', 'label' => 'Status', 'aggregate' => 'none'],
        ]];
        $data = $this->service($s)->preview($query, 0);
        self::assertSame('Alex Example', $data['items'][0]['c1']);
        self::assertNotEmpty($data['items'][0]['c0']);
        $fields = AcademicReportSchema::datasets()['applications']['fields'];
        self::assertArrayNotHasKey('encrypted_answers', $fields);
        self::assertSame('submission', $fields['submitted_on']['relation']);
        self::assertSame('date', $fields['submitted_on']['type']);
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:manage', 'sis:reporting:run', 'sis:student:read'];
        $this->expectException(AccessDeniedException::class);
        $this->service($s)->preview($query, 0);
    }
    public function testAdmissionsJoinsRegistrationAndRecordedCreditsWithoutReinterpretingTheCatalog(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section(20, 0);
        $s->service->register($student->id, $section->id, true);
        $query = ['dataset' => 'applications', 'columns' => [
            ['field' => 'application_id', 'label' => 'Application', 'aggregate' => 'none'],
            ['field' => 'registration_section_code', 'label' => 'Registered section', 'aggregate' => 'none'],
            ['field' => 'recorded_course_title', 'label' => 'Recorded course title', 'aggregate' => 'none'],
            ['field' => 'recorded_attempted', 'label' => 'Attempted credits', 'aggregate' => 'none'],
        ]];
        $events = $s->sql->rows('sis_history_events');
        $s->sql->update(
            'sis_courses',
            ['title' => 'Changed current course title'],
            ['id' => $section->courseId->value]
        );
        $data = $this->service($s)->preview($query, 0)['items'];
        self::assertCount(1, $data);
        self::assertSame($section->code, $data[0]['c1']);
        self::assertSame('Computing', $data[0]['c2']);
        self::assertSame(3.0, $data[0]['c3']);
        self::assertSame($events, $s->sql->rows('sis_history_events'));
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:manage', 'sis:reporting:run', 'sis:admissions:manage'];
        $this->expectException(AccessDeniedException::class);
        $this->service($s)->preview($query, 0);
    }
    public function testLessThanTwelveUsesDistinctRegistrationsAndKeepsEmptySections(): void
    {
        $s = new SisFixture();
        $sections = [$s->section(30, 0), $s->section(30, 0), $s->section(30, 0), $s->section(30, 0)];
        for ($i = 0; $i < 12; $i++) {
            $student = $s->student();
            foreach ([1, 2, 3] as $n) {
                if ($n === 1 && $i === 11) {
                    continue;
                }
                $s->sql->insert('sis_enrollments', ['id' => EntityId::generate()->value,
                    'student_id' => $student->id->value, 'section_id' => $sections[$n]->id->value,
                    'term_id' => $s->term->value, 'status' => $n === 3 ? 'W' : 'A',
                    'registered_at' => '2026-08-10T12:00:00+00:00', 'status_at' => '2026-08-10T12:00:00+00:00',
                    'version' => 0]);
            }
        }
        $query = ['dataset' => 'sections', 'columns' => [
            ['field' => 'section_code', 'label' => 'Section', 'aggregate' => 'none'],
            ['field' => 'enrollment_id', 'label' => 'Registrations', 'aggregate' => 'count_distinct'],
        ], 'joins' => ['enrollment' => 'left'], 'join_filters' => [
            'enrollment' => ['condition' => 'AND', 'rules' => [
                ['id' => 'enrollment_status', 'operator' => 'in', 'value' => ['A', 'N']],
            ]],
        ], 'having' => ['condition' => 'AND', 'rules' => [
            ['id' => 'c1', 'operator' => 'less', 'value' => 12],
        ]]];
        $rows = array_column($this->service($s)->preview($query, 0)['items'], 'c1', 'c0');
        self::assertSame(0, $rows[$sections[0]->code]);
        self::assertSame(11, $rows[$sections[1]->code]);
        self::assertSame(0, $rows[$sections[3]->code]);
        self::assertArrayNotHasKey($sections[2]->code, $rows);
    }
    public function testGraphEndpointPreservesCsrfPrivacyAndMetadataOnlyResponses(): void
    {
        $s = new SisFixture();
        $container = new \Qubus\Injector\Psr11\Container(\Qubus\Injector\Config\InjectorFactory::create([
            \Qubus\Injector\Injector::STANDARD_ALIASES => [
                \Psr\Http\Message\ResponseFactoryInterface::class => \Qubus\Routing\Factories\ResponseFactory::class,
                \Qubus\Routing\Interfaces\MiddlewareResolver::class =>
                    \Qubus\Routing\Route\InjectorMiddlewareResolver::class,
            ],
        ]));
        $container->share(new \Application\Http\Controller\SisRelationalReportController($this->service($s)));
        $router = new \Qubus\Routing\Router(new \Qubus\Routing\Route\RouteCollector(), $container);
        (require dirname(__DIR__, 2) . '/routes/web/sis-workflows.php')($router);
        $handler = new \Qubus\Routing\Handlers\CallableRequestHandler($router->match(...));
        $privacy = new \Application\Http\Middleware\SisPrivacyMiddleware();
        $request = new \Qubus\Http\ServerRequest([], [], '/sis/report-studio/graph/', 'POST')
            ->withParsedBody(['dataset' => 'students', 'aliases' => $this->definition()['aliases']]);
        $csrf = new \Codefy\Framework\Http\Middleware\Csrf\CsrfProtectionMiddleware(
            $this->createStub(\Qubus\Config\ConfigContainer::class),
            $this->createStub(\Qubus\Http\Cookies\Factory\HttpCookieFactory::class)
        );
        $denied = $privacy->process($request, new \Qubus\Routing\Handlers\CallableRequestHandler(
            static fn($r) => $csrf->process($r, $handler)
        ));
        self::assertSame(403, $denied->getStatusCode());
        $response = $privacy->process($request, $handler);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('private, no-store', $response->getHeaderLine('Cache-Control'));
        $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Spring registrations', $data['dataset']['relationships']['copy1']['label']);
        self::assertStringNotContainsString('sis_enrollments', (string) $response->getBody());
        self::assertSame([], $s->sql->rows('sis_history_events'));
    }
    public function testCopiedRelationshipsCompileWithTheFiveSupportedIdentifierDialects(): void
    {
        $typed = new ReportQuery($this->definition(), AcademicReportSchema::datasets()['students']);
        foreach (['mysql', 'pgsql', 'sqlite', 'sqlsrv', 'oci'] as $driver) {
            $connection = $this->createStub(\Qubus\Expressive\Connection::class);
            $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $name): string =>
                match ($driver) {
'mysql' => '`' . $name . '`', 'sqlsrv' => '[' . $name . ']', default => '"' . $name . '"'
                });
            $db = $this->createStub(\Qubus\Expressive\Database::class);
            $db->method('getConnection')->willReturn($connection);
            $plan = new ReportSelectCompiler(new \Infrastructure\Persistence\SqlRecords($db))->compile($typed);
            self::assertStringContainsString('match_copy1__registration_term', $plan['sql']);
            self::assertStringNotContainsString('Spring registrations', $plan['sql']);
            self::assertSame(['26/FA', '27/SP'], $plan['parameters']);
        }
    }
}
