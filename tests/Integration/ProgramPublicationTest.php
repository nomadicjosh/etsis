<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/ProgramPublicationFixture.php";

use Domain\Catalog\Enum\ClassificationKind;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class ProgramPublicationTest extends TestCase
{
    private function publish(ProgramPublicationFixture $p, array $year): array
    {
        $review = $p->service->review(
            $p->sis->program,
            new EntityId($year["id"]),
            "Fictional program approval",
        );
        return $p->service->publish($p->sis->program, $review["review_token"]);
    }

    private function student(ProgramPublicationFixture $p, array $year): \Domain\Student\Student
    {
        $f = $p->sis;
        $person = $f->admissionsService()->createPerson($f->user(), "Fictional", "Student");
        $application = $f->legacyApplication($person->id, $f->program, new EntityId($year["terms"][0]["id"]));
        $f->legacyAdmissionDecision($application->id, true);
        return $f->matriculate($application->id);
    }

    private function rename(ProgramPublicationFixture $p, string $title): void
    {
        $draft = $p->service->draft($p->sis->program);
        $p->service->save(
            $p->sis->program,
            "BSCS",
            $title,
            "Description for " . $title,
            null,
            null,
            null,
            $draft["revision"],
        );
    }
    public function testPublicationIsReviewedAndCreatesAnImmutableAcademicYearRevision(): void
    {
        $p = new ProgramPublicationFixture();
        $f = $p->sis;
        $year = $p->calendar->year(2024);
        $department = $p->classifications->save(
            ClassificationKind::Department,
            null,
            "SCI",
            "Sciences",
            true,
            null,
        );
        $award = $p->classifications->save(
            ClassificationKind::Award,
            null,
            "BA",
            "Bachelor of Arts",
            true,
            null,
        );
        $before = $p->service->draft($f->program);
        $saved = $p->service->save(
            $f->program,
            "BA.SCI",
            "Science",
            "Fictional program description",
            new EntityId($department["id"]),
            null,
            new EntityId($award["id"]),
            $before["revision"],
        );
        $review = $p->service->review($f->program, new EntityId($year["id"]), "Fictional program approval");
        self::assertSame([], $f->sql->rows("sis_program_catalogs"));
        $f->clock->time = $f->clock->time->modify("+5 minutes");
        $receipt = $p->service->publish($f->program, $review["review_token"]);
        $catalog = $f->sql->one("sis_program_catalogs", ["id" => $receipt["operation_id"]]);
        self::assertSame($year["id"], $catalog["year_id"]);
        self::assertSame("Science", $catalog["program_title"]);
        self::assertSame(1, (int) $catalog["is_current"]);
        $page = $p->service->versions($f->program);
        self::assertEquals($saved["definition"], $page["items"][0]["definition"]);
        self::assertSame(
            $f->clock->now()->getTimestamp(),
            new DateTimeImmutable($page["items"][0]["effective_at"])->getTimestamp(),
        );
        $p->classifications->save(
            ClassificationKind::Department,
            new EntityId($department["id"]),
            "NEW",
            "Renamed Sciences",
            false,
            $department["revision"],
        );
        self::assertSame(
            "Sciences",
            $p->service->versions($f->program)["items"][0]["definition"]["classifications"]["department"][
                "title"
            ],
        );
        self::assertSame($receipt, $p->service->publish($f->program, $review["review_token"]));
    }

    public function testExpiredOrStaleReviewDoesNotCreateAnyCohortOrPublication(): void
    {
        $p = new ProgramPublicationFixture();
        $year = new EntityId($p->calendar->year(2024)["id"]);
        $review = $p->service->review($p->sis->program, $year, "Fictional expired review");
        $p->sis->clock->time = $p->sis->clock->time->modify("+21 minutes");
        try {
            $p->service->publish($p->sis->program, $review["review_token"]);
            self::fail("An expired program review was published.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $p->sis->sql->rows("sis_program_catalogs"));
            self::assertSame([], $p->sis->sql->rows("sis_program_catalog_details"));
        }
    }

    public function testStudentsKeepTheirPublishedAcademicYearAndNewAssignmentsUseTheSelectedRevision(): void
    {
        $p = new ProgramPublicationFixture();
        $year24 = $p->calendar->year(2024);
        $year25 = $p->calendar->year(2025);
        $this->rename($p, "Original Science");
        $first = $this->publish($p, $year24);
        $olderStudent = $this->student($p, $year24);
        $this->rename($p, "Revised Science");
        $second = $this->publish($p, $year25);
        $newerStudent = $this->student($p, $year25);
        $this->rename($p, "Unpublished future title");
        $laterOldCohort = $this->student($p, $year24);
        $records = new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($p->sis->sql);
        foreach ([$olderStudent, $laterOldCohort] as $student) {
            $rows = $records->assignments($student->id, null, 50);
            self::assertSame("Original Science", $rows[0]["program_title"]);
            self::assertSame($first["operation_id"], $rows[0]["catalog_id"]);
        }
        $rows = $records->assignments($newerStudent->id, null, 50);
        self::assertSame("Revised Science", $rows[0]["program_title"]);
        self::assertSame($second["operation_id"], $rows[0]["catalog_id"]);
        $before = $p->sis->sql->rows("sis_history_events");
        $source = new \Infrastructure\Reporting\SqlRelationalReportSource($p->sis->sql);
        $query = new \Domain\Reporting\ValueObject\ReportQuery(
            [
                "dataset" => "programs",
                "columns" => [
                    ["field" => "published_program_title", "label" => "Program", "aggregate" => "none"],
                ],
                "filters" => null,
                "having" => null,
                "sort" => [],
            ],
            $source->datasets()["programs"],
        );
        $titles = array_column($source->execute($query, 0), "c0");
        sort($titles);
        self::assertSame(["Original Science", "Original Science", "Revised Science"], $titles);
        self::assertSame(
            $before,
            $p->sis->sql->rows("sis_history_events"),
            "Reading assignments/reports must not write history.",
        );
    }

    public function testExactRetryAfterLaterPublicationsAndExpiryDoesNotRestoreAnEarlierSelection(): void
    {
        $p = new ProgramPublicationFixture();
        $year = $p->calendar->year(2024);
        $review = $p->service->review(
            $p->sis->program,
            new EntityId($year["id"]),
            "Fictional original program",
        );
        $first = $p->service->publish($p->sis->program, $review["review_token"]);
        $this->rename($p, "Later title");
        $later = $this->publish($p, $year);
        $before = $p->sis->sql->rows("sis_history_events");
        $p->sis->clock->time = $p->sis->clock->time->modify("+30 minutes");
        self::assertSame($first, $p->service->publish($p->sis->program, $review["review_token"]));
        self::assertSame($before, $p->sis->sql->rows("sis_history_events"));
        $current = $p->sis->sql->rows("sis_program_catalogs", ["is_current" => 1]);
        self::assertSame($later["operation_id"], $current[0]["id"]);
        self::assertSame("Later title", $p->service->draft($p->sis->program)["definition"]["title"]);
    }

    public function testChangedDraftAndCompetingReviewCannotPublishUnreviewedDetails(): void
    {
        $p = new ProgramPublicationFixture();
        $year = new EntityId($p->calendar->year(2024)["id"]);
        $review = $p->service->review($p->sis->program, $year, "Fictional pending review");
        $this->rename($p, "Changed after review");
        try {
            $p->service->publish($p->sis->program, $review["review_token"]);
            self::fail("A stale program definition was published.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $p->sis->sql->rows("sis_program_catalogs"));
        }
        $first = $p->service->review($p->sis->program, $year, "Fictional first review");
        $second = $p->service->review($p->sis->program, $year, "Fictional competing review");
        $p->service->publish($p->sis->program, $first["review_token"]);
        $this->expectException(ConcurrentModificationException::class);
        $p->service->publish($p->sis->program, $second["review_token"]);
    }

    public function testReferenceRenamesInvalidateReviewAndWrongCategoriesCannotBeAssigned(): void
    {
        $p = new ProgramPublicationFixture();
        $year = new EntityId($p->calendar->year(2024)["id"]);
        $award = $p->classifications->save(
            ClassificationKind::Award,
            null,
            "BA",
            "Bachelor of Arts",
            true,
            null,
        );
        $draft = $p->service->draft($p->sis->program);
        $p->service->save(
            $p->sis->program,
            "BSCS",
            "Science",
            null,
            null,
            null,
            new EntityId($award["id"]),
            $draft["revision"],
        );
        $review = $p->service->review($p->sis->program, $year, "Fictional award review");
        $p->classifications->save(
            ClassificationKind::Award,
            new EntityId($award["id"]),
            "BA",
            "Arts degree",
            true,
            $award["revision"],
        );
        try {
            $p->service->publish($p->sis->program, $review["review_token"]);
            self::fail("A changed award was accepted without review.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $p->sis->sql->rows("sis_program_catalog_details"));
        }
        $this->expectException(\Domain\Shared\Exception\ValidationException::class);
        $p->service->save(
            $p->sis->program,
            "BSCS",
            "Science",
            null,
            new EntityId($award["id"]),
            null,
            null,
            $p->service->draft($p->sis->program)["revision"],
        );
    }

    public function testAuditFailureRollsBackThePublicationAndCurrentCurriculumPointer(): void
    {
        $p = new ProgramPublicationFixture();
        $year = $p->calendar->year(2024);
        $first = $this->publish($p, $year);
        $this->rename($p, "New Science");
        $review = $p->service->review(
            $p->sis->program,
            new EntityId($year["id"]),
            "Fictional failed publication",
        );
        $before = $p->sis->sql->rows("sis_history_events");
        $p->sis->connection->pdo->exec(
            "CREATE TRIGGER program_audit_fail BEFORE INSERT ON sis_audit BEGIN SELECT RAISE(ABORT, 'fictional failure'); END",
        );
        try {
            $p->service->publish($p->sis->program, $review["review_token"]);
            self::fail("Publication survived audit failure.");
        } catch (PDOException) {
            self::assertSame($before, $p->sis->sql->rows("sis_history_events"));
            self::assertCount(1, $p->sis->sql->rows("sis_program_catalog_details"));
            self::assertSame(
                $first["operation_id"],
                $p->sis->sql->rows("sis_program_catalogs", ["is_current" => 1])[0]["id"],
            );
        }
    }

    public function testMissingOrConflictingRetainedDefinitionsCannotBecomeUnrecordedCohorts(): void
    {
        $p = new ProgramPublicationFixture();
        $year = $p->calendar->year(2024);
        $receipt = $this->publish($p, $year);
        $p->sis->sql->execute("DELETE FROM sis_program_catalog_details WHERE id = ?", [
            $receipt["operation_id"],
        ]);
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $p->sis->programEntryContext()->proposal($p->sis->program, new EntityId($year["terms"][0]["id"]));
    }

    public function testPermissionAndActorAreCheckedAgainAfterWaitingForTheTransaction(): void
    {
        $p = new ProgramPublicationFixture();
        $year = new EntityId($p->calendar->year(2024)["id"]);
        $review = $p->service->review($p->sis->program, $year, "Fictional permission review");
        $transactions = $this->createStub(\Domain\Shared\Persistence\UnitOfWork::class);
        $transactions->method("run")->willReturnCallback(function (callable $work) use ($p) {
            return $p->sis->transactions->run(function () use ($work, $p) {
                $p->sis->access->staff = false;
                return $work();
            });
        });
        try {
            $p->publisher($transactions)->publish($p->sis->program, $review["review_token"]);
            self::fail("Revoked authority was accepted after waiting.");
        } catch (\Domain\Shared\Exception\AccessDeniedException) {
            self::assertSame([], $p->sis->sql->rows("sis_program_catalogs"));
        }
    }

    public function testHistoryPagesFreezeTheirBoundaryAndFrameworkReplayUsesTheSameJournal(): void
    {
        $p = new ProgramPublicationFixture();
        $year = $p->calendar->year(2024);
        for ($revision = 1; $revision <= 21; $revision++) {
            $this->publish($p, $year);
        }
        $first = $p->service->versions($p->sis->program);
        self::assertCount(20, $first["items"]);
        $this->publish($p, $year);
        $last = $p->service->versions($p->sis->program, $first["next_cursor"]);
        self::assertCount(1, $last["items"]);
        self::assertSame(21, $last["through_version"]);
        $f = $p->sis;
        $journal = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(
                EventStoreFixture::config($f->connection->queryBuilder()),
            ),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
        $id = new \Domain\Catalog\ValueObject\ProgramPublicationId($f->program->value);
        $store = new \Infrastructure\Persistence\PdoTransactionalEventStore(
            $journal,
            $f->transactions,
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
        $root = \Domain\Catalog\ProgramPublication::reconstituteFromEventStream(
            $store->getAggregateHistoryFor($id),
        );
        self::assertSame(22, $root->playhead());
        self::assertNotNull($root->versionId());
    }
}
