<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Reporting\Service\RelationalReportService;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Reporting\AcademicReportSchema;
use Infrastructure\Reporting\SqlRelationalReportSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class ReportPopulationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }
    private function service(
        SisFixture $s,
        ?AuditTrail $audit = null,
        ?\Domain\Reporting\Repository\RelationalReportSource $source = null,
    ): RelationalReportService {
        return new RelationalReportService(
            new SqlReportRepository($s->sql, $s->clock),
            new SqlRelationalReportRepository($s->sql),
            $source ?? new SqlRelationalReportSource($s->sql),
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->dropCipher,
            $s->clock,
            $s->reportLifecycles()
        );
    }
    private function definition(string $dataset = 'programs'): array
    {
        return ['dataset' => $dataset, 'columns' => [
            ['field' => 'institutional_id', 'label' => 'Student', 'aggregate' => 'none'],
        ], 'joins' => [], 'filters' => null, 'having' => null, 'distinct' => false, 'sort' => []];
    }
    private function saved(SisFixture $s, ?array $definition = null): EntityId
    {
        $id = EntityId::generate();
        $this->service($s)->save($id, $id, -1, 'Fictional registrar report', $definition ?? $this->definition());
        return $id;
    }
    private function facts(SisFixture $s): array
    {
        $facts = [];
        foreach (
            ['sis_history_events', 'sis_history_operations', 'sis_history_streams', 'sis_enrollments',
            'sis_academic_credits', 'sis_student_accounts', 'sis_report_queries', 'sis_registration_batches'] as $table
        ) {
            $facts[$table] = $s->sql->rows($table);
        }
        return $facts;
    }
    public function testJoinedCreditsBecomeDistinctStudentsWithoutChangingTheSavedReportOrAcademicFacts(): void
    {
        $s = new SisFixture();
        $a = $s->student();
        $b = $s->student();
        $s->service->register($a->id, $s->section(20, 0)->id, true);
        $s->service->register($a->id, $s->section(20, 0)->id, true);
        $s->service->register($b->id, $s->section(20, 0)->id, true);
        $id = $this->saved($s, $this->definition('credits'));
        $service = $this->service($s);
        $before = $this->facts($s);
        self::assertTrue($service->view($id)['can_select_students']);
        self::assertCount(3, $service->run($id, 0)['items']);
        $page = $service->population($id, 0);
        self::assertCount(2, $page['items']);
        $ids = [$a->id->value, $b->id->value];
        sort($ids, SORT_STRING);
        self::assertSame($ids, array_column($page['items'], 'id'));
        self::assertSame(
            $page['items'],
            $service->selectStudents($id, array_reverse($ids), $page['report_version'])['items']
        );
        self::assertSame($before, $this->facts($s));
        self::assertSame(['id', 'institutional_id', 'name', 'active'], array_keys($page['items'][0]));
    }
    public function testPopulationPagesAreBoundedAndDoNotLoadTheWholeStudentDirectory(): void
    {
        $s = new SisFixture();
        for ($i = 0; $i < 51; $i++) {
            $s->student();
        }
        $id = $this->saved($s);
        $first = $this->service($s)->population($id, 0);
        $second = $this->service($s)->population($id, 50);
        self::assertCount(50, $first['items']);
        self::assertSame(50, $first['next_offset']);
        self::assertCount(1, $second['items']);
        self::assertNull($second['next_offset']);
        self::assertSame([], array_intersect(
            array_column($first['items'], 'id'),
            array_column($second['items'], 'id')
        ));
    }
    public function testOriginalJoinChoicesAndAllFortyDeeplyNestedConditionsSurviveSelection(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $definition = $this->definition();
        $definition['columns'][] = ['field' => 'program_title', 'label' => 'Program', 'aggregate' => 'none'];
        $definition['joins'] = ['program' => 'inner'];
        $rules = array_fill(0, 40, ['id' => 'active', 'operator' => 'equal', 'value' => true]);
        $filters = ['condition' => 'AND', 'rules' => $rules];
        for ($i = 0; $i < 4; $i++) {
            $filters = ['condition' => 'AND', 'rules' => [$filters]];
        }
        $definition['filters'] = $filters;
        $query = new ReportQuery($definition, AcademicReportSchema::datasets()['programs']);
        $population = $query->studentPopulation([$student->id->value]);
        self::assertSame($filters, $population->definition['filters']);
        self::assertSame('inner', $population->definition['joins']['program']);
        self::assertSame('left', $population->definition['joins']['identity']);
        $id = $this->saved($s, $definition);
        self::assertSame([$student->id->value], array_column($this->service($s)->selectStudents(
            $id,
            [$student->id->value],
            0
        )['items'], 'id'));
    }
    public function testAChangedDefinitionOrLiveMembershipRequiresANewSelection(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $definition = $this->definition();
        $definition['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'active', 'operator' => 'equal', 'value' => true]]];
        $id = $this->saved($s, $definition);
        $service = $this->service($s);
        $s->sql->update('sis_students', ['active' => 0], ['id' => $student->id->value]);
        $before = $s->sql->rows('sis_audit');
        try {
            $service->selectStudents($id, [$student->id->value], 0);
            self::fail('An out-of-population student was handed off.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows('sis_audit'));
        }
        $service->save($id, EntityId::generate(), 0, 'Revised fictional report', $definition);
        $this->expectException(ConcurrentModificationException::class);
        $service->selectStudents($id, [$student->id->value], 0);
    }
    public static function insufficientGrants(): iterable
    {
        yield 'report reader' => [['sis:reporting:run', 'sis:student:read']];
        yield 'registration only' => [['sis:registration:manage', 'sis:student:read']];
        yield 'no academic read' => [['sis:registration:manage', 'sis:reporting:run']];
    }
    #[DataProvider('insufficientGrants')]
    public function testReportSharingDoesNotGrantRegistrationOrSourceAccess(array $grants): void
    {
        $s = new SisFixture();
        $s->student();
        $id = $this->saved($s);
        $s->access->staff = false;
        $s->access->grants = [...$grants, 'sis:reporting:manage'];
        try {
            self::assertFalse($this->service($s)->view($id)['can_select_students']);
        } catch (AccessDeniedException) {
            self::assertTrue(true); // Source restrictions can also prevent viewing the saved definition.
        }
        $this->expectException(AccessDeniedException::class);
        $this->service($s)->population($id, 0);
    }
    public function testRevokingAShareStopsPopulationReadsDespiteRegistrationAuthority(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $id = $this->saved($s);
        $reader = $s->user();
        $reports = new SqlReportRepository($s->sql, $s->clock);
        $reports->share($id, $reader);
        $s->access->actor = $reader;
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run', 'sis:student:read', 'sis:registration:manage'];
        self::assertSame($student->id->value, $this->service($s)->population($id, 0)['items'][0]['id']);
        $reports->unshare($id, $reader);
        $this->expectException(AccessDeniedException::class);
        $this->service($s)->selectStudents($id, [$student->id->value], 0);
    }
    public function testSummariesAndSectionSourcesCannotBeReinterpretedAsStudentPopulations(): void
    {
        $s = new SisFixture();
        $grouped = $this->definition();
        $grouped['columns'][0]['aggregate'] = 'count_distinct';
        foreach ([$grouped, $this->definition('sections')] as $definition) {
            $id = $this->saved($s, $definition);
            self::assertFalse($this->service($s)->view($id)['can_select_students']);
            try {
                $this->service($s)->population($id, 0);
                self::fail('A summarized or non-student source became a population.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }
    public function testSelectionRequiresOneToTwentyFiveDistinctCanonicalStudentReferences(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $id = $this->saved($s);
        foreach ([[], [$student->id->value, $student->id->value], array_fill(0, 26, $student->id->value)] as $bad) {
            try {
                $this->service($s)->selectStudents($id, $bad, 0);
                self::fail('An invalid population size or duplicate reference was accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->service($s)->selectStudents($id, ["x' OR 1=1 --"], 0);
    }
    public function testAuthorityLostDuringTheReadAuditRollsBackItsAuditAndReturnsNoPopulation(): void
    {
        $s = new SisFixture();
        $s->student();
        $id = $this->saved($s);
        $audit = $this->createMock(AuditTrail::class);
        $audit->expects(self::once())->method('record')->willReturnCallback(
            static function (EntityId $actor, string $action, EntityId $subject) use ($s): void {
                $s->audit->record($actor, $action, $subject);
                $s->access->staff = false;
            }
        );
        $before = $s->sql->rows('sis_audit');
        try {
            $this->service($s, $audit)->population($id, 0);
            self::fail('A population was returned after authority was removed.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $s->sql->rows('sis_audit'));
        }
    }

    public function testOriginalOutputFieldPermissionCannotBeBypassedByReplacingPopulationColumns(): void
    {
        $s = new SisFixture();
        $schema = AcademicReportSchema::datasets();
        $schema['programs']['fields']['program_code']['permissions'] = ['sis:catalog:manage'];
        $source = $this->createMock(\Domain\Reporting\Repository\RelationalReportSource::class);
        $source->method('datasets')->willReturn($schema);
        $source->expects(self::never())->method('execute');
        $definition = $this->definition();
        $definition['columns'][] = ['field' => 'program_code', 'label' => 'Program', 'aggregate' => 'none'];
        $service = $this->service($s, source: $source);
        $id = EntityId::generate();
        $service->save($id, $id, -1, 'Fictional restricted report', $definition);
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run', 'sis:student:read', 'sis:registration:manage'];
        $this->expectException(AccessDeniedException::class);
        $service->population($id, 0);
    }

    public function testPrivateCsrfProtectedRoutesHandOffOnlyToTheExistingReviewedRegistrationCommand(): void
    {
        $s = new SisFixture();
        $first = $s->student();
        $second = $s->student();
        $id = $this->saved($s);
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
        $request = new \Qubus\Http\ServerRequest([], [], "/sis/report-studio/{$id->value}/population/", 'POST')
            ->withParsedBody(['offset' => 0]);
        $csrf = new \Codefy\Framework\Http\Middleware\Csrf\CsrfProtectionMiddleware(
            $this->createStub(\Qubus\Config\ConfigContainer::class),
            $this->createStub(\Qubus\Http\Cookies\Factory\HttpCookieFactory::class)
        );
        $before = $this->facts($s);
        $response = $privacy->process($request, new \Qubus\Routing\Handlers\CallableRequestHandler(
            static fn($r) => $csrf->process($r, $handler)
        ));
        self::assertSame(403, $response->getStatusCode());
        $response = $privacy->process($request, $handler);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('private, no-store', $response->getHeaderLine('Cache-Control'));
        $page = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(2, $page['items']);
        $request = new \Qubus\Http\ServerRequest([], [], "/sis/report-studio/{$id->value}/population/select/", 'POST')
            ->withParsedBody(['student_ids' => [$first->id->value, $second->id->value], 'report_version' => 0]);
        $response = $privacy->process($request, $handler);
        self::assertSame(200, $response->getStatusCode());
        $selection = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($before, $this->facts($s));
        $section = $s->section();
        $batch = new \Domain\Registration\Service\RegistrationBatchService(
            new \Infrastructure\Persistence\Repository\SqlRegistrationBatchRepository($s->sql),
            $s->registration,
            $s->admissions,
            $s->service,
            $s->access,
            $s->transactions,
            $s->audit,
            $s->clock,
            new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($s->sql),
            $s->workPursuits(),
            $s->prerequisites()
        );
        $group = new \Domain\Registration\ValueObject\RegistrationBatchSelection(
            $section->id,
            ...array_map(static fn(array $row): EntityId => new EntityId($row['id']), $selection['items'])
        );
        $review = $batch->review($group);
        self::assertSame([], $s->sql->rows('sis_enrollments'));
        $receipt = $batch->commit(new EntityId($review['operation_id']), $group, $review['revision']);
        self::assertSame(2, $receipt['created']);
        self::assertCount(2, $s->sql->rows('sis_student_accounts'));
        $events = $s->sql->rows('sis_history_events');
        self::assertSame($receipt, $batch->commit(new EntityId($review['operation_id']), $group, $review['revision']));
        self::assertSame($events, $s->sql->rows('sis_history_events'));
    }
}
