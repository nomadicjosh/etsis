<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Service\AdmissionsService;
use Domain\Audit\Repository\AuditTrail;
use Domain\Gradebook\GradeDefinition;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Repository\TransferCatalogRepository;
use Domain\Transfer\Service\TransferCatalogService;
use Domain\Transfer\Service\TransferService;
use Domain\Transfer\TransferEquivalency;
use Domain\Workspace\Service\WorkspaceService;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\Repository\SqlGradebookRepository;
use Infrastructure\Persistence\Repository\SqlTransferCatalogRepository;
use Infrastructure\Persistence\Repository\SqlTransferRepository;
use Infrastructure\Persistence\Repository\SqlWorkspaceRepository;
use PHPUnit\Framework\TestCase;

final class TransferCatalogTest extends TestCase
{
    private SisFixture $f;
    private TransferService $transfer;
    private TransferCatalogService $catalog;
    private SqlTransferCatalogRepository $repository;
    private EntityId $institution;
    private EntityId $external;
    private TransferEquivalency $equivalency;
    private \Domain\Student\Student $student;

    protected function setUp(): void
    {
        $this->f = $f = new SisFixture(
            new TransferReadConnection(["driver" => "pdo_sqlite", "dsn" => "sqlite::memory:"]),
        );
        $this->student = $f->student();
        $course = $f->section();
        $grades = new SqlGradebookRepository($f->sql);
        $f->legacyGrade(new GradeDefinition(EntityId::generate(), "TR", 0, true, false));
        $f->legacyGrade(new GradeDefinition(EntityId::generate(), "B", 3000, true, true));
        $f->legacyGrade(new GradeDefinition(EntityId::generate(), "F", 0, false, true));
        $this->repository = new SqlTransferCatalogRepository($f->sql, $f->gradeCatalog());
        $this->transfer = $this->service($this->repository);
        $this->catalog = new TransferCatalogService(
            $this->repository,
            $f->access,
            $f->transactions,
            $f->audit,
        );
        $this->institution = $this->transfer->addInstitution("EXT.1", "Fictional College");
        $this->external = $this->transfer->addExternalCourse(
            $this->institution,
            "MATH.101",
            "External algebra",
        );
        $this->equivalency = new TransferEquivalency(
            EntityId::generate(),
            $this->institution,
            $this->external,
            $course->courseId,
            new DateRange(new DateTimeImmutable("2020-01-01")),
            2,
            3000,
            "tr",
        );
        $this->transfer->equate($this->equivalency);
    }

    private function service(
        TransferCatalogRepository $repository,
        ?AuditTrail $audit = null,
    ): TransferService {
        $f = $this->f;
        return new TransferService(
            new SqlTransferRepository($f->sql, $f->gradeCatalog()),
            $f->registration,
            $f->admissions,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $repository,
            $f->transferCredits(),
        );
    }

    private function reviewed(): array
    {
        return $this->catalog->view("equivalencies", $this->equivalency->id);
    }

    private function award(?string $revision = null, string $on = "2026-05-01", int $rank = 2): EntityId
    {
        return $this->transfer->award(
            $this->student->id,
            $this->equivalency->id,
            $this->institution,
            $this->f->term,
            new DateTimeImmutable($on),
            $rank,
            $revision ?? $this->reviewed()["revision"],
        );
    }

    public function testCatalogChangesPreserveExistingAwardSnapshotsAndApplyToFutureAwards(): void
    {
        $reviewed = $this->reviewed();
        $credit = $this->award($reviewed["revision"]);
        self::assertSame($credit->value, $this->award($reviewed["revision"])->value);
        $snapshot = $this->f->sql->one("sis_academic_credits", ["id" => $credit->value]);
        $this->catalog->editEquivalency(
            $this->equivalency->id,
            new DateRange(new DateTimeImmutable("2020-01-01"), new DateTimeImmutable("2027-01-01")),
            3,
            3125,
            "b",
            $reviewed["revision"],
        );
        $next = $this->reviewed();
        self::assertSame(3125, $next["credits_milli"]);
        self::assertSame("B", $next["awarded_grade"]);
        self::assertSame($reviewed["external_course_id"], $next["external_course_id"]);
        self::assertSame($reviewed["course_id"], $next["course_id"]);
        $newCredit = $this->award($next["revision"], "2026-05-02", 3);
        $new = $this->f->sql->one("sis_academic_credits", ["id" => $newCredit->value]);
        self::assertSame(3125, $new["completed_milli"]);
        self::assertSame(9375, $new["grade_points_milli"]);
        self::assertSame($snapshot, $this->f->sql->one("sis_academic_credits", ["id" => $credit->value]));
        self::assertCount(2, $this->catalog->awards($this->equivalency->id)["items"]);
        self::assertSame("B", $this->transfer->transcript($this->student->id)[1]["grade_code"]);
        $this->expectException(ConcurrentModificationException::class);
        $this->award($reviewed["revision"], "2026-05-03", 3);
    }

    public function testChangesToEveryReviewedCatalogContextRequireFreshReview(): void
    {
        foreach (["institution", "external", "course", "equivalency"] as $context) {
            $reviewed = $this->reviewed();
            if ($context === "institution" || $context === "external") {
                $kind = $context === "institution" ? "institutions" : "external";
                $id = $context === "institution" ? $this->institution : $this->external;
                $record = $this->catalog->view($kind, $id);
                $this->catalog->editLabel(
                    $kind,
                    $id,
                    $record["code"],
                    "Updated " . $context,
                    $record["revision"],
                );
            } elseif ($context === "course") {
                $this->f->sql->update(
                    "sis_courses",
                    ["title" => "Revised institutional title"],
                    ["id" => $this->equivalency->institutionalCourseId->value],
                );
            } else {
                $this->catalog->editEquivalency(
                    $this->equivalency->id,
                    new DateRange(new DateTimeImmutable("2020-01-01")),
                    2,
                    3125,
                    "TR",
                    $reviewed["revision"],
                );
            }
            try {
                $this->award($reviewed["revision"]);
                self::fail("A stale review was accepted after changing " . $context);
            } catch (ConcurrentModificationException) {
                self::assertSame([], $this->f->sql->rows("sis_transfer_awards"));
            }
        }
        self::assertNotEmpty($this->award()->value);
    }

    public function testFreshReadsFollowAllCatalogLocksWhenAnotherWriterFinishes(): void
    {
        $f = $this->f;
        $delegate = $this->repository;
        $reviewed = $this->reviewed();
        $locks = [];
        $repository = $this->createStub(TransferCatalogRepository::class);
        $repository->method("get")->willReturnCallback($delegate->get(...));
        $repository
            ->method("lock")
            ->willReturnCallback(static function (
                string $kind,
                EntityId $id,
            ) use (
                &$locks,
                $f,
                $delegate
): void {
                $locks[] = $kind;
                if ($kind === "courses") {
                    // Simulate a preceding catalog writer finishing just before we acquire the last lock.
                    $f->sql->update(
                        "sis_courses",
                        ["title" => "A preceding writer changed this"],
                        ["id" => $id->value],
                    );
                }
                $delegate->lock($kind, $id);
            });
        try {
            $this->service($repository)->award(
                $this->student->id,
                $this->equivalency->id,
                $this->institution,
                $f->term,
                new DateTimeImmutable("2026-05-01"),
                2,
                $reviewed["revision"],
            );
            self::fail("An award used context read before the catalog locks.");
        } catch (ConcurrentModificationException) {
            self::assertSame(["institutions", "external", "equivalencies", "courses"], $locks);
            self::assertSame([], $f->sql->rows("sis_academic_credits"));
        }
    }

    public function testStaleLabelEditsDuplicateCodesAndFailingGradesLeaveCatalogUnchanged(): void
    {
        $record = $this->catalog->view("institutions", $this->institution);
        $this->catalog->editLabel(
            "institutions",
            $this->institution,
            "EXT.2",
            "Updated college",
            $record["revision"],
        );
        try {
            $this->catalog->editLabel(
                "institutions",
                $this->institution,
                "EXT.3",
                "Lost update",
                $record["revision"],
            );
            self::fail("A stale label edit was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame("EXT.2", $this->catalog->view("institutions", $this->institution)["code"]);
        }
        try {
            $this->transfer->addInstitution("EXT.2", "Duplicate");
            self::fail("Duplicate institution accepted.");
        } catch (ValidationException) {
            self::assertCount(1, $this->f->sql->rows("sis_external_institutions"));
        }
        $before = $this->reviewed();
        try {
            $this->catalog->editEquivalency(
                $this->equivalency->id,
                new DateRange(new DateTimeImmutable("2020-01-01")),
                2,
                3000,
                "F",
                $before["revision"],
            );
            self::fail("A failing transfer grade was accepted.");
        } catch (DomainException) {
            self::assertSame($before, $this->reviewed());
        }
    }

    public function testEligibilityBoundariesAndWrongInstitutionCannotPostCredit(): void
    {
        $before = $this->reviewed();
        $this->catalog->editEquivalency(
            $this->equivalency->id,
            new DateRange(new DateTimeImmutable("2026-01-01"), new DateTimeImmutable("2026-05-01")),
            2,
            3000,
            "TR",
            $before["revision"],
        );
        $cases1 = [["2025-12-31", 2], ["2026-05-02", 2], ["2026-05-01", 1], ["2026-05-01", 10001]];
        foreach ($cases1 as [$on, $rank]) {
            try {
                $this->award(on: $on, rank: $rank);
                self::fail("An ineligible transfer award was posted.");
            } catch (DomainException) {
                self::assertSame([], $this->f->sql->rows("sis_transfer_awards"));
            }
        }
        try {
            $this->transfer->award(
                $this->student->id,
                $this->equivalency->id,
                EntityId::generate(),
                $this->f->term,
                new DateTimeImmutable("2026-05-01"),
                2,
                $this->reviewed()["revision"],
            );
            self::fail("Wrong sending institution accepted.");
        } catch (DomainException) {
            self::assertSame([], $this->f->sql->rows("sis_transfer_awards"));
        }
        self::assertNotEmpty($this->award(on: "2026-01-01")->value);
        self::assertNotEmpty($this->award(on: "2026-05-01")->value);
    }

    public function testAuditFailuresRollBackCatalogAndAwardWrites(): void
    {
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willThrowException(new RuntimeException("Fictional audit failure"));
        $catalog = new TransferCatalogService(
            $this->repository,
            $this->f->access,
            $this->f->transactions,
            $audit,
        );
        $record = $this->catalog->view("external", $this->external);
        try {
            $catalog->editLabel("external", $this->external, "NEW.101", "Changed", $record["revision"]);
            self::fail("An unaudited catalog write committed.");
        } catch (RuntimeException) {
            self::assertSame($record, $this->catalog->view("external", $this->external));
        }
        try {
            $this->service($this->repository, $audit)->award(
                $this->student->id,
                $this->equivalency->id,
                $this->institution,
                $this->f->term,
                new DateTimeImmutable("2026-05-01"),
                2,
                $this->reviewed()["revision"],
            );
            self::fail("An unaudited award committed.");
        } catch (RuntimeException) {
            self::assertSame([], $this->f->sql->rows("sis_transfer_awards"));
            self::assertSame([], $this->f->sql->rows("sis_academic_credits"));
        }
    }

    public function testTransferOnlyGrantAllowsRequiredLookupsButNotFullRecordsOrOtherRegistrarDuties(): void
    {
        $f = $this->f;
        $f->access->actor = $f->user();
        $f->access->staff = false;
        $f->access->grants = ["admin:dashboard", "sis:transfer:manage"];
        $permissions = $this->createStub(AccessRepository::class);
        $permissions
            ->method("allows")
            ->willReturnCallback(
                static fn(string $user, string $permission): bool => in_array(
                    $permission,
                    $f->access->grants,
                    true,
                ),
            );
        $workspace = new WorkspaceService(
            new SqlWorkspaceRepository($f->sql, $f->gradeCatalog()),
            $f->access,
            $permissions,
            new AdmissionsService(
                $f->admissions,
                $f->access,
                $f->transactions,
                $f->audit,
                new RandomInstitutionalIdGenerator(),
                $f->catalogEnrollment(),
            ),
        );
        self::assertCount(1, $workspace->records("courses")["items"]);
        $recipient = $workspace->records("transferstudents")["items"][0];
        self::assertSame($this->student->id->value, $recipient["id"]);
        self::assertArrayNotHasKey("primary_program_id", $recipient);
        self::assertArrayNotHasKey("entry_term_id", $recipient);
        self::assertNotEmpty($this->award()->value);
        self::assertCount(1, $this->catalog->awards($this->equivalency->id)["items"]);
        foreach (["students", "transcripts", "sections", "people", "accounts", "employees"] as $dataset) {
            try {
                $workspace->records($dataset);
                self::fail("A transfer grant exposed " . $dataset);
            } catch (AccessDeniedException) {
                self::assertNotContains(WorkspaceService::DATASETS[$dataset], $workspace->capabilities());
            }
        }
        try {
            $this->transfer->transcript($this->student->id);
            self::fail("Transfer management exposed another student’s full transcript.");
        } catch (AccessDeniedException) {
            self::assertCount(1, $this->catalog->awards($this->equivalency->id)["items"]);
        }
        $f->access->grants[] = "sis:student:read";
        self::assertCount(1, $this->transfer->transcript($this->student->id));
        self::assertCount(1, $workspace->records("students")["items"]);
        self::assertNotContains("sis:catalog:manage", $workspace->capabilities());
        self::assertNotContains("sis:transcript:manage", $workspace->capabilities());
    }

    public function testGenericStaffAndStudentOwnersCannotManageTransferRecords(): void
    {
        $this->f->access->staff = false;
        $this->f->access->grants = ["admin:dashboard", "sis:student:read"];
        foreach (["view", "award", "edit", "awards", "children"] as $action) {
            try {
                match ($action) {
                    "view" => $this->reviewed(),
                    "award" => $this->award(str_repeat("0", 64)),
                    "edit" => $this->catalog->editLabel("external", $this->external, "NEW", "Denied", ""),
                    "awards" => $this->catalog->awards($this->equivalency->id),
                    "children" => $this->catalog->children("institutions", $this->institution),
                };
                self::fail("A non-transfer user performed " . $action);
            } catch (AccessDeniedException) {
                self::assertSame([], $this->f->sql->rows("sis_transfer_awards"));
            }
        }
    }

    public function testTransferPagesUseIndexesAndTranscriptReadsOneJoinedStatement(): void
    {
        $this->award();
        $pdo = $this->f->connection->pdo;
        self::assertInstanceOf(TransferReadPdo::class, $pdo);
        $queries = [
            [
                fn() => $this->repository->children("institutions", $this->institution, null, 51),
                $this->institution->value,
                "sis_external_page",
            ],
            [
                fn() => $this->repository->children("external", $this->external, null, 51),
                $this->external->value,
                "sis_equivalency_page",
            ],
            [
                fn() => $this->repository->awards($this->equivalency->id, null, 51),
                $this->equivalency->id->value,
                "sis_transfer_award_page",
            ],
            [
                fn() => new SqlTransferRepository($this->f->sql, $this->f->gradeCatalog())->transcript(
                    $this->student->id,
                ),
                $this->student->id->value,
                "sis_student_credit_read",
            ],
        ];
        foreach ($queries as [$read, $id, $index]) {
            $pdo->prepares = 0;
            self::assertCount(1, $read());
            self::assertSame(1, $pdo->prepares);
            $plan = $pdo->prepare("EXPLAIN QUERY PLAN " . $pdo->lastQuery);
            $plan->execute([$id]);
            self::assertStringContainsString(
                $index,
                implode(" ", array_column($plan->fetchAll(PDO::FETCH_ASSOC), "detail")),
            );
        }
    }

    public function testRelatedCatalogListsHaveStableBoundedPages(): void
    {
        for ($i = 0; $i < 53; ++$i) {
            $this->transfer->addExternalCourse($this->institution, "PAGE." . $i, "Fictional course");
        }
        $other = $this->transfer->addInstitution("OTHER", "Other college");
        $this->transfer->addExternalCourse($other, "OTHER.1", "Excluded course");
        $first = $this->catalog->children("institutions", $this->institution);
        $second = $this->catalog->children(
            "institutions",
            $this->institution,
            new EntityId($first["next_after"]),
        );
        self::assertCount(50, $first["items"]);
        self::assertCount(4, $second["items"]);
        self::assertNull($second["next_after"]);
        self::assertCount(54, array_unique(array_column([...$first["items"], ...$second["items"]], "id")));
        foreach ([...$first["items"], ...$second["items"]] as $row) {
            self::assertSame($this->institution->value, $row["institution_id"]);
        }
    }
}

/** Only statement structure is collected; fictional fixture bindings are never captured. */
final class TransferReadPdo extends PDO
{
    public int $prepares = 0;
    public string $lastQuery = "";

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        ++$this->prepares;
        $this->lastQuery = $query;
        return parent::prepare($query, $options);
    }
}

final class TransferReadConnection extends \Qubus\Expressive\Connection\Pdo\Sqlite
{
    protected function createPdo(array $config): PDO
    {
        $pdo = new TransferReadPdo("sqlite::memory:");
        $pdo->exec("PRAGMA foreign_keys = ON");
        return $pdo;
    }
}
