<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\AcademicRecord\Service\WorkPursuitService;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class WorkPursuitHistoryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
        require_once dirname(__DIR__) . '/Support/TransferAwardFixture.php';
    }

    private function fixture(): array
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $section = $s->section(20, 0);
        $enrollment = $s->service->register($student, $section->id);
        $credit = new EntityId($s->sql->one('sis_academic_credits', ['source_id' => $enrollment->id->value])['id']);
        $old = $s->workPursuits()->activePursuit($student);
        $program = EntityId::generate();
        $s->sql->insert('sis_programs', ['id' => $program->value, 'code' => 'NEW', 'title' => 'Fictional new program']);
        $r = $s->admissions->student($student);
        $revision = hash('sha256', json_encode([$r->id->value, $r->personId->value, $r->programId->value,
            $r->entryTermId->value, $r->active], JSON_THROW_ON_ERROR));
        $service = $s->programAssignmentService();
        $review = $service->reviewChange($student, $program, $s->term, $revision, 'Fictional program change.');
        $result = $service->changeProgram($student, $review['review_token']);
        return [$s, $student, $credit, $old, new EntityId($result['assignment_id']), $section];
    }

    private function decision(
        SisFixture $s,
        EntityId $student,
        EntityId $credit,
        EntityId $target,
        string $action,
    ): void {
        $service = $s->workPursuits();
        $review = $service->review($student, $credit, $target, $action, 'Fictional protected approval narrative.');
        $service->commit($student, $credit, $review['review_token']);
    }

    private function facts(SisFixture $s): array
    {
        $facts = [];
        foreach (
            ['sis_history_events', 'sis_history_operations', 'sis_history_streams',
            'sis_work_pursuit_scopes', 'sis_academic_credits', 'sis_enrollments', 'sis_student_accounts'] as $table
        ) {
            $facts[$table] = $s->sql->rows($table);
        }
        return $facts;
    }

    private function longHistory(): array
    {
        [$s, $student, $credit, $old, $receiving] = $this->fixture();
        for ($i = 0; $i < 10; $i++) {
            $this->decision($s, $student, $credit, $receiving, 'approve_reuse');
            $this->decision($s, $student, $credit, $receiving, 'revoke_reuse');
        }
        return [$s, $student, $credit, $old, $receiving];
    }

    public function testCuratedHistoryRetainsDecisionOrderTimesAndPursuitsWithoutProtectedNarrativesOrWrites(): void
    {
        [$s, $student, $credit, $old, $receiving] = $this->fixture();
        $s->clock->time = $s->clock->time->modify('+1 hour');
        $effective = $s->clock->time->format(DATE_ATOM);
        $this->decision($s, $student, $credit, $receiving, 'approve_reuse');
        $this->decision($s, $student, $credit, $receiving, 'revoke_reuse');
        $this->decision($s, $student, $credit, $receiving, 'correct_attribution');
        $before = $this->facts($s);
        $audits = count($s->sql->rows('sis_audit'));
        $result = $s->workPursuits()->history($student, $credit);
        self::assertSame($before, $this->facts($s));
        self::assertSame($audits + 1, count($s->sql->rows('sis_audit')));
        self::assertSame(4, $result['through_version']);
        self::assertNull($result['next_cursor']);
        self::assertSame([1, 2, 3, 4], array_column($result['items'], 'version'));
        self::assertSame(
            ['attribute', 'approve_reuse', 'revoke_reuse', 'correct_attribution'],
            array_column($result['items'], 'action')
        );
        self::assertSame($old->value, $result['items'][0]['pursuit']['id']);
        self::assertSame('closed', $result['items'][0]['pursuit']['status']);
        self::assertSame($receiving->value, $result['items'][1]['pursuit']['id']);
        self::assertSame($effective, $result['items'][1]['effective_at']);
        self::assertNotEmpty($result['items'][1]['recorded_at']);
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (
            ['Fictional protected approval narrative.', $s->access->actor->value,
            'payload', 'work_hash', 'academic_version', 'source_id', 'decision_id', 'correlation_id'] as $private
        ) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    public function testPursuitLabelsAreExplicitlyCurrentAndCannotBeMistakenForHistoricalSnapshots(): void
    {
        [$s, $student, $credit, $old] = $this->fixture();
        $program = new EntityId($s->sql->one('sis_student_programs', ['id' => $old->value])['program_id']);
        $s->sql->update('sis_programs', ['title' => 'Renamed <b>fictional program</b>'], ['id' => $program->value]);
        $before = $this->facts($s);
        $result = $s->workPursuits()->history($student, $credit);
        self::assertSame('current', $result['pursuit_labels']);
        self::assertSame('Renamed <b>fictional program</b>', $result['items'][0]['pursuit']['program_title']);
        self::assertSame($before, $this->facts($s));
    }

    public function testPagedHistoryFreezesItsHeadAndOnlyExplicitRefreshIncludesLaterDecisions(): void
    {
        [$s, $student, $credit, , $receiving] = $this->longHistory();
        $service = $s->workPursuits();
        $first = $service->history($student, $credit);
        self::assertCount(20, $first['items']);
        self::assertSame(21, $first['through_version']);
        self::assertNotNull($first['next_cursor']);
        $this->decision($s, $student, $credit, $receiving, 'approve_reuse');
        $second = $service->history($student, $credit, $first['next_cursor']);
        self::assertSame([21], array_column($second['items'], 'version'));
        self::assertSame(21, $second['through_version']);
        self::assertNull($second['next_cursor']);
        $refresh = $service->history($student, $credit);
        self::assertSame(22, $refresh['through_version']);
        self::assertSame([21, 22], array_column(
            $service->history($student, $credit, $refresh['next_cursor'])['items'],
            'version'
        ));
    }

    public function testAnotherRecordCannotUseAHistoryContinuation(): void
    {
        [$s, $student, $credit] = $this->longHistory();
        $first = $s->workPursuits()->history($student, $credit);
        $enrollment = $s->service->register($student, $s->section(20, 0)->id);
        $other = new EntityId($s->sql->one('sis_academic_credits', ['source_id' => $enrollment->id->value])['id']);
        $audit = $s->sql->rows('sis_audit');
        try {
            $s->workPursuits()->history($student, $other, $first['next_cursor']);
            self::fail('A continuation crossed academic records.');
        } catch (CorruptHistoryException) {
            self::assertSame($audit, $s->sql->rows('sis_audit'));
        }
    }

    public function testUnattributedOlderTransferHasAnEmptyHistoryWithoutInventingAttribution(): void
    {
        [$s, $student] = $this->fixture();
        $transfer = new \TransferAwardFixture($s, $s->dropCipher, $student, legacy: true);
        $before = $this->facts($s);
        $result = $s->workPursuits()->history($student, $transfer->credit);
        self::assertSame([], $result['items']);
        self::assertSame(0, $result['through_version']);
        self::assertNull($result['next_cursor']);
        self::assertSame($before, $this->facts($s));
    }

    public function testAnotherStudentsWorkAndRemovedEarlyDropsAreNotExposed(): void
    {
        [$s, $student, $credit, , , $section] = $this->fixture();
        $other = $s->student()->id;
        $before = $s->sql->rows('sis_audit');
        try {
            $s->workPursuits()->history($other, $credit);
            self::fail('History crossed student boundaries.');
        } catch (RecordNotFoundException) {
            self::assertSame($before, $s->sql->rows('sis_audit'));
        }
        $s->drops->drop($student, $section->id);
        $before = $this->facts($s);
        try {
            $s->workPursuits()->history($student, $credit);
            self::fail('A restricted early removal was exposed by the office workbench.');
        } catch (RecordNotFoundException) {
            self::assertSame($before, $this->facts($s));
        }
    }

    public function testProjectionCorruptionStopsHistoryWithoutAReadAuditOrRepair(): void
    {
        [$s, $student, $credit] = $this->fixture();
        $s->sql->update('sis_work_pursuit_scopes', ['projection_json' => '{}'], ['id' => $credit->value]);
        $before = $this->facts($s);
        $audit = $s->sql->rows('sis_audit');
        try {
            $s->workPursuits()->history($student, $credit);
            self::fail('Corrupt attribution was presented as valid history.');
        } catch (CorruptHistoryException) {
            self::assertSame($before, $this->facts($s));
            self::assertSame($audit, $s->sql->rows('sis_audit'));
        }
    }

    public function testHistoryPermissionRemainsIndependentAndCurrentThroughTheRequiredAudit(): void
    {
        [$s, $student, $credit] = $this->fixture();
        $s->access->staff = false;
        $s->access->grants = ['sis:student:read', 'sis:registration:manage'];
        try {
            $s->workPursuits()->history($student, $credit);
            self::fail('Registration authority exposed scope history.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $s->access->grants[] = WorkPursuitService::PERMISSION;
        self::assertCount(1, $s->workPursuits()->history($student, $credit)['items']);
        $audit = $this->createMock(AuditTrail::class);
        $audit->expects(self::once())->method('record')->willReturnCallback(
            static function (EntityId $actor, string $action, EntityId $subject) use ($s): void {
                $s->audit->record($actor, $action, $subject);
                $s->access->grants = [];
            }
        );
        $before = $s->sql->rows('sis_audit');
        try {
            $s->workPursuits($audit)->history($student, $credit);
            self::fail('Revoked authority returned history.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $s->sql->rows('sis_audit'));
        }
    }

    public function testReplayAndDisplayedPageSpendOneSharedHistoryAllowance(): void
    {
        [$s, $student, $credit] = $this->fixture();
        $work = $s->sql->one('sis_academic_credits', ['id' => $credit->value]);
        $source = \Domain\AcademicRecord\Service\WorkPursuitLedger::source($work);
        $before = $this->facts($s);
        try {
            $s->workPursuitLedger()->timeline($credit, $source, new HistoryReadBudget(1, 1));
            self::fail('The displayed page reset the verification allowance.');
        } catch (HistoryReadLimitException) {
            self::assertSame($before, $this->facts($s));
        }
    }

    public function testActualHistoryRouteRetainsPrivacyCsrfAndDebugSuppression(): void
    {
        [$s, $student, $credit] = $this->fixture();
        $container = new \Qubus\Injector\Psr11\Container(\Qubus\Injector\Config\InjectorFactory::create([
            \Qubus\Injector\Injector::STANDARD_ALIASES => [
                \Psr\Http\Message\ResponseFactoryInterface::class => \Qubus\Routing\Factories\ResponseFactory::class,
                \Qubus\Routing\Interfaces\MiddlewareResolver::class =>
                    \Qubus\Routing\Route\InjectorMiddlewareResolver::class,
            ],
        ]));
        $container->share(new \Application\Http\Controller\SisWorkPursuitController($s->workPursuits()));
        $router = new \Qubus\Routing\Router(new \Qubus\Routing\Route\RouteCollector(), $container);
        (require dirname(__DIR__, 2) . '/routes/web/sis-workflows.php')($router);
        $handler = new \Qubus\Routing\Handlers\CallableRequestHandler($router->match(...));
        $request = new \Qubus\Http\ServerRequest(
            [],
            [],
            "/sis/students/{$student->value}/academic-work-pursuits/{$credit->value}/history/",
            'POST'
        )->withParsedBody([]);
        $privacy = new \Application\Http\Middleware\SisPrivacyMiddleware();
        $csrf = new \Codefy\Framework\Http\Middleware\Csrf\CsrfProtectionMiddleware(
            $this->createStub(\Qubus\Config\ConfigContainer::class),
            $this->createStub(\Qubus\Http\Cookies\Factory\HttpCookieFactory::class)
        );
        $before = $s->sql->rows('sis_audit');
        $response = $privacy->process($request, new \Qubus\Routing\Handlers\CallableRequestHandler(
            static fn($r) => $csrf->process($r, $handler)
        ));
        self::assertSame(403, $response->getStatusCode());
        self::assertSame($before, $s->sql->rows('sis_audit'));
        self::assertStringContainsString('private, no-store', $response->getHeaderLine('Cache-Control'));
        $debugContainer = $this->createMock(\Psr\Container\ContainerInterface::class);
        $debugContainer->expects(self::never())->method('get');
        $debug = new \Application\Http\Middleware\PrivateRouteDebugBarMiddleware($debugContainer);
        $response = $privacy->process($request, new \Qubus\Routing\Handlers\CallableRequestHandler(
            static fn($r) => $debug->process($r, $handler)
        ));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('private, no-store', $response->getHeaderLine('Cache-Control'));
        $result = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['attribute'], array_column($result['items'], 'action'));
    }
}
