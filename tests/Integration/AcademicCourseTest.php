<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Domain\AcademicRecord\AcademicCredit;
use Domain\AcademicRecord\ValueObject\AcademicCreditId;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAcademicCreditRepository;
use PHPUnit\Framework\TestCase;

final class AcademicCourseTest extends TestCase
{
    private function fixture(bool $legacy = false): array
    {
        $f = new SisFixture();
        $f->gradeScale([
            [
                "code" => "A",
                "min_score_milli" => 0,
                "max_score_milli" => 100000,
                "points_milli" => 4000,
                "passes" => true,
                "counts_gpa" => true,
            ],
        ]);
        $student = $f->student()->id;
        $section = $f->section(faculty: $f->access->actor);
        $version = $f->publishCourse($section->courseId);
        if ($legacy) {
            $enrollment = EntityId::generate();
            $credit = EntityId::generate();
            $f->sql->insert("sis_enrollments", [
                "id" => $enrollment->value,
                "student_id" => $student->value,
                "section_id" => $section->id->value,
                "term_id" => $f->term->value,
                "status" => "A",
                "registered_at" => "2026-08-01T17:00:00Z",
                "status_at" => "2026-08-01T17:00:00Z",
                "version" => 0,
            ]);
            $f->sql->insert("sis_academic_credits", [
                "id" => $credit->value,
                "student_id" => $student->value,
                "source_id" => $enrollment->value,
                "course_id" => $section->courseId->value,
                "term_id" => $f->term->value,
                "credit_type" => "institutional",
                "course_code" => "OLD.101",
                "course_title" => "Observed older title",
                "attempted_milli" => 3000,
                "completed_milli" => 0,
                "grade_points_milli" => 0,
                "grade_id" => null,
                "status" => "A",
                "version" => 0,
            ]);
        } else {
            $enrollment = $f->service->register($student, $section->id)->id;
            $credit = new EntityId($f->sql->one("sis_academic_credits", ["source_id" => $enrollment->value])["id"]);
        }
        return [$f, $student, $section, $enrollment, $credit, $version];
    }
    private function history(SisFixture $f): \Infrastructure\EventSourcing\SqlHistoricalEventStore
    {
        return new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(
                EventStoreFixture::config($f->connection->queryBuilder()),
            ),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
    }
    private function replay(SisFixture $f, EntityId $credit): AcademicCredit
    {
        $root = AcademicCredit::root(new AcademicCreditId($credit->value));
        $cursor = null;
        do {
            $page = $this->history($f)->page(
                new StreamReference(new AcademicCreditId($credit->value), "academic_credit"),
                $cursor,
                20,
            );
            foreach ($page->events as $e) {
                $root->acceptHistorical($e->event);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        return $root;
    }
    private function snapshot(SisFixture $f): array
    {
        $out = [];
        foreach (
            [
                "sis_history_events",
                "sis_history_operations",
                "sis_history_streams",
                "sis_academic_credits",
                "sis_credit_course_versions",
                "sis_academic_credit_streams",
                "sis_audit",
            ]
            as $table
        ) {
            $out[$table] = $f->sql->rows($table);
        }
        return $out;
    }
    public function testReviewedAcceptancePreservesOriginalFactsGradesFinanceAndFutureSectionBinding(): void
    {
        [$f, $student, $section, $enrollment, $credit, $version] = $this->fixture();
        $ledger = $f->academicCredits();
        $grade = $ledger->choices($student, $section->id, $enrollment)["items"][0]["id"];
        $r = $ledger->review($student, $section->id, $enrollment, new EntityId($grade), 0);
        $ledger->commit($student, $section->id, $r["review_token"]);
        $completion = $f
            ->academicCompletions()
            ->review($student, $section->id, $enrollment, "2026-08-02", 1, "Fictional completion.");
        $f->academicCompletions()->commit($student, $section->id, $completion["review_token"]);
        $before = new SqlAcademicCreditRepository($f->sql)->projection($credit);
        $events = $f->sql->rows("sis_history_events");
        $finance = $f->financialEvents();
        $service = $f->academicCourses();
        $choices = $service->choices($student, $section->id, $enrollment);
        self::assertSame($version->value, $choices["items"][0]["version_id"]);
        $review = $service->review(
            $student,
            $section->id,
            $enrollment,
            $version,
            2,
            "2026-08-10",
            "Fictional private acceptance evidence.",
        );
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        $receipt = $service->commit($student, $section->id, $review["review_token"]);
        $root = $this->replay($f, $credit);
        self::assertSame($version->value, $root->credit()["course_version_id"]);
        self::assertSame("2026-08-02", $root->completedOn());
        self::assertSame(3000, $root->credit()["completed_milli"]);
        self::assertSame(12000, $root->credit()["grade_points_milli"]);
        self::assertSame(3, $root->credit()["version"]);
        self::assertSame($before["grade_id"], $root->credit()["grade_id"]);
        self::assertSame($finance, $f->financialEvents());
        self::assertSame($events, array_slice($f->sql->rows("sis_history_events"), 0, count($events)));
        self::assertEquals($root->credit(), new SqlAcademicCreditRepository($f->sql)->projection($credit));
        self::assertSame($receipt, $service->commit($student, $section->id, $review["review_token"]));
        $next = $f->student()->id;
        $nextEnrollment = $f->service->register($next, $section->id)->id;
        self::assertNull(
            $f->sql->rows(
                "sis_credit_course_versions",
                ["id" => $f->sql->one("sis_academic_credits", ["source_id" => $nextEnrollment->value])["id"]],
                1,
            )[0] ?? null,
        );
        $page = $ledger->history($student, $section->id, $enrollment);
        self::assertSame("course_accepted", end($page["items"])["action"]);
        self::assertStringNotContainsString(
            "Fictional private acceptance evidence.",
            json_encode($page, JSON_THROW_ON_ERROR),
        );
    }
    public function testFirstLegacyDecisionObservesCurrentFactsWithoutInventingEarlierCourseEvidence(): void
    {
        [$f, $student, $section, $enrollment, $credit, $version] = $this->fixture(true);
        $service = $f->academicCourses();
        $before = $this->snapshot($f);
        $r = $service->review(
            $student,
            $section->id,
            $enrollment,
            $version,
            0,
            "2026-08-09",
            "Fictional records review.",
        );
        self::assertSame($before, $this->snapshot($f));
        $receipt = $service->commit($student, $section->id, $r["review_token"]);
        $root = $this->replay($f, $credit);
        self::assertSame("observed_existing", $root->provenance());
        self::assertSame(2, $root->playhead());
        self::assertSame(1, $root->credit()["version"]);
        $page = $this->history($f)->page(new StreamReference(new AcademicCreditId($credit->value), "academic_credit"));
        self::assertNull($page->events[0]->event->payload()["credit"]["course_version_id"]);
        self::assertSame("OLD.101", $page->events[0]->event->payload()["credit"]["course_code"]);
        self::assertSame(
            $f->clock->now()->getTimestamp(),
            new DateTimeImmutable($page->events[0]->context->describe()["effective_at"])->getTimestamp(),
        );
        self::assertSame($receipt, $service->commit($student, $section->id, $r["review_token"]));
    }
    public function testAcceptedRetriesDoNotUndoLaterSelectionsAndSurviveEarlyRemoval(): void
    {
        [$f, $student, $section, $enrollment, $credit, $version] = $this->fixture();
        $service = $f->academicCourses();
        $r = $service->review(
            $student,
            $section->id,
            $enrollment,
            $version,
            0,
            "2026-08-10",
            "Fictional initial acceptance.",
        );
        $first = $service->commit($student, $section->id, $r["review_token"]);
        $other = $f->publishCourse($section->courseId);
        $next = $service->review(
            $student,
            $section->id,
            $enrollment,
            $other,
            1,
            "2026-08-10",
            "Fictional revised acceptance.",
        );
        $service->commit($student, $section->id, $next["review_token"]);
        self::assertSame($first, $service->commit($student, $section->id, $r["review_token"]));
        self::assertSame($other->value, $this->replay($f, $credit)->credit()["course_version_id"]);
        $f->drops->drop($student, $section->id);
        $before = $this->snapshot($f);
        self::assertSame($first, $service->commit($student, $section->id, $r["review_token"]));
        self::assertNull(new SqlAcademicCreditRepository($f->sql)->projection($credit));
        self::assertSame($before, $this->snapshot($f));
    }
    public function testStaleExpiredForeignAndUnauthorizedReviewsWriteNothing(): void
    {
        [$f, $student, $section, $enrollment, , $version] = $this->fixture();
        $service = $f->academicCourses();
        $r = $service->review($student, $section->id, $enrollment, $version, 0, "2026-08-10", "Fictional evidence.");
        $f->clock->time = $f->clock->time->modify("+21 minutes");
        $before = $this->snapshot($f);
        try {
            $service->commit($student, $section->id, $r["review_token"]);
            self::fail("Expired review accepted.");
        } catch (\Domain\Shared\Exception\ConcurrentModificationException) {
            self::assertSame($before, $this->snapshot($f));
        }
        $f->access->staff = false;
        foreach ([[], ["sis:gradebook:amend"], ["sis:student:read"]] as $grants) {
            $f->access->grants = $grants;
            try {
                $service->choices($student, $section->id, $enrollment);
                self::fail("Insufficient authority accepted.");
            } catch (\Domain\Shared\Exception\AccessDeniedException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
        $f->access->staff = true;
        $f->access->actor = EntityId::generate();
        try {
            $service->commit($student, $section->id, $r["review_token"]);
            self::fail("Foreign actor token accepted.");
        } catch (\Domain\Shared\Exception\AccessDeniedException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }
    public function testCrossCourseAndKnownInceptionReferencesCannotBeReplaced(): void
    {
        [$f, $student, $section, $enrollment, $credit, $version] = $this->fixture();
        $foreign = $f->publishCourse($f->section()->courseId);
        $before = $this->snapshot($f);
        try {
            $f->academicCourses()->review(
                $student,
                $section->id,
                $enrollment,
                $foreign,
                0,
                "2026-08-10",
                "Fictional invalid cross-course choice.",
            );
            self::fail("Cross-course publication accepted.");
        } catch (\Domain\Shared\Exception\RecordNotFoundException) {
            self::assertSame($before, $this->snapshot($f));
        }
        $facts = $this->replay($f, $credit)->credit();
        $facts["course_version_id"] = $version->value;
        $openingId = EntityId::generate();
        $root = AcademicCredit::root(new AcademicCreditId($credit->value));
        $root->acceptHistorical(
            \Domain\AcademicRecord\Event\InstitutionalCreditWasEstablished::occur(
                new AcademicCreditId($credit->value),
                [
                    "decision_id" => $openingId->value,
                    "provenance" => "registration",
                    "credit" => $facts,
                    "section_id" => $section->id->value,
                    "policies" => [],
                    "grade" => null,
                ],
                ["__aggregate_type" => "academic_credit"],
            )->withPlayhead(1),
        );
        self::assertFalse($root->canAcceptCourse());
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $root->acceptHistorical(
            \Domain\AcademicRecord\Event\InstitutionalCourseWasAccepted::occur(
                new AcademicCreditId($credit->value),
                [
                    "decision_id" => EntityId::generate()->value,
                    "previous_decision_id" => $openingId->value,
                    "previous_course_version_id" => $version->value,
                    "course_version_id" => EntityId::generate()->value,
                    "course_code" => "CS.101",
                    "course_title" => "Invalid replacement",
                ],
                ["__aggregate_type" => "academic_credit"],
            )->withPlayhead(2),
        );
    }
    public function testAuditFailureRollsBackObservationReceiptReferenceAndAcademicProjection(): void
    {
        [$f, $student, $section, $enrollment, , $version] = $this->fixture(true);
        $audit = new class implements \Domain\Audit\Repository\AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                throw new \RuntimeException("Fictional audit failure.");
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        $service = $f->academicCourses($audit);
        $r = $service->review(
            $student,
            $section->id,
            $enrollment,
            $version,
            0,
            "2026-08-10",
            "Fictional private evidence.",
        );
        $before = $this->snapshot($f);
        try {
            $service->commit($student, $section->id, $r["review_token"]);
            self::fail("Failed audit committed.");
        } catch (\RuntimeException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }
    public function testChangedGradeOrDamagedProjectionCannotBeOverwrittenByAnAcceptanceReview(): void
    {
        [$f, $student, $section, $enrollment, $credit, $version] = $this->fixture();
        $service = $f->academicCourses();
        $r = $service->review($student, $section->id, $enrollment, $version, 0, "2026-08-10", "Fictional evidence.");
        $ledger = $f->academicCredits();
        $grade = $ledger->choices($student, $section->id, $enrollment)["items"][0]["id"];
        $g = $ledger->review($student, $section->id, $enrollment, new EntityId($grade), 0);
        $ledger->commit($student, $section->id, $g["review_token"]);
        $before = $this->snapshot($f);
        try {
            $service->commit($student, $section->id, $r["review_token"]);
            self::fail("A stale acceptance changed a newer grade record.");
        } catch (\Domain\Shared\Exception\ConcurrentModificationException) {
            self::assertSame($before, $this->snapshot($f));
        }
        $f->sql->update(
            "sis_academic_credits",
            ["course_title" => "Fictional damaged projection"],
            ["id" => $credit->value],
        );
        $before = $this->snapshot($f);
        try {
            $service->choices($student, $section->id, $enrollment);
            self::fail("Damaged projection offered course choices.");
        } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }
    public function testReasonDateAndTokenScopeAreValidatedWithoutWrites(): void
    {
        [$f, $student, $section, $enrollment, , $version] = $this->fixture();
        $service = $f->academicCourses();
        $before = $this->snapshot($f);
        foreach ([["2026-08-11", "Evidence"], ["2026-02-30", "Evidence"], ["2026-08-10", ""]] as [$date, $reason]) {
            try {
                $service->review($student, $section->id, $enrollment, $version, 0, $date, $reason);
                self::fail("Invalid acceptance evidence was reviewed.");
            } catch (\Domain\Shared\Exception\ValidationException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
        $r = $service->review($student, $section->id, $enrollment, $version, 0, "2026-08-10", "Fictional evidence.");
        try {
            $service->commit(EntityId::generate(), $section->id, $r["review_token"]);
            self::fail("A cross-student token was used.");
        } catch (\Domain\Shared\Exception\ValidationException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }
}
