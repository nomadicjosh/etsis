<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Reporting\StudentReport;
use Domain\Reporting\Service\ReportRetention;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Persistence\Repository\SqlReportLifecycleRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class ReportLifecycleTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
        require_once dirname(__DIR__) . '/Support/ReportRequestFixture.php';
    }
    private function report(SisFixture $s): EntityId
    {
        $id = EntityId::generate();
        new SqlReportRepository($s->sql, $s->clock)->add(
            new StudentReport($id, $s->access->actor, 'Fictional student report')
        );
        return $id;
    }
    private function retention(SisFixture $s): ReportRetention
    {
        return new ReportRetention(
            new SqlReportLifecycleRepository($s->sql),
            new SqlReportRepository($s->sql, $s->clock),
            $s->transactions,
            $s->clock
        );
    }
    private function facts(SisFixture $s): array
    {
        return [$s->sql->rows('sis_history_events'), $s->sql->rows('sis_history_operations'),
            $s->sql->rows('sis_academic_credits'), $s->sql->rows('sis_enrollments'),
            $s->sql->rows('sis_student_accounts')];
    }
    public function testStaticResultsAreFrozenEncryptedAndSeparateFromAcademicFacts(): void
    {
        $s = new SisFixture();
        $s->student();
        $id = $this->report($s);
        $before = $this->facts($s);
        $service = $s->reportLifecycles();
        $operation = EntityId::generate();
        $result = $service->configure($id, $operation, -1, 'static', null);
        self::assertSame('static', $result['lifecycle']['mode']);
        self::assertSame(1, $result['lifecycle']['row_count']);
        self::assertNull($result['lifecycle']['expires_at']);
        $page = $service->frozenPage($id, 0);
        self::assertSame('BSCS', $page['items'][0]['c1']);
        $s->sql->update('sis_programs', ['code' => 'CHANGED'], ['id' => $s->program->value]);
        self::assertSame($page['items'], $service->frozenPage($id, 0)['items']);
        self::assertSame($result, $service->configure($id, $operation, -1, 'static', null));
        self::assertSame($before, $this->facts($s));
        $stored = json_encode($s->sql->rows('sis_report_snapshot_pages'), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('BSCS', $stored);
        self::assertStringNotContainsString($page['items'][0]['c0'], $stored);
    }
    public function testDynamicReportCanBeTemporaryAndExpiryImmediatelyStopsReadsAndDirectoryVisibility(): void
    {
        $s = new SisFixture();
        $s->student();
        $id = $this->report($s);
        $result = $s->reportLifecycles()->configure($id, EntityId::generate(), -1, 'dynamic', 1);
        self::assertSame('dynamic', $result['lifecycle']['mode']);
        self::assertNull($result['lifecycle']['captured_at']);
        self::assertSame([], $s->sql->rows('sis_report_snapshot_pages'));
        $s->clock->time = $s->clock->time->modify('+1 day');
        $directory = new \Infrastructure\Persistence\Repository\SqlWorkspaceRepository(
            $s->sql,
            $s->gradeCatalog(),
            $s->clock
        );
        self::assertSame([], $directory->records('reports', null, '', null)['items']);
        $this->expectException(RecordNotFoundException::class);
        new SqlReportRepository($s->sql, $s->clock)->get($id);
    }
    public function testTemporaryCaptureCleanupIsBoundedPrivateAndLeavesAcademicFactsUntouched(): void
    {
        $s = new SisFixture();
        $s->student();
        $id = $this->report($s);
        $s->reportLifecycles()->configure($id, EntityId::generate(), -1, 'static', 1);
        new SqlReportRepository($s->sql, $s->clock)->share($id, $s->user());
        $before = $this->facts($s);
        $s->clock->time = $s->clock->time->modify('+1 day');
        $retention = $this->retention($s);
        self::assertSame(1, $retention->run()['examined']);
        self::assertNotEmpty($s->sql->rows('sis_report_snapshot_pages'));
        self::assertSame(1, $retention->run(true, 1)['removed']);
        foreach (
            ['sis_report_snapshot_pages', 'sis_report_queries',
            'sis_report_query_changes', 'sis_report_shares'] as $table
        ) {
            self::assertSame([], $s->sql->rows($table));
        }
        self::assertSame('Removed report', $s->sql->one('sis_reports', ['id' => $id->value])['name']);
        self::assertSame($before, $this->facts($s));
        self::assertSame(0, $retention->run(true)['removed']);
    }
    public function testExpiryCanBeChangedWithoutRecapturingAndAnEarlierRetryCannotResetIt(): void
    {
        $s = new SisFixture();
        $s->student();
        $id = $this->report($s);
        $first = EntityId::generate();
        $service = $s->reportLifecycles();
        $original = $service->configure($id, $first, -1, 'static', 7);
        $pages = $s->sql->rows('sis_report_snapshot_pages');
        $later = $service->configure($id, EntityId::generate(), 0, 'static', 30);
        self::assertNotSame($original['lifecycle']['expires_at'], $later['lifecycle']['expires_at']);
        self::assertSame($original, $service->configure($id, $first, -1, 'static', 7));
        self::assertSame($later['lifecycle']['expires_at'], $service->view($id)['lifecycle']['expires_at']);
        self::assertSame($pages, $s->sql->rows('sis_report_snapshot_pages'));
        $this->expectException(ValidationException::class);
        $service->configure($id, EntityId::generate(), 1, 'dynamic', null);
    }
    public function testTamperedPageFailsWithoutRequeryingCurrentStudentDataOrReturningPartialResults(): void
    {
        $s = new SisFixture();
        $s->student();
        $id = $this->report($s);
        $s->reportLifecycles()->configure($id, EntityId::generate(), -1, 'static', null);
        $row = $s->sql->rows('sis_report_snapshot_pages')[0];
        $s->sql->update('sis_report_snapshot_pages', ['encrypted_content' => 'corrupted'], ['id' => $row['id']]);
        $this->expectException(\Throwable::class);
        $s->reportLifecycles()->frozenPage($id, 0);
    }

    public function testCompleteStaticCapturePagesBeyondTheFirstFiftyRowsAndDynamicResultsRemainLive(): void
    {
        $s = new SisFixture();
        for ($i = 0; $i < 61; $i++) {
            $s->student();
        }
        $id = $this->report($s);
        $service = $s->reportLifecycles();
        $service->configure($id, EntityId::generate(), -1, 'static', null);
        $first = $service->frozenPage($id, 0);
        $last = $service->frozenPage($id, 50);
        self::assertCount(50, $first['items']);
        self::assertCount(11, $last['items']);
        self::assertSame(50, $first['next_offset']);
        self::assertNull($last['next_offset']);
        self::assertSame(61, $first['lifecycle']['row_count']);
        self::assertSame([], array_intersect(array_column($first['items'], 'c0'), array_column($last['items'], 'c0')));
        $dynamic = $this->report($s);
        $service->configure($dynamic, EntityId::generate(), -1, 'dynamic', null);
        $s->sql->update('sis_programs', ['code' => 'LATEST'], ['id' => $s->program->value]);
        $reports = new \Domain\Reporting\Service\RelationalReportService(
            new SqlReportRepository($s->sql, $s->clock),
            new \Infrastructure\Persistence\Repository\SqlRelationalReportRepository($s->sql),
            new \Infrastructure\Reporting\SqlRelationalReportSource($s->sql),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
            $service
        );
        self::assertSame('LATEST', $reports->run($dynamic, 0)['items'][0]['c1']);
        self::assertSame('BSCS', $reports->run($id, 0)['items'][0]['c1']);
        self::assertFalse($reports->view($id)['can_select_students']);
        self::assertTrue($reports->view($dynamic)['can_select_students']);
        $this->expectException(ValidationException::class);
        $reports->selectStudents($id, [EntityId::generate()->value], 0);
    }

    public function testRequiredAuditFailureRollsBackCapturePagesConversionAndReceiptTogether(): void
    {
        $s = new SisFixture();
        $s->student();
        $id = $this->report($s);
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit->expects(self::once())->method('record')->willThrowException(
            new \RuntimeException('Fictional audit failure.')
        );
        $before = $this->facts($s);
        try {
            $s->reportLifecycles(audit: $audit)->configure($id, EntityId::generate(), -1, 'static', 7);
            self::fail('A static capture committed without its required audit.');
        } catch (\RuntimeException) {
            foreach (
                ['sis_report_lifecycles', 'sis_report_snapshot_pages',
                'sis_report_queries', 'sis_report_query_changes'] as $table
            ) {
                self::assertSame([], $s->sql->rows($table));
            }
            self::assertSame($before, $this->facts($s));
        }
    }

    public function testCurrentAuthorityIsRequiredAfterCaptureAndOnFrozenReads(): void
    {
        $s = new SisFixture();
        $s->student();
        $id = $this->report($s);
        $capture = $this->createMock(\Domain\Reporting\Repository\ReportCaptureSource::class);
        $capture->expects(self::once())->method('capture')->willReturnCallback(static function () use ($s) {
            $s->access->staff = false;
            return new \Domain\Reporting\ValueObject\ReportCapture(['Fictional'], [['c0' => 'Fictional result']]);
        });
        try {
            $s->reportLifecycles(capture: $capture)->configure($id, EntityId::generate(), -1, 'static', null);
            self::fail('Permission loss during capture persisted private results.');
        } catch (AccessDeniedException) {
            self::assertSame([], $s->sql->rows('sis_report_snapshot_pages'));
        }
        $s->access->staff = true;
        $s->reportLifecycles()->configure($id, EntityId::generate(), -1, 'static', null);
        $reader = $s->user();
        $reports = new SqlReportRepository($s->sql, $s->clock);
        $reports->share($id, $reader);
        $s->access->actor = $reader;
        $s->access->staff = false;
        $s->access->grants = ['sis:reporting:run', 'sis:student:read'];
        self::assertCount(1, $s->reportLifecycles()->frozenPage($id, 0)['items']);
        $reports->unshare($id, $reader);
        $this->expectException(AccessDeniedException::class);
        $s->reportLifecycles()->frozenPage($id, 0);
    }

    public function testOriginalSaveRetryDoesNotRecaptureAndAnotherActorCannotReuseItsOperation(): void
    {
        $s = new SisFixture();
        $s->student();
        $id = $this->report($s);
        $capture = $this->createMock(\Domain\Reporting\Repository\ReportCaptureSource::class);
        $capture->expects(self::once())->method('capture')->willReturn(
            new \Domain\Reporting\ValueObject\ReportCapture(['Fictional'], [['c0' => 'Fictional fixed value']])
        );
        $service = $s->reportLifecycles(capture: $capture);
        $operation = EntityId::generate();
        $first = $service->configure($id, $operation, -1, 'static', null);
        self::assertSame($first, $service->configure($id, $operation, -1, 'static', null));
        $s->access->actor = $s->user();
        $this->expectException(ConcurrentModificationException::class);
        $service->configure($id, $operation, -1, 'static', null);
    }

    public function testStaticExpiryPreservesLinkedReportRequestsAndTheirRecordedCaseHistory(): void
    {
        $r = new \ReportRequestFixture();
        $s = $r->sis;
        $case = EntityId::generate();
        $service = $r->service();
        $service->create($case, new \Domain\Reporting\ValueObject\ReportRequestBrief(
            'Fictional request',
            'Fictional student totals',
            'Fictional review'
        ));
        $service->change(
            $case,
            EntityId::generate(),
            0,
            new \Domain\Reporting\ValueObject\ReportRequestChange('claim', 'Fictional responsibility')
        );
        $id = $this->report($s);
        $s->reportLifecycles()->configure($id, EntityId::generate(), -1, 'static', 1);
        $plan = $service->deliveryReview($case, $id);
        $service->change(
            $case,
            new EntityId($plan['operation_id']),
            $plan['version'],
            new \Domain\Reporting\ValueObject\ReportRequestChange(
                'deliver',
                'Fictional handoff',
                $id,
                $plan['delivery_revision']
            )
        );
        $history = $s->sql->rows('sis_report_request_actions');
        $record = $s->sql->one('sis_report_requests', ['id' => $case->value]);
        $s->clock->time = $s->clock->time->modify('+1 day');
        self::assertSame(1, $this->retention($s)->run(true)['removed']);
        self::assertSame($record, $s->sql->one('sis_report_requests', ['id' => $case->value]));
        self::assertSame($history, $s->sql->rows('sis_report_request_actions'));
        self::assertSame('delivered', $service->view($case)['status']);
        self::assertSame($id->value, $service->view($case)['report_id']);
    }

    public function testCaptureResultsHaveRedactedSerializationAndExplicitWorkLimits(): void
    {
        $capture = new \Domain\Reporting\ValueObject\ReportCapture(['Private'], [['c0' => 'fictional-private-result']]);
        self::assertStringNotContainsString('fictional-private-result', json_encode($capture, JSON_THROW_ON_ERROR));
        $this->expectException(ValidationException::class);
        new \Domain\Reporting\ValueObject\ReportCapture(['Private'], array_fill(0, 5001, ['c0' => 'Fictional']));
    }

    public function testStaticResultsAreNotReturnedWhenReadAuthorityIsLostDuringTheAudit(): void
    {
        $s = new SisFixture();
        $s->student();
        $id = $this->report($s);
        $s->reportLifecycles()->configure($id, EntityId::generate(), -1, 'static', null);
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit->expects(self::once())->method('record')->willReturnCallback(static function () use ($s): void {
            $s->access->staff = false;
        });
        $this->expectException(AccessDeniedException::class);
        $s->reportLifecycles(audit: $audit)->frozenPage($id, 0);
    }

    public function testDefinitionChangesDuringCaptureRejectAllPagesAndPreserveTheNewerDefinition(): void
    {
        $s = new SisFixture();
        $id = $this->report($s);
        $capture = $this->createMock(\Domain\Reporting\Repository\ReportCaptureSource::class);
        $capture->expects(self::once())->method('capture')->willReturnCallback(function () use ($s, $id) {
            $s->reportLifecycles()->configure($id, EntityId::generate(), -1, 'dynamic', 30);
            return new \Domain\Reporting\ValueObject\ReportCapture(['Fictional'], [['c0' => 'Fictional']]);
        });
        try {
            $s->reportLifecycles(capture: $capture)->configure($id, EntityId::generate(), -1, 'static', 1);
            self::fail('A changed definition must not accept stale captured results.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $s->sql->rows('sis_report_snapshot_pages'));
            self::assertSame('dynamic', $s->reportLifecycles()->view($id)['lifecycle']['mode']);
            self::assertSame(0, $s->reportLifecycles()->view($id)['version']);
        }
    }

    public function testSettingsRoutesRetainCsrfAndPrivateResponsesAndRejectMalformedDurations(): void
    {
        $s = new SisFixture();
        $s->student();
        $id = $this->report($s);
        $service = new \Domain\Reporting\Service\RelationalReportService(
            new SqlReportRepository($s->sql, $s->clock),
            new \Infrastructure\Persistence\Repository\SqlRelationalReportRepository($s->sql),
            new \Infrastructure\Reporting\SqlRelationalReportSource($s->sql),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
            $s->reportLifecycles()
        );
        $controller = new \Application\Http\Controller\SisRelationalReportController($service);
        $container = new \Qubus\Injector\Psr11\Container(\Qubus\Injector\Config\InjectorFactory::create([
            \Qubus\Injector\Injector::STANDARD_ALIASES => [
                \Psr\Http\Message\ResponseFactoryInterface::class => \Qubus\Routing\Factories\ResponseFactory::class,
                \Qubus\Routing\Interfaces\MiddlewareResolver::class =>
                    \Qubus\Routing\Route\InjectorMiddlewareResolver::class,
            ],
        ]));
        $container->share($controller);
        $router = new \Qubus\Routing\Router(new \Qubus\Routing\Route\RouteCollector(), $container);
        (require dirname(__DIR__, 2) . '/routes/web/sis-workflows.php')($router);
        $handler = new \Qubus\Routing\Handlers\CallableRequestHandler($router->match(...));
        $privacy = new \Application\Http\Middleware\SisPrivacyMiddleware();
        $request = new \Qubus\Http\ServerRequest([], [], "/sis/report-studio/{$id->value}/settings/", 'POST')
            ->withParsedBody(['operation_id' => EntityId::generate()->value, 'version' => -1,
                'mode' => 'static', 'expires_after_days' => '7']);
        $csrf = new \Codefy\Framework\Http\Middleware\Csrf\CsrfProtectionMiddleware(
            $this->createStub(\Qubus\Config\ConfigContainer::class),
            $this->createStub(\Qubus\Http\Cookies\Factory\HttpCookieFactory::class)
        );
        $denied = $privacy->process($request, new \Qubus\Routing\Handlers\CallableRequestHandler(
            static fn($r) => $csrf->process($r, $handler)
        ));
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame([], $s->sql->rows('sis_report_snapshot_pages'));
        $response = $privacy->process($request, $handler);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('private, no-store', $response->getHeaderLine('Cache-Control'));
        $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('static', $data['lifecycle']['mode']);
        $show = $privacy->process($request->withMethod('GET'), $handler);
        self::assertSame(200, $show->getStatusCode());
        self::assertStringContainsString('private, no-store', $show->getHeaderLine('Cache-Control'));
        $this->expectException(ValidationException::class);
        $controller->configure($request->withParsedBody(['expires_after_days' => '7.5']), $id->value);
    }

    public function testStaticDefinitionsRejectNewEditsButOriginalAcceptedSavesStillRetrySafely(): void
    {
        $s = new SisFixture();
        $s->student();
        $service = new \Domain\Reporting\Service\RelationalReportService(
            new SqlReportRepository($s->sql, $s->clock),
            new \Infrastructure\Persistence\Repository\SqlRelationalReportRepository($s->sql),
            new \Infrastructure\Reporting\SqlRelationalReportSource($s->sql),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
            $s->reportLifecycles()
        );
        $id = EntityId::generate();
        $operation = EntityId::generate();
        $definition = ['dataset' => 'students', 'columns' => [
            ['field' => 'institutional_id', 'label' => 'Student ID', 'aggregate' => 'none'],
        ]];
        $service->save($id, $operation, -1, 'Fictional report', $definition);
        $service->configureReport($id, EntityId::generate(), 0, 'static', null);
        $pages = $s->sql->rows('sis_report_snapshot_pages');
        $receipts = $s->sql->rows('sis_report_query_changes');
        $service->save($id, $operation, -1, 'Fictional report', $definition);
        self::assertSame($pages, $s->sql->rows('sis_report_snapshot_pages'));
        self::assertSame($receipts, $s->sql->rows('sis_report_query_changes'));
        try {
            $service->save($id, EntityId::generate(), 1, 'Changed report', $definition);
            self::fail('An immutable static definition was changed.');
        } catch (ValidationException) {
            self::assertSame($pages, $s->sql->rows('sis_report_snapshot_pages'));
            self::assertSame($receipts, $s->sql->rows('sis_report_query_changes'));
            self::assertSame('Fictional report', $service->view($id)['name']);
        }
    }
}
