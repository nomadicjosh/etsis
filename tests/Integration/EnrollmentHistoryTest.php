<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";
use Domain\Registration\EnrollmentHistory;
use Domain\Registration\Service\EnrollmentHistoryService;
use Domain\Registration\ValueObject\EnrollmentHistoryId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlEnrollmentHistoryRepository;
use PHPUnit\Framework\TestCase;
final class EnrollmentHistoryTest extends TestCase
{
    private function replay(SisFixture $f, EntityId $id): EnrollmentHistory
    {
        $root = EnrollmentHistory::root(new EnrollmentHistoryId($id->value));
        $historyCases1 = EventStoreFixture::framework($f->connection->queryBuilder())->getAggregateHistoryFor(
            new EnrollmentHistoryId($id->value),
        );
        foreach ($historyCases1 as $e) {
            $root->acceptHistorical($e);
        }
        return $root;
    }
    private function history(SisFixture $f): EnrollmentHistoryService
    {
        return new EnrollmentHistoryService(
            new SqlEnrollmentHistoryRepository($f->sql),
            $f->registration,
            $f->enrollmentLedger(),
            $f->access,
            $f->transactions,
            $f->audit,
        );
    }
    private function oldEnrollment(SisFixture $f): array
    {
        $student = $f->student();
        $section = $f->section();
        $id = EntityId::generate();
        $course = $f->registration->course($section->courseId);
        $f->sql->insert("sis_enrollments", [
            "id" => $id->value,
            "student_id" => $student->id->value,
            "section_id" => $section->id->value,
            "term_id" => $f->term->value,
            "status" => "A",
            "registered_at" => "2026-08-01 17:00:00",
            "status_at" => "2026-08-01 17:00:00",
            "version" => 0,
        ]);
        $f->sql->insert("sis_academic_credits", [
            "id" => EntityId::generate()->value,
            "student_id" => $student->id->value,
            "course_id" => $course->id->value,
            "term_id" => $f->term->value,
            "source_id" => $id->value,
            "credit_type" => "institutional",
            "course_code" => $course->code,
            "course_title" => $course->title,
            "attempted_milli" => 3000,
            "completed_milli" => 0,
            "grade_points_milli" => 0,
            "grade_id" => null,
            "status" => "A",
            "version" => 0,
        ]);
        return [$student, $section, $id];
    }
    public function testAcceptedEnrollmentReplaysWithTheCapacityAndEligibilityActuallyUsed(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section(2, 1250);
        $e = $f->service->register($student->id, $section->id);
        $root = $this->replay($f, $e->id);
        self::assertEquals($root->facts(), new SqlEnrollmentHistoryRepository($f->sql)->current($e->id));
        self::assertSame(1, $root->seat()["sequence"]);
        self::assertSame(0, $root->seat()["used_before"]);
        self::assertSame(1, $root->seat()["used_after"]);
        self::assertSame(1250, $root->source()["fee_minor"]);
        self::assertSame("registered", $root->provenance());
        $events = iterator_to_array(
            EventStoreFixture::framework($f->connection->queryBuilder())->getAggregateHistoryFor(
                new EnrollmentHistoryId($e->id->value),
            ),
        );
        self::assertSame([], $events[0]->payload()["eligibility"]["required_course_ids"]);
        self::assertTrue($events[0]->payload()["eligibility"]["prerequisites_met"]);
        $before = $f->sql->rows("sis_history_events");
        self::assertSame($e->id->value, $f->service->register($student->id, $section->id)->id->value);
        self::assertSame($before, $f->sql->rows("sis_history_events"));
    }
    public function testSectionOrderIncludesReleasesAndRetakesWithoutResurrectingEarlyRecords(): void
    {
        $f = new SisFixture();
        $a = $f->student();
        $b = $f->student();
        $c = $f->student();
        $section = $f->section(2);
        $one = $f->service->register($a->id, $section->id);
        $f->service->register($b->id, $section->id);
        $f->drops->drop($a->id, $section->id);
        $third = $f->service->register($c->id, $section->id);
        $f->drops->drop($c->id, $section->id);
        $again = $f->service->register($a->id, $section->id);
        $rows = $f->sql->fetch("SELECT * FROM sis_registration_decisions ORDER BY sequence");
        self::assertSame(range(1, 6), array_column($rows, "sequence"));
        self::assertNotSame($one->id->value, $again->id->value);
        self::assertNull(new SqlEnrollmentHistoryRepository($f->sql)->current($one->id));
        self::assertSame("D", $this->replay($f, $one->id)->facts()["status"]);
        self::assertSame(2, $f->registration->sectionCount($section->id));
        $history = $this->history($f)->student($a->id);
        self::assertCount(2, $history["items"]);
        self::assertCount(
            1,
            array_filter($history["items"], fn(array $r): bool => $r["current_records_removed"]),
        );
        self::assertCount(1, array_filter($history["items"], fn(array $r): bool => $r["status"] === "N"));
        self::assertSame("D", $this->replay($f, $third->id)->facts()["status"]);
    }
    public function testLateDropReleasesTheSeatAndKeepsItsChargeAndCurrentAcademicRecord(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section(1);
        $e = $f->service->register($student->id, $section->id);
        $finance = $f->financialEvents();
        $f->clock->time = new DateTimeImmutable("2026-09-01T10:00:00-07:00");
        $f->drops->drop($student->id, $section->id);
        $root = $this->replay($f, $e->id);
        self::assertSame("W", $root->facts()["status"]);
        self::assertSame(2, $root->seat()["sequence"]);
        self::assertSame(0, $root->seat()["used_after"]);
        self::assertEquals($root->facts(), new SqlEnrollmentHistoryRepository($f->sql)->current($e->id));
        self::assertSame($finance, $f->financialEvents());
        self::assertCount(1, $f->sql->rows("sis_academic_credits", ["source_id" => $e->id->value]));
    }
    public function testOlderEnrollmentIsObservedOnlyAtAnActualReviewedClosure(): void
    {
        $f = new SisFixture();
        [$student, $section, $id] = $this->oldEnrollment($f);
        $before = $f->sql->rows("sis_history_events");
        self::assertSame([], $this->history($f)->student($student->id)["items"]);
        $f->service->register($student->id, $section->id);
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        $review = $f->drops->review($student->id, $section->id);
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        $f->drops->drop($student->id, $section->id);
        $root = $this->replay($f, $id);
        self::assertSame("observed_existing", $root->provenance());
        self::assertSame(1, $root->seat()["sequence"]);
        self::assertNull($root->source()["course_version_id"]);
        self::assertSame("2026-08-01 17:00:00", $root->facts()["registered_at"]);
        $record = $this->history($f)->student($student->id)["items"][0];
        self::assertTrue($record["earlier_history_unknown"]);
        self::assertSame(["observed_existing", "early_drop"], array_column($record["items"], "action"));
        self::assertStringStartsWith("2026-08-10", $record["items"][0]["effective_at"]);
    }
    public function testBillingFailureAndDropAuditFailureRollbackBothJournalsAndSeatIndices(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section();
        $account = \Domain\Finance\StudentAccount::open(
            new \Domain\Finance\ValueObject\AccountId(),
            $student->id,
            $f->term,
            "EUR",
        );
        $f->accounts->save($account);
        $events = $f->sql->rows("sis_history_events");
        try {
            $f->service->register($student->id, $section->id);
            self::fail("Billing failure left an accepted seat.");
        } catch (DomainException) {
            self::assertSame($events, $f->sql->rows("sis_history_events"));
            $historyCases2 = [
                    "sis_registration_streams",
                    "sis_registration_decisions",
                    "sis_enrollments",
                    "sis_academic_credits",
                ];
            foreach ($historyCases2 as $table) {
                self::assertSame([], $f->sql->rows($table));
            }
        }
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section();
        $e = $f->service->register($student->id, $section->id);
        $before = [];
        $historyCases3 = [
                "sis_history_events",
                "sis_history_operations",
                "sis_registration_streams",
                "sis_registration_decisions",
                "sis_enrollments",
                "sis_academic_credits",
            ];
        foreach ($historyCases3 as $table) {
            $before[$table] = $f->sql->rows($table);
        }
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit
            ->method("record")
            ->willThrowException(new RuntimeException("Fictional closure audit failure."));
        try {
            $f->dropService(audit: $audit)->drop($student->id, $section->id);
            self::fail("Closure partly committed.");
        } catch (RuntimeException $error) {
            self::assertSame("Fictional closure audit failure.", $error->getMessage());
        }
        foreach ($before as $table => $rows) {
            self::assertSame($rows, $f->sql->rows($table));
        }
        self::assertSame(1, $this->replay($f, $e->id)->playhead());
    }
    public function testMissingRoutingOrDecisionIndexAndUnrecordedSeatChangesFailClosed(): void
    {
        foreach (["routing", "index", "seat_count"] as $kind) {
            $f = new SisFixture();
            $student = $f->student();
            $section = $f->section();
            $e = $f->service->register($student->id, $section->id);
            if ($kind === "routing") {
                $f->sql->execute("DELETE FROM sis_registration_streams WHERE id = ?", [$e->id->value]);
            } elseif ($kind === "index") {
                $f->sql->execute("DELETE FROM sis_registration_decisions WHERE enrollment_id = ?", [
                    $e->id->value,
                ]);
            } else {
                $other = $f->student();
                $f->sql->insert("sis_enrollments", [
                    "id" => EntityId::generate()->value,
                    "student_id" => $other->id->value,
                    "section_id" => $section->id->value,
                    "term_id" => $f->term->value,
                    "status" => "A",
                    "registered_at" => "2026-08-10 17:00:00",
                    "status_at" => "2026-08-10 17:00:00",
                    "version" => 0,
                ]);
            }
            try {
                $f->service->register($student->id, $section->id);
                self::fail("An altered registration projection was trusted.");
            } catch (CorruptHistoryException) {
                self::assertTrue(true);
            }
        }
    }
    public function testCapacityReductionDoesNotPreventAReleaseOrRewriteTheOriginalOffering(): void
    {
        $f = new SisFixture();
        $a = $f->student();
        $b = $f->student();
        $section = $f->section(2, 1250);
        $e = $f->service->register($a->id, $section->id);
        $f->service->register($b->id, $section->id);
        $f->sql->update(
            "sis_sections",
            ["capacity" => 1, "fee_minor" => 2500, "code" => "UPDATED"],
            ["id" => $section->id->value],
        );
        $f->drops->drop($a->id, $section->id);
        $root = $this->replay($f, $e->id);
        self::assertSame(2, $root->source()["capacity"]);
        self::assertSame(1250, $root->source()["fee_minor"]);
        self::assertSame($section->code, $root->source()["section_code"]);
        self::assertSame(1, $root->seat()["capacity"]);
        self::assertSame(1, $f->registration->sectionCount($section->id));
    }
    public function testRestrictedHistoryRequiresRegistrationAuthorityAndHasBoundedStudentPages(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section();
        for ($i = 0; $i < 21; ++$i) {
            $f->service->register($student->id, $section->id);
            $f->drops->drop($student->id, $section->id);
        }
        $first = $this->history($f)->student($student->id);
        self::assertCount(20, $first["items"]);
        self::assertNotNull($first["next_after"]);
        $last = $this->history($f)->student($student->id, new EntityId($first["next_after"]));
        self::assertCount(1, $last["items"]);
        self::assertNull($last["next_after"]);
        $json = json_encode($first, JSON_THROW_ON_ERROR);
        foreach (["actor_id", "reason", "eligibility", "previous_decision_id"] as $private) {
            self::assertStringNotContainsString('"' . $private . '"', $json);
        }
        $f->access->staff = false;
        foreach ([[], ["sis:student:read"]] as $grants) {
            $f->access->grants = $grants;
            try {
                $this->history($f)->student($student->id);
                self::fail("Ordinary academic access exposed restricted early drops.");
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
        $f->access->grants = ["sis:registration:manage"];
        self::assertCount(20, $this->history($f)->student($student->id)["items"]);
        $this->expectException(RecordNotFoundException::class);
        $this->history($f)->student($student->id, EntityId::generate());
    }
    public function testRemovedEnrollmentRoutingAndOrderRebuildWithoutReplayingBilling(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section();
        $e = $f->service->register($student->id, $section->id);
        $f->drops->drop($student->id, $section->id);
        $root = $this->replay($f, $e->id);
        $before = $f->sql->rows("sis_history_events");
        $finance = $f->financialEvents();
        $stage = new \Qubus\Expressive\Connection\Pdo\Sqlite([
            "driver" => "pdo_sqlite",
            "dsn" => "sqlite::memory:",
        ]);
        $stage->pdo->exec(
            "CREATE TABLE sis_enrollments (id TEXT PRIMARY KEY,student_id TEXT,section_id TEXT," .
                "term_id TEXT,status TEXT,registered_at TEXT,status_at TEXT,version INTEGER)",
        );
        $stage->pdo->exec(
            "CREATE TABLE sis_registration_streams (id TEXT PRIMARY KEY,student_id TEXT," .
                "section_id TEXT,term_id TEXT,last_sequence INTEGER)",
        );
        $stage->pdo->exec(
            "CREATE TABLE sis_registration_decisions (id TEXT PRIMARY KEY,enrollment_id TEXT," .
                "section_id TEXT,sequence INTEGER,stream_version INTEGER)",
        );
        $repo = new SqlEnrollmentHistoryRepository(
            new \Infrastructure\Persistence\SqlRecords($stage->queryBuilder()),
        );
        $repo->project($root->facts(), $root->seat()["sequence"], null);
        foreach ($root->decisions() as $decision) {
            $repo->index($decision);
        }
        self::assertNull($repo->current($e->id));
        self::assertSame(2, $repo->mapping($e->id)["last_sequence"]);
        self::assertSame(2, $repo->maximumSequence($section->id));
        self::assertSame($root->decisions(), $repo->decisions($e->id));
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        self::assertSame($finance, $f->financialEvents());
    }
}
