<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/StudentSpecializationRequirementsFixture.php';

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class StudentSpecializationRequirementsTest extends TestCase
{
    private function read(StudentSpecializationRequirementsFixture $f, string $code = 'DATA'): array
    {
        return $f->service->assignedRequirements($f->student, $f->assignment, new EntityId($f->choices[$code]['id']));
    }
    public function testAnExactPublicationIsRetainedWithoutChangingDegreeAcademicOrFinancialFacts(): void
    {
        $f = new StudentSpecializationRequirementsFixture();
        $s = $f->r->sis;
        $before = [];
        foreach (['sis_student_requirements', 'sis_academic_credits', 'sis_student_accounts', 'sis_student_catalogs'] as $table) {
            $before[$table] = $s->sql->rows($table);
        }
        $review = $f->review();
        $s->clock->time = $s->clock->time->modify('+2 minutes');
        $receipt = $f->service->assignRequirements($f->student, $f->assignment, new EntityId($f->choices['DATA']['id']), $review['review_token']);
        self::assertSame(2, $receipt['last_version']);
        $later = $f->publish();
        self::assertNotSame($later, $f->versions['DATA']);
        $assigned = $this->read($f);
        self::assertSame($f->versions['DATA'], $assigned['selection']['requirements_version_id']);
        self::assertSame($f->programVersion, $assigned['requirements']['program_version_id']);
        self::assertSame($f->r->groupVersion->value, $assigned['requirements']['definition']['groups'][0]['version_id']);
        $view = $f->service->view($f->student, $f->assignment);
        self::assertSame('Fictional DATA requirements', $view['requirements'][0]['requirements_name']);
        $timeline = $f->service->history($f->student, $f->assignment);
        self::assertSame(['choices', 'requirements'], array_column($timeline['items'], 'kind'));
        self::assertStringContainsString('10:02:00', $timeline['items'][1]['effective_at']);
        self::assertArrayNotHasKey('reason', $timeline['items'][1]);
        self::assertArrayNotHasKey('actor_id', $timeline['items'][1]);
        foreach ($before as $table => $rows) {
            self::assertSame($rows, $s->sql->rows($table), $table);
        }
        self::assertStringNotContainsString('Fictional protected assignment reason.', json_encode($s->sql->rows('sis_history_events')));
    }
    public function testASelectionChangePreservesOtherBindingsAndRemovedChoicesRequireANewRequirementsReview(): void
    {
        $f = new StudentSpecializationRequirementsFixture();
        $first = $f->assign();
        $data = $this->read($f)['selection'];
        $f->select(['DATA', 'ART']);
        self::assertSame($data, $this->read($f)['selection']);
        $f->assign('ART');
        $art = $this->read($f, 'ART')['selection'];
        $f->select(['ART']);
        self::assertNull($this->read($f)['selection']);
        self::assertSame($art, $this->read($f, 'ART')['selection']);
        $f->select(['ART', 'DATA']);
        self::assertNull($this->read($f)['selection']);
        self::assertSame($art, $this->read($f, 'ART')['selection']);
        $before = $f->r->sis->sql->rows('sis_history_events');
        $f->assign();
        self::assertNotSame($first['operation_id'], $this->read($f)['selection']['decision_id']);
        self::assertGreaterThan(count($before), count($f->r->sis->sql->rows('sis_history_events')));
    }
    public function testRetriesReturnTheOriginalReceiptAfterLaterAssignmentsRemovalAndClosure(): void
    {
        $f = new StudentSpecializationRequirementsFixture();
        $review = $f->review();
        $choice = new EntityId($f->choices['DATA']['id']);
        $first = $f->service->assignRequirements($f->student, $f->assignment, $choice, $review['review_token']);
        $f->assign('DATA', $f->publish());
        $f->select([]);
        $s = $f->r->sis;
        $s->transactions->run(function () use ($f, $s): void {
            $records = new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($s->sql);
            $records->lock($f->student);
            $s->programStatusLedger()->change($records->assignment($f->assignment), 'closed', EntityId::generate(), $s->access->actor, 'Fictional closure.');
        });
        $s->clock->time = $s->clock->time->modify('+1 day');
        $before = $s->sql->rows('sis_history_events');
        self::assertSame($first, $f->service->assignRequirements($f->student, $f->assignment, $choice, $review['review_token']));
        self::assertNull($this->read($f)['selection']);
        self::assertSame($before, $s->sql->rows('sis_history_events'));
    }
    public function testWrongSpecializationDegreeOrProgramPublicationCannotBeAssigned(): void
    {
        $f = new StudentSpecializationRequirementsFixture();
        $f->r->saveDegree();
        $base = $f->r->publishDegree()['operation_id'];
        $choice = new EntityId($f->choices['DATA']['id']);
        $page = $f->service->requirementChoices($f->student, $f->assignment, $choice);
        foreach ([['ART', $f->versions['ART']], ['base', $base]] as [$code, $version]) {
            try {
                $template = $code === 'base' ? $f->r->degree : new EntityId($f->plans[$code]['id']);
                $f->service->reviewRequirements($f->student, $f->assignment, $choice, $page['revision'], $template,
                    new EntityId($version), 'Fictional wrong scope.');
                self::fail('A publication from another audit scope was assigned.');
            } catch (ValidationException) { self::assertTrue(true); }
        }
        $r = $f->program->service->review($f->r->sis->program, $f->r->year, 'Fictional later program revision.');
        $f->program->service->publish($f->r->sis->program, $r['review_token']);
        $later = $f->publish();
        $page = $f->service->requirementChoices($f->student, $f->assignment, $choice);
        self::assertSame([true, false], array_column($page['items'], 'assignable'));
        $this->expectException(ValidationException::class);
        $f->service->reviewRequirements($f->student, $f->assignment, $choice, $page['revision'], new EntityId($f->plans['DATA']['id']),
            new EntityId($later), 'Fictional incompatible program revision.');
    }
    public function testStaleCompetingAndExpiredReviewsCannotOverwriteASelection(): void
    {
        $f = new StudentSpecializationRequirementsFixture();
        $pending = $f->review();
        $f->select(['ART', 'DATA']);
        $choice = new EntityId($f->choices['DATA']['id']);
        try {
            $f->service->assignRequirements($f->student, $f->assignment, $choice, $pending['review_token']);
            self::fail('Changed choices did not invalidate a requirement review.');
        } catch (ConcurrentModificationException) { self::assertTrue(true); }
        $pending = $f->review();
        $f->r->sis->clock->time = $f->r->sis->clock->time->modify('+20 minutes');
        $before = $f->r->sis->sql->rows('sis_history_events');
        try {
            $f->service->assignRequirements($f->student, $f->assignment, $choice, $pending['review_token']);
            self::fail('An expired requirement review was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $f->r->sis->sql->rows('sis_history_events'));
        }
        $a = $f->review();
        $b = $f->review('ART');
        $f->service->assignRequirements($f->student, $f->assignment, $choice, $a['review_token']);
        $this->expectException(ConcurrentModificationException::class);
        $f->service->assignRequirements($f->student, $f->assignment, new EntityId($f->choices['ART']['id']), $b['review_token']);
    }
    public function testPermissionAndActorBoundariesAndForeignStudentScopeAreRechecked(): void
    {
        $f = new StudentSpecializationRequirementsFixture();
        $review = $f->review();
        $choice = new EntityId($f->choices['DATA']['id']);
        $s = $f->r->sis;
        $s->access->staff = false;
        $s->access->grants = ['sis:student:read'];
        self::assertFalse($this->read($f)['can_manage']);
        try {
            $f->service->assignRequirements($f->student, $f->assignment, $choice, $review['review_token']);
            self::fail('Read-only staff assigned requirements.');
        } catch (AccessDeniedException) { self::assertTrue(true); }
        $s->access->grants = ['sis:degree-audit:assign'];
        try { $this->read($f); self::fail('Assignment authority bypassed student privacy.'); }
        catch (AccessDeniedException) { self::assertTrue(true); }
        $s->access->staff = true;
        $actor = $s->access->actor;
        $s->access->actor = $s->user();
        try {
            $f->service->assignRequirements($f->student, $f->assignment, $choice, $review['review_token']);
            self::fail('Another actor reused a review.');
        } catch (ValidationException) { self::assertTrue(true); }
        $s->access->actor = $actor;
        $other = $s->student();
        $this->expectException(RecordNotFoundException::class);
        $f->service->assignedRequirements($other->id, $f->assignment, $choice);
    }
    public function testAuditFailureRollsBackAllWritesAndCorruptBindingProjectionsAreRejected(): void
    {
        $f = new StudentSpecializationRequirementsFixture();
        $review = $f->review();
        $choice = new EntityId($f->choices['DATA']['id']);
        $audit = new class implements \Domain\Audit\Repository\AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void { throw new RuntimeException('Fictional audit failure.'); }
            public function forSubject(EntityId $subject): array { return []; }
        };
        $tables = ['sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_student_specializations', 'sis_student_specialization_requirements'];
        $before = [];
        foreach ($tables as $table) { $before[$table] = $f->r->sis->sql->rows($table); }
        try {
            $f->service($audit)->assignRequirements($f->student, $f->assignment, $choice, $review['review_token']);
            self::fail('Assignment committed without its required audit.');
        } catch (RuntimeException) {
            foreach ($before as $table => $rows) { self::assertSame($rows, $f->r->sis->sql->rows($table), $table); }
        }
        $f->service->assignRequirements($f->student, $f->assignment, $choice, $review['review_token']);
        $row = $f->r->sis->sql->rows('sis_student_specialization_requirements')[0];
        $payload = json_decode($row['reference_json'], true);
        $payload['requirements_name'] = 'Unverified label';
        $f->r->sis->sql->update('sis_student_specialization_requirements', ['reference_json' => json_encode($payload)], ['id' => $row['id']]);
        $this->expectException(CorruptHistoryException::class);
        $f->service->view($f->student, $f->assignment);
    }
    public function testCohortChangesRetainEarlierRequirementsButRequireFreshChoiceAndRequirementsReviews(): void
    {
        $f = new StudentSpecializationRequirementsFixture();
        $f->assign();
        $retained = $this->read($f)['selection'];
        $pending = $f->review('DATA', $f->publish());
        $s = $f->r->sis;
        $repo = new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($s->sql);
        $bind = function (string $version) use ($f, $s, $repo): void {
            $s->transactions->run(function () use ($f, $s, $repo, $version): void {
                $s->sql->lock('sis_students', $f->student->value, 'id');
                $repo->bind($f->assignment, new EntityId($version), $repo->binding($f->assignment)['version']);
            });
        };
        $review = $f->program->service->review($s->program, $f->r->year, 'Fictional new cohort definition.');
        $next = $f->program->service->publish($s->program, $review['review_token']);
        $bind($next['operation_id']);
        $read = $this->read($f);
        self::assertSame($retained, $read['selection']);
        self::assertTrue($read['cohort_changed']);
        self::assertFalse($read['can_manage']);
        self::assertSame($f->versions['DATA'], $read['requirements']['version_id']);
        $bind($f->programVersion);
        try {
            $f->service->assignRequirements($f->student, $f->assignment, new EntityId($f->choices['DATA']['id']), $pending['review_token']);
            self::fail('A changed-and-restored cohort accepted an old requirements review.');
        } catch (ValidationException|ConcurrentModificationException) { self::assertTrue(true); }
        $f->select(['DATA']);
        self::assertNull($this->read($f)['selection']);
        self::assertTrue($this->read($f)['can_manage']);
        self::assertSame('requirements', $f->service->history($f->student, $f->assignment)['items'][1]['kind']);
        $f->assign();
        self::assertNotSame($retained['decision_id'], $this->read($f)['selection']['decision_id']);
    }
    public function testMixedHistoryPagesRetainTheirHeadAfterFurtherAssignments(): void
    {
        $f = new StudentSpecializationRequirementsFixture();
        foreach (range(1, 6) as $n) {
            $f->assign();
            $f->select([]);
            $f->select(['DATA']);
        }
        $page = $f->service->history($f->student, $f->assignment);
        self::assertCount(10, $page['items']);
        $f->assign();
        $tail = $f->service->history($f->student, $f->assignment, $page['next_cursor']);
        self::assertSame(range(11, 19), array_column($tail['items'], 'version'));
        self::assertNull($tail['next_cursor']);
        self::assertSame(['requirements', 'choices'], array_values(array_unique(array_column($tail['items'], 'kind'))));
    }
    public function testAssignmentRechecksRevokedAuthorityAndSharesOneBoundedReplayBudget(): void
    {
        $f = new StudentSpecializationRequirementsFixture();
        $review = $f->review();
        $before = $f->r->sis->sql->rows('sis_history_events');
        $history = new class ($f) implements \Domain\Shared\EventSourcing\HistoricalEventStore {
            public bool $endless = false;
            public int $pages = 0;
            public function __construct(private StudentSpecializationRequirementsFixture $f) {}
            public function page(\Domain\Shared\EventSourcing\StreamReference $stream, ?string $cursor = null, int $limit = 50): \Domain\Shared\EventSourcing\EventPage
            {
                $this->pages++;
                if ($this->endless) { return new \Domain\Shared\EventSourcing\EventPage([], 'fictional-next-page', 0); }
                $page = $this->f->r->history->page($stream, $cursor, $limit);
                $this->f->r->sis->access->staff = false;
                $this->f->r->sis->access->grants = ['sis:student:read'];
                return $page;
            }
            public function append(\Domain\Shared\EventSourcing\StreamReference $stream, EntityId $operation, int $expectedVersion, \Domain\Shared\EventSourcing\EventContext $context, array $events): array { throw new LogicException('No write should reach the journal.'); }
            public function latest(\Domain\Shared\EventSourcing\StreamReference $stream): ?\Domain\Shared\EventSourcing\StoredEvent { throw new LogicException(); }
            public function verifyOperation(EntityId $operation): int { throw new LogicException(); }
        };
        $choice = new EntityId($f->choices['DATA']['id']);
        try {
            $f->service(history: $history)->assignRequirements($f->student, $f->assignment, $choice, $review['review_token']);
            self::fail('Revoked authority assigned requirements.');
        } catch (AccessDeniedException) { self::assertSame($before, $f->r->sis->sql->rows('sis_history_events')); }
        $f->r->sis->access->staff = true;
        $history->endless = true;
        $history->pages = 0;
        try {
            $f->service(history: $history)->assignRequirements($f->student, $f->assignment, $choice, $review['review_token']);
            self::fail('Assignment verification exceeded its shared request budget.');
        } catch (\Domain\Shared\EventSourcing\HistoryReadLimitException) {
            self::assertSame(399, $history->pages);
            self::assertSame($before, $f->r->sis->sql->rows('sis_history_events'));
        }
    }

}
