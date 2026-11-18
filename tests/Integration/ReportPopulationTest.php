<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Reporting\Enum\StudentPopulationAction;
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
    public static function actions(): iterable
    {
        foreach (StudentPopulationAction::cases() as $action) {
            yield $action->value => [$action];
        }
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
            'sis_academic_credits', 'sis_student_accounts', 'sis_account_balances', 'sis_billing_batches',
            'sis_report_queries', 'sis_registration_batches', 'sis_campaigns', 'sis_deliveries',
            'sis_campaign_dispatches'] as $table
        ) {
            $facts[$table] = $s->sql->rows($table);
        }
        return $facts;
    }
    #[DataProvider('actions')]
    public function testJoinedCreditsBecomeDistinctStudentsWithoutChangingTheSavedReportOrAcademicFacts(
        StudentPopulationAction $action,
    ): void {
        $s = new SisFixture();
        $a = $s->student();
        $b = $s->student();
        $s->service->register($a->id, $s->section(20, 0)->id, true);
        $s->service->register($a->id, $s->section(20, 0)->id, true);
        $s->service->register($b->id, $s->section(20, 0)->id, true);
        $id = $this->saved($s, $this->definition('credits'));
        $service = $this->service($s);
        $before = $this->facts($s);
        self::assertContains($action->value, $service->view($id)['student_actions']);
        self::assertCount(3, $service->run($id, 0)['items']);
        $page = $service->population(
            $id,
            0,
            $action,
        );
        self::assertCount(2, $page['items']);
        $ids = [$a->id->value, $b->id->value];
        sort($ids, SORT_STRING);
        self::assertSame($ids, array_column($page['items'], 'id'));
        self::assertSame(
            $page['items'],
            $service->selectStudents(
                $id,
                array_reverse($ids),
                $page['report_version'],
                $action,
            )['items']
        );
        self::assertSame($before, $this->facts($s));
        self::assertSame(['id', 'institutional_id', 'name', 'active'], array_keys($page['items'][0]));
    }
    #[DataProvider('actions')]
    public function testPopulationPagesAreBoundedAndDoNotLoadTheWholeStudentDirectory(
        StudentPopulationAction $action,
    ): void {
        $s = new SisFixture();
        for ($i = 0; $i < 51; $i++) {
            $s->student();
        }
        $id = $this->saved($s);
        $first = $this->service($s)->population(
            $id,
            0,
            $action,
        );
        $second = $this->service($s)->population(
            $id,
            50,
            $action,
        );
        self::assertCount(50, $first['items']);
        self::assertSame(50, $first['next_offset']);
        self::assertCount(1, $second['items']);
        self::assertNull($second['next_offset']);
        self::assertSame([], array_intersect(
            array_column($first['items'], 'id'),
            array_column($second['items'], 'id')
        ));
    }
    #[DataProvider('actions')]
    public function testOriginalJoinChoicesAndAllFortyDeeplyNestedConditionsSurviveSelection(
        StudentPopulationAction $action,
    ): void {
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
            0,
            $action,
        )['items'], 'id'));
    }
    #[DataProvider('actions')]
    public function testAChangedDefinitionOrLiveMembershipRequiresANewSelection(
        StudentPopulationAction $action,
    ): void {
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
            $service->selectStudents(
                $id,
                [$student->id->value],
                0,
                $action,
            );
            self::fail('An out-of-population student was handed off.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows('sis_audit'));
        }
        $service->save($id, EntityId::generate(), 0, 'Revised fictional report', $definition);
        $this->expectException(ConcurrentModificationException::class);
        $service->selectStudents(
            $id,
            [$student->id->value],
            0,
            $action,
        );
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
            self::assertNotContains('registration', $this->service($s)->view($id)['student_actions']);
        } catch (AccessDeniedException) {
            self::assertTrue(true); // Source restrictions can also prevent viewing the saved definition.
        }
        $this->expectException(AccessDeniedException::class);
        $this->service($s)->population(
            $id,
            0,
            StudentPopulationAction::Registration,
        );
    }
    #[DataProvider('actions')]
    public function testRevokingAShareStopsPopulationReadsDespiteRegistrationAuthority(
        StudentPopulationAction $action,
    ): void {
        $s = new SisFixture();
        $student = $s->student();
        $id = $this->saved($s);
        $reader = $s->user();
        $reports = new SqlReportRepository($s->sql, $s->clock);
        $reports->share($id, $reader);
        $s->access->actor = $reader;
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run', 'sis:student:read', ...$action->permissions()];
        self::assertSame($student->id->value, $this->service($s)->population(
            $id,
            0,
            $action,
        )['items'][0]['id']);
        $reports->unshare($id, $reader);
        $this->expectException(AccessDeniedException::class);
        $this->service($s)->selectStudents(
            $id,
            [$student->id->value],
            0,
            $action,
        );
    }
    #[DataProvider('actions')]
    public function testSummariesAndSectionSourcesCannotBeReinterpretedAsStudentPopulations(
        StudentPopulationAction $action,
    ): void {
        $s = new SisFixture();
        $grouped = $this->definition();
        $grouped['columns'][0]['aggregate'] = 'count_distinct';
        foreach ([$grouped, $this->definition('sections')] as $definition) {
            $id = $this->saved($s, $definition);
            self::assertNotContains($action->value, $this->service($s)->view($id)['student_actions']);
            try {
                $this->service($s)->population(
                    $id,
                    0,
                    $action,
                );
                self::fail('A summarized or non-student source became a population.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }
    #[DataProvider('actions')]
    public function testSelectionRequiresOneToTwentyFiveDistinctCanonicalStudentReferences(
        StudentPopulationAction $action,
    ): void {
        $s = new SisFixture();
        $student = $s->student();
        $id = $this->saved($s);
        foreach ([[], [$student->id->value, $student->id->value], array_fill(0, 26, $student->id->value)] as $bad) {
            try {
                $this->service($s)->selectStudents(
                    $id,
                    $bad,
                    0,
                    $action,
                );
                self::fail('An invalid population size or duplicate reference was accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->service($s)->selectStudents(
            $id,
            ["x' OR 1=1 --"],
            0,
            $action,
        );
    }
    #[DataProvider('actions')]
    public function testAuthorityLostDuringTheReadAuditRollsBackItsAuditAndReturnsNoPopulation(
        StudentPopulationAction $action,
    ): void {
        $s = new SisFixture();
        $s->student();
        $id = $this->saved($s);
        $audit = $this->createMock(AuditTrail::class);
        $audit->expects(self::once())->method('record')->willReturnCallback(
            static function (EntityId $actor, string $auditAction, EntityId $subject) use ($s, $action): void {
                $s->audit->record($actor, $auditAction, $subject);
                $s->access->staff = false;
                $s->access->grants = ['sis:reporting:run', 'sis:student:read',
                    ...array_slice($action->permissions(), 0, -1)];
            }
        );
        $before = $s->sql->rows('sis_audit');
        try {
            $this->service($s, $audit)->population(
                $id,
                0,
                $action,
            );
            self::fail('A population was returned after authority was removed.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $s->sql->rows('sis_audit'));
        }
    }

    #[DataProvider('actions')]
    public function testOriginalOutputFieldPermissionCannotBeBypassedByReplacingPopulationColumns(
        StudentPopulationAction $action,
    ): void {
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
        $s->access->grants = ['sis:reporting:run', 'sis:student:read', ...$action->permissions()];
        $this->expectException(AccessDeniedException::class);
        $service->population(
            $id,
            0,
            $action,
        );
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
            ->withParsedBody(['offset' => 0, 'action' => 'registration']);
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
            ->withParsedBody([
                'student_ids' => [$first->id->value, $second->id->value],
                'report_version' => 0, 'action' => 'registration',
            ]);
        $response = $privacy->process($request, $handler);
        self::assertSame(200, $response->getStatusCode());
        $selection = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($before, $this->facts($s));
        $section = $s->section();
        $batch = $s->registrationBatches();
        $group = new \Domain\Registration\ValueObject\RegistrationBatchSelection(
            $section->id,
            ...array_map(static fn(array $row): EntityId => new EntityId($row['id']), $selection['items'])
        );
        $review = $batch->review($group);
        self::assertSame([], $s->sql->rows('sis_enrollments'));
        $receipt = $batch->commit(
            new EntityId($review["operation_id"]),
            $group,
            $review["revision"],
            $review["review_token"],
        );
        self::assertSame(2, $receipt['created']);
        self::assertCount(2, $s->sql->rows('sis_student_accounts'));
        $events = $s->sql->rows('sis_history_events');
        self::assertSame(
            $receipt,
            $batch->commit(
                new EntityId($review["operation_id"]),
                $group,
                $review["revision"],
                $review["review_token"],
            ),
        );
        self::assertSame($events, $s->sql->rows('sis_history_events'));
    }

    public function testPopulationTasksAreAdvertisedAndAuthorizedIndependently(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $id = $this->saved($s);
        $service = $this->service($s);
        $s->access->staff = false;
        $base = ['sis:reporting:run', 'sis:student:read'];
        foreach (StudentPopulationAction::cases() as $action) {
            $s->access->grants = [...$base, ...$action->permissions()];
            self::assertSame([$action->value], $service->view($id)['student_actions']);
            $page = $service->population($id, 0, $action);
            self::assertSame($action->value, $page['action']);
            self::assertSame([$student->id->value], array_column($page['items'], 'id'));
            foreach (StudentPopulationAction::cases() as $other) {
                if ($other === $action) {
                    continue;
                }
                $before = $this->facts($s);
                try {
                    $service->selectStudents($id, [$student->id->value], 0, $other);
                    self::fail('One task supplied another task authority.');
                } catch (AccessDeniedException) {
                    self::assertSame($before, $this->facts($s));
                }
            }
        }
        $s->access->staff = true;
        self::assertSame(['registration', 'billing', 'messaging'], $service->view($id)['student_actions']);
        $service->configureReport($id, EntityId::generate(), 0, 'static', null);
        self::assertSame([], $service->view($id)['student_actions']);
        foreach (StudentPopulationAction::cases() as $action) {
            try {
                $service->population($id, 0, $action);
                self::fail('A static capture supplied a live task population.');
            } catch (ValidationException) {
                self::assertSame([], $s->sql->rows('sis_billing_batches'));
            }
        }
    }

    public static function missingTaskGrants(): iterable
    {
        foreach (StudentPopulationAction::cases() as $action) {
            $all = ['sis:reporting:run', 'sis:student:read', ...$action->permissions()];
            foreach ($all as $missing) {
                yield $action->value . '/' . $missing => [$action, array_values(array_diff($all, [$missing]))];
            }
        }
    }

    public function testFinancialDetailReportsKeepBalanceCurrencyAndSourceChecksWhileDeduplicatingStudents(): void
    {
        $s = new SisFixture();
        $first = $s->student();
        $second = $s->student();
        $term = EntityId::generate();
        $s->sql->insert('sis_terms', ['id' => $term->value, 'code' => '27/SP',
            'starts_on' => '2027-01-04', 'ends_on' => '2027-05-15', 'add_drop_ends_on' => '2027-01-15']);
        $accountA = $s->finance()->open($first->id, $s->term, 'USD');
        $accountB = $s->finance()->open($first->id, $term, 'USD');
        $accountC = $s->finance()->open($second->id, $s->term, 'EUR');
        $postings = [];
        foreach ([$accountA, $accountB, $accountC] as $account) {
            $operation = EntityId::generate();
            $s->finance()->post(
                $account->aggregateId(),
                $operation,
                \Domain\Finance\Enum\EntryType::Charge,
                new \Domain\Finance\ValueObject\Money(250, $account->balance()->currency),
                $account->playhead(),
                'Fictional report source charge.'
            );
            $postings[] = [$account->aggregateId(), $operation];
        }
        $definition = $this->definition('accounts');
        $definition['columns'][] = ['field' => 'balance', 'label' => 'Balance', 'aggregate' => 'none'];
        $definition['columns'][] = ['field' => 'currency', 'label' => 'Currency', 'aggregate' => 'none'];
        $definition['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'balance', 'operator' => 'greater', 'value' => 0],
            ['id' => 'currency', 'operator' => 'equal', 'value' => 'USD'],
        ]];
        $id = $this->saved($s, $definition);
        $service = $this->service($s);
        $before = $this->facts($s);
        self::assertCount(2, $service->run($id, 0)['items']);
        self::assertSame([$first->id->value], array_column(
            $service->population($id, 0, StudentPopulationAction::Billing)['items'],
            'id'
        ));
        self::assertSame($before, $this->facts($s));
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run', 'sis:student:read', 'sis:registration:manage'];
        try {
            $service->population($id, 0, StudentPopulationAction::Registration);
            self::fail('Derived student columns bypassed the original financial source permission.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $this->facts($s));
        }
        $s->access->staff = true;
        foreach (array_slice($postings, 0, 2) as [$account, $operation]) {
            $s->finance()->reverse(
                $account,
                $operation,
                $s->accounts->get($account)->playhead(),
                'Fictional source balance corrected.'
            );
        }
        $before = $this->facts($s);
        try {
            $service->selectStudents($id, [$first->id->value], 0, StudentPopulationAction::Billing);
            self::fail('A changed financial filter handed off an outdated student.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->facts($s));
        }
        $definition['columns'][1]['aggregate'] = 'sum';
        $summary = $this->saved($s, $definition);
        self::assertSame([], $service->view($summary)['student_actions']);
        $this->expectException(ValidationException::class);
        $service->population($summary, 0, StudentPopulationAction::Billing);
    }

    #[DataProvider('missingTaskGrants')]
    public function testEveryTaskPermissionIsRequiredEvenForASharedReport(
        StudentPopulationAction $action,
        array $grants,
    ): void {
        $s = new SisFixture();
        $student = $s->student();
        $id = $this->saved($s);
        $reader = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($id, $reader);
        $s->access->actor = $reader;
        $s->access->staff = false;
        $s->access->grants = $grants;
        $before = $this->facts($s);
        $audit = $s->sql->rows('sis_audit');
        foreach (
            [fn () => $this->service($s)->population($id, 0, $action),
            fn () => $this->service($s)->selectStudents(
                $id,
                [$student->id->value],
                0,
                $action
            )] as $attempt
        ) {
            try {
                $attempt();
                self::fail('Sharing bypassed a required billing-population permission.');
            } catch (AccessDeniedException) {
                self::assertSame($before, $this->facts($s));
                self::assertSame($audit, $s->sql->rows('sis_audit'));
            }
        }
    }
}
