<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/StudentSpecializationFixture.php';

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class StudentSpecializationTest extends TestCase
{
    public function testSelectionUsesTheStudentsFrozenPublicationAndLeavesAcademicFinancialAndDegreeFactsUntouched(): void
    {
        $f = new StudentSpecializationFixture();
        $before = [];
        foreach (['sis_academic_credits', 'sis_student_requirements', 'sis_student_accounts', 'sis_student_catalogs'] as $table) {
            $before[$table] = $f->sis->sql->rows($table);
        }
        $review = $f->review([$f->choices[0]['id'], $f->choices[1]['id']]);
        $f->sis->clock->time = $f->sis->clock->time->modify('+2 minutes');
        $receipt = $f->service->commit($f->student, $f->assignment, $review['review_token']);
        self::assertSame(1, $receipt['last_version']);
        $choice = $f->choices[0];
        $f->choiceService()->save($f->sis->program, new EntityId($choice['id']), 1, 'ART', 'New current title', null, false);
        $f->policies()->saveProgram($f->sis->program, 1, 0, 0);
        $f->publish();
        $view = $f->service->view($f->student, $f->assignment);
        self::assertSame(2, $view['program']['specializations']['effective']['maximum']);
        self::assertSame('Fictional ART', $view['selection']['specializations'][0]['title']);
        self::assertFalse($view['cohort_changed']);
        self::assertCount(2, $view['selection']['specializations']);
        self::assertSame($f->publication['operation_id'], $view['selection']['program_version_id']);
        $history = $f->service->history($f->student, $f->assignment);
        self::assertStringContainsString('10:02:00', $history['items'][0]['effective_at']);
        self::assertArrayNotHasKey('reason', $history['items'][0]);
        self::assertArrayNotHasKey('actor_id', $history['items'][0]);
        foreach ($before as $table => $rows) {
            self::assertSame($rows, $f->sis->sql->rows($table), $table);
        }
        $raw = json_encode($f->sis->sql->rows('sis_history_events'), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Fictional registrar selection.', $raw);
    }

    public function testCompetingReviewsExpireAndAcceptedRetriesNeverUndoLaterDecisionsEvenAfterClosure(): void
    {
        $f = new StudentSpecializationFixture();
        $first = $f->review([$f->choices[0]['id']]);
        $competing = $f->review([$f->choices[1]['id']]);
        $receipt = $f->service->commit($f->student, $f->assignment, $first['review_token']);
        try {
            $f->service->commit($f->student, $f->assignment, $competing['review_token']);
            self::fail('A stale competing review was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertTrue(true);
        }
        $expired = $f->review([]);
        $f->sis->clock->time = $f->sis->clock->time->modify('+20 minutes');
        try {
            $f->service->commit($f->student, $f->assignment, $expired['review_token']);
            self::fail('A review was accepted at its expiry.');
        } catch (ConcurrentModificationException) {
            self::assertTrue(true);
        }
        $f->select([$f->choices[1]['id']]);
        $f->select([]);
        $s = $f->sis;
        $s->transactions->run(function () use ($f, $s): void {
            $records = new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($s->sql);
            $records->lock($f->student);
            $s->programStatusLedger()->change($records->assignment($f->assignment), 'closed', EntityId::generate(), $s->access->actor, 'Fictional closure.');
        });
        $before = $s->sql->rows('sis_history_events');
        self::assertSame($receipt, $f->service->commit($f->student, $f->assignment, $first['review_token']));
        self::assertSame([], $f->service->view($f->student, $f->assignment)['selection']['specializations']);
        self::assertSame($before, $s->sql->rows('sis_history_events'));
        $this->expectException(ValidationException::class);
        $f->review([$f->choices[0]['id']]);
    }

    public function testChoicesRespectRetainedLimitsRequiredCountsAndDistinctProgramOwnedIdentities(): void
    {
        $f = new StudentSpecializationFixture();
        foreach ([array_column($f->choices, 'id'), [EntityId::generate()->value], [$f->choices[0]['id'], $f->choices[0]['id']]] as $ids) {
            try {
                $f->review($ids);
                self::fail('An invalid selection was reviewed.');
            } catch (ValidationException | InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $f->policies()->saveProgram($f->sis->program, 1, 1, 1);
        $publication = $f->publish();
        $this->bind($f, $publication['operation_id']);
        try {
            $f->review([]);
            self::fail('A mandatory choice was removed.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        $f->select([$f->choices[0]['id']]);
        $this->expectException(ValidationException::class);
        $f->review([$f->choices[0]['id']]);
    }

    private function bind(StudentSpecializationFixture $f, string $catalog): void
    {
        $repo = new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($f->sis->sql);
        $f->sis->transactions->run(function () use ($repo, $f, $catalog): void {
            $f->sis->sql->lock('sis_students', $f->student->value, 'id');
            $repo->bind($f->assignment, new EntityId($catalog), $repo->binding($f->assignment)['version']);
        });
    }

    public function testCohortChangesRequireExplicitNewSelectionAndChangingBackStillInvalidatesAReview(): void
    {
        $f = new StudentSpecializationFixture();
        $f->select([$f->choices[0]['id']]);
        $pending = $f->review([$f->choices[1]['id']]);
        $oldView = $f->service->view($f->student, $f->assignment);
        $next = $f->publish();
        $this->bind($f, $next['operation_id']);
        $view = $f->service->view($f->student, $f->assignment);
        self::assertTrue($view['cohort_changed']);
        self::assertSame($oldView['selection'], $view['selection']);
        $this->bind($f, $f->publication['operation_id']);
        try {
            $f->service->commit($f->student, $f->assignment, $pending['review_token']);
            self::fail('A changed-and-restored cohort reused a stale review.');
        } catch (ConcurrentModificationException) {
            self::assertTrue(true);
        }
        $f->select([$f->choices[1]['id']]);
        self::assertFalse($f->service->view($f->student, $f->assignment)['cohort_changed']);
    }

    public function testStaffReadAndAssignmentAuthorityAreSeparateAndForeignOrWrongActorReviewsAreRejected(): void
    {
        $f = new StudentSpecializationFixture();
        $review = $f->review([$f->choices[0]['id']]);
        $f->sis->access->staff = false;
        $f->sis->access->grants = ['sis:student:read'];
        self::assertFalse($f->service->view($f->student, $f->assignment)['can_manage']);
        try {
            $f->service->commit($f->student, $f->assignment, $review['review_token']);
            self::fail('Student-read authority assigned a specialization.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $f->sis->access->grants = ['sis:degree-audit:assign'];
        try {
            $f->service->view($f->student, $f->assignment);
            self::fail('Assignment permission bypassed private student reads.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $f->sis->access->staff = true;
        $original = $f->sis->access->actor;
        $f->sis->access->actor = $f->sis->user();
        try {
            $f->service->commit($f->student, $f->assignment, $review['review_token']);
            self::fail('Another actor reused a review.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        $f->sis->access->actor = $original;
        $other = $f->sis->student();
        $this->expectException(RecordNotFoundException::class);
        $f->service->view($other->id, $f->assignment);
    }

    public function testProjectionCorruptionFailsClosedAndAuditFailureRollsBackAllSelectionWrites(): void
    {
        $f = new StudentSpecializationFixture();
        $review = $f->review([$f->choices[0]['id']]);
        $audit = new class implements \Domain\Audit\Repository\AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void { throw new RuntimeException('Fictional audit failure.'); }
            public function forSubject(EntityId $subject): array { return []; }
        };
        $before = $f->sis->sql->rows('sis_history_events');
        try {
            $f->service($audit)->commit($f->student, $f->assignment, $review['review_token']);
            self::fail('A selection committed without its audit.');
        } catch (RuntimeException) {
            self::assertSame($before, $f->sis->sql->rows('sis_history_events'));
            self::assertSame([], $f->sis->sql->rows('sis_student_specializations'));
        }
        $f->service->commit($f->student, $f->assignment, $review['review_token']);
        $f->sis->sql->update('sis_student_specializations', ['selected_json' => '[]'], ['id' => $f->assignment->value]);
        $this->expectException(CorruptHistoryException::class);
        $f->service->view($f->student, $f->assignment);
    }

    public function testHistoryPagesFreezeTheirHeadAndResolveOriginalLabelsAfterMoreDecisions(): void
    {
        $f = new StudentSpecializationFixture();
        foreach (range(1, 12) as $n) {
            $f->select([$f->choices[$n % 2]['id']]);
        }
        $page = $f->service->history($f->student, $f->assignment);
        self::assertCount(10, $page['items']);
        self::assertNotNull($page['next_cursor']);
        $f->select([$f->choices[1]['id']]);
        $last = $f->service->history($f->student, $f->assignment, $page['next_cursor']);
        self::assertSame([11, 12], array_column($last['items'], 'version'));
        self::assertNull($last['next_cursor']);
        self::assertSame('Fictional DATA', $last['items'][0]['selection']['specializations'][0]['title']);
    }

    public function testUnversionedCohortsStayUnknownAndMissingRetainedPublicationEvidenceIsRejected(): void
    {
        $f = new StudentSpecializationFixture();
        $repo = new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($f->sis->sql);
        $unknown = EntityId::generate();
        $repo->addRelease($unknown, [
            'program_id' => $f->sis->program->value, 'year_id' => $f->year['id'],
            'program_code' => 'BSCS', 'program_title' => 'Earlier unknown program',
            'revision' => 50, 'published' => 1, 'is_current' => 0,
        ]);
        $this->bind($f, $unknown->value);
        $view = $f->service->view($f->student, $f->assignment);
        self::assertNull($view['program']);
        self::assertFalse($view['can_manage']);
        try {
            $f->review([$f->choices[0]['id']]);
            self::fail('Current catalog choices were borrowed for an unknown earlier cohort.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        $this->bind($f, $f->publication['operation_id']);
        $f->sis->sql->execute('DELETE FROM sis_program_catalog_details WHERE id = ?', [$f->publication['operation_id']]);
        $this->expectException(CorruptHistoryException::class);
        $f->service->view($f->student, $f->assignment);
    }

    public function testAuthorityIsRecheckedAfterReplayAndVerificationCannotRunWithoutBounds(): void
    {
        $f = new StudentSpecializationFixture();
        $review = $f->review([$f->choices[0]['id']]);
        $before = $f->sis->sql->rows('sis_history_events');
        $history = new class ($f) implements \Domain\Shared\EventSourcing\HistoricalEventStore {
            public bool $endless = false;
            public int $pages = 0;
            public function __construct(private StudentSpecializationFixture $f) {}
            public function page(\Domain\Shared\EventSourcing\StreamReference $stream, ?string $cursor = null, int $limit = 50): \Domain\Shared\EventSourcing\EventPage
            {
                $this->pages++;
                if ($this->endless) {
                    return new \Domain\Shared\EventSourcing\EventPage([], 'fictional-next-page', 0);
                }
                $page = $this->f->history->page($stream, $cursor, $limit);
                $this->f->sis->access->staff = false;
                $this->f->sis->access->grants = ['sis:student:read'];
                return $page;
            }
            public function append(\Domain\Shared\EventSourcing\StreamReference $stream, EntityId $operation, int $expectedVersion, \Domain\Shared\EventSourcing\EventContext $context, array $events): array { throw new LogicException('No write should reach the journal.'); }
            public function latest(\Domain\Shared\EventSourcing\StreamReference $stream): ?\Domain\Shared\EventSourcing\StoredEvent { throw new LogicException(); }
            public function verifyOperation(EntityId $operation): int { throw new LogicException(); }
        };
        try {
            $f->service(history: $history)->commit($f->student, $f->assignment, $review['review_token']);
            self::fail('Revoked assignment authority committed a selection.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $f->sis->sql->rows('sis_history_events'));
        }
        $f->sis->access->staff = true;
        $history->endless = true;
        $history->pages = 0;
        try {
            $f->service(history: $history)->view($f->student, $f->assignment);
            self::fail('Verification exceeded its request budget.');
        } catch (\Domain\Shared\EventSourcing\HistoryReadLimitException) {
            self::assertSame(399, $history->pages); // The status stream consumed the first shared page.
            self::assertSame($before, $f->sis->sql->rows('sis_history_events'));
        }
    }
}
