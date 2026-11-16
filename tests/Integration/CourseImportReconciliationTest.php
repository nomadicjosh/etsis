<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Course;
use Domain\Import\Repository\CatalogImportRepository;
use Domain\Import\ValueObject\CourseImportRow;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Restriction;
use Infrastructure\Persistence\Repository\SqlCatalogImportRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SisFixture;

final class CourseImportReconciliationTest extends TestCase
{
    private const array MAPPING = [
        "external_id" => 0,
        "code" => 1,
        "title" => 2,
        "credits" => 3,
    ];

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function csv(string ...$rows): string
    {
        return "External ID,Code,Title,Credits\n" . implode("\n", $rows) . "\n";
    }

    private function course(
        SisFixture $f,
        string $code,
        string $title = "Algebra",
        int $credits = 3000,
    ): EntityId {
        $id = EntityId::generate();
        $f->registration->addCourse(new Course($id, $code, $title, $credits));
        return $id;
    }

    public function testOneReviewedBatchCreatesDraftsLinksApprovedMatchesSkipsPriorImportsAndOmitsConflicts(): void
    {
        $f = new SisFixture();
        $person = $f->student()->personId;
        $f->restrictions()->create(
            new Restriction(
                EntityId::generate(),
                $person,
                "FICTIONAL",
                99,
                new DateRange(new DateTimeImmutable("2026-08-01")),
                true,
            ),
            "Fictional retained fact.",
        );
        $service = $f->imports();
        $earlier = $this->csv("earlier,HIST.101,History,3");
        $accepted = $service->preview("registrar", $earlier, self::MAPPING);
        $service->commit(
            "registrar",
            $earlier,
            self::MAPPING,
            $accepted["review_token"],
        );
        $match = $this->course($f, "MATH.101");
        $conflict = $this->course($f, "BIO.101", "Biology", 4000);
        $csv = $this->csv(
            "1,ENG.101,Writing,3.125",
            "2,MATH.101,Algebra,3",
            "3,BIO.101,Different biology,3",
            "earlier,HIST.101,History,3",
        );
        $before = $f->sql->rows("sis_courses");
        $events = $f->sql->rows("sis_history_events");
        $mappings = $f->sql->rows("sis_import_mappings");
        $audit = $f->sql->rows("sis_audit");
        $preview = $service->preview("registrar", $csv, self::MAPPING);
        self::assertFalse($preview["ready"]);
        self::assertSame(
            ["create", "match", "conflict", "skip"],
            array_column($preview["plan"], "action"),
        );
        self::assertNull($preview["review_token"]);
        self::assertArrayNotHasKey("rows", $preview);
        self::assertSame($before, $f->sql->rows("sis_courses"));
        self::assertSame($mappings, $f->sql->rows("sis_import_mappings"));
        self::assertSame($audit, $f->sql->rows("sis_audit"));
        $choices = [
            [
                "external_id" => "2",
                "action" => "link",
                "course_id" => $match->value,
            ],
            ["external_id" => "3", "action" => "skip", "course_id" => null],
        ];
        $review = $service->preview(
            "registrar",
            $csv,
            self::MAPPING,
            choices: $choices,
        );
        self::assertTrue($review["ready"]);
        $result = $service->commit(
            "registrar",
            $csv,
            self::MAPPING,
            $review["review_token"],
            choices: $choices,
        );
        self::assertSame(
            [1, 1, 1, 1, 4],
            array_values(array_diff_key($result, ["operation_id" => true])),
        );
        $created = $f->sql->one("sis_courses", ["code" => "ENG.101"]);
        self::assertSame(0, (int) $created["active"]);
        self::assertSame(3125, (int) $created["credits_milli"]);
        self::assertSame(
            $match->value,
            $f->sql->one("sis_import_mappings", ["external_id" => "2"])[
                "course_id"
            ],
        );
        foreach ($before as $course) {
            self::assertSame(
                $course,
                $f->sql->one("sis_courses", ["id" => $course["id"]]),
            );
        }
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertCount(4, $f->sql->rows("sis_courses"));
        self::assertSame(
            "Biology",
            $f->sql->one("sis_courses", ["id" => $conflict->value])["title"],
        );
    }

    public function testAcceptedRetryReturnsItsOriginalResultAfterExpiryAndLaterCatalogChanges(): void
    {
        $f = new SisFixture();
        $service = $f->imports();
        $csv = $this->csv("abc,MATH-101,Algebra,3");
        $review = $service->preview("REGISTRAR", $csv, self::MAPPING);
        $first = $service->commit(
            "registrar",
            $csv,
            self::MAPPING,
            $review["review_token"],
        );
        $f->sql->update(
            "sis_courses",
            ["title" => "Later catalog title"],
            ["code" => "MATH-101"],
        );
        $f->clock->time = $f->clock->time->modify("+1 day");
        $before = $f->sql->rows("sis_courses");
        $audit = $f->sql->rows("sis_audit");
        self::assertSame(
            $first,
            $service->commit(
                "registrar",
                $csv,
                self::MAPPING,
                $review["review_token"],
            ),
        );
        self::assertSame($before, $f->sql->rows("sis_courses"));
        self::assertSame($audit, $f->sql->rows("sis_audit"));
        $next = $service->preview("registrar", $csv, self::MAPPING);
        self::assertSame("skip", $next["plan"][0]["action"]);
        $second = $service->commit(
            "registrar",
            $csv,
            self::MAPPING,
            $next["review_token"],
        );
        self::assertSame(0, $second["imported"]);
        self::assertSame(1, $second["skipped"]);
        self::assertSame($before, $f->sql->rows("sis_courses"));
        $f->access->staff = false;
        $f->access->grants = [];
        $this->expectException(AccessDeniedException::class);
        $service->commit(
            "registrar",
            $csv,
            self::MAPPING,
            $review["review_token"],
        );
    }

    public function testChangedMatchedCoursesInvalidateAReviewBeforeAnyRowIsWritten(): void
    {
        $f = new SisFixture();
        $match = $this->course($f, "MATH.101");
        $csv = $this->csv("new,ENG.101,Writing,3", "same,MATH.101,Algebra,3");
        $choices = [
            [
                "external_id" => "same",
                "action" => "link",
                "course_id" => $match->value,
            ],
        ];
        $service = $f->imports();
        $review = $service->preview(
            "source",
            $csv,
            self::MAPPING,
            choices: $choices,
        );
        $f->sql->update(
            "sis_courses",
            ["credits_milli" => 4000],
            ["id" => $match->value],
        );
        try {
            $service->commit(
                "source",
                $csv,
                self::MAPPING,
                $review["review_token"],
                choices: $choices,
            );
            self::fail(
                "A changed matched course was linked from an older review.",
            );
        } catch (ConcurrentModificationException) {
            self::assertCount(1, $f->sql->rows("sis_courses"));
            self::assertSame([], $f->sql->rows("sis_import_mappings"));
            self::assertSame([], $f->sql->rows("sis_catalog_operations"));
        }
    }

    public function testALockingRereadRejectsCatalogChangesMadeWhileWaiting(): void
    {
        $f = new SisFixture();
        $match = $this->course($f, 'MATH.101');
        $inner = new SqlCatalogImportRepository($f->sql);
        $repository = $this->createStub(CatalogImportRepository::class);
        $repository->method('inspect')->willReturnCallback($inner->inspect(...));
        $repository->method('lockCourses')->willReturnCallback(
            static function (array $ids) use ($f, $match, $inner): array {
                $f->sql->update('sis_courses', ['active' => 0], ['id' => $match->value]);
                return $inner->lockCourses($ids);
            }
        );
        $service = $f->imports(repository: $repository);
        $csv = $this->csv('same,MATH.101,Algebra,3');
        $choices = [['external_id' => 'same', 'action' => 'link', 'course_id' => $match->value]];
        $review = $service->preview('source', $csv, self::MAPPING, choices: $choices);
        try {
            $service->commit('source', $csv, self::MAPPING, $review['review_token'], choices: $choices);
            self::fail('Catalog facts captured before a lock wait were trusted.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows('sis_import_mappings'));
            self::assertSame([], $f->sql->rows('sis_catalog_operations'));
        }
    }

    public function testReviewTokensBindSourceActorFileAndRowChoicesAndUnacceptedReviewsExpire(): void
    {
        $cases = ["source", "actor", "file", "choices", "tampered", "expiry"];
        foreach ($cases as $case) {
            $f = new SisFixture();
            $service = $f->imports();
            $csv = $this->csv("a,MATH.101,Algebra,3");
            $review = $service->preview("source", $csv, self::MAPPING);
            $token = $review["review_token"];
            $choices = [];
            $source = "source";
            if ($case === "source") {
                $source = "other";
            }
            if ($case === "actor") {
                $f->access->actor = $f->user();
            }
            if ($case === "file") {
                $csv = $this->csv("a,MATH.101,Changed,3");
            }
            if ($case === "choices") {
                $choices = [
                    [
                        "external_id" => "a",
                        "action" => "skip",
                        "course_id" => null,
                    ],
                ];
            }
            if ($case === "tampered") {
                $token =
                substr($token, 0, -1) .
                (str_ends_with($token, "a") ? "b" : "a");
            }
            if ($case === "expiry") {
                $f->clock->time = $f->clock->time->modify("+21 minutes");
            }
            try {
                $service->commit(
                    $source,
                    $csv,
                    self::MAPPING,
                    $token,
                    choices: $choices,
                );
                self::fail("An invalid review was accepted: " . $case);
            } catch (ValidationException | ConcurrentModificationException) {
                self::assertSame([], $f->sql->rows("sis_courses"));
                self::assertSame([], $f->sql->rows("sis_import_mappings"));
                self::assertSame([], $f->sql->rows("sis_catalog_operations"));
            }
        }
    }

    public function testAuditFailureLostPermissionAndMidCommitExpiryRollBackTheWholeBatchAndReceipt(): void
    {
        foreach (["audit", "permission", "expiry"] as $case) {
            $f = new SisFixture();
            $csv = $this->csv("a,ENG.101,Writing,3", "b,MATH.101,Algebra,3");
            $audit = $this->createStub(AuditTrail::class);
            $audit
                ->method("record")
                ->willReturnCallback(static function (
                    EntityId $actor,
                    string $action,
                ) use (
                    $f,
                    $case
): void {
                    if ($action !== "catalog.import_completed") {
                        return;
                    }
                    if ($case === "audit") {
                        throw new RuntimeException(
                            "Fictional required audit failure.",
                        );
                    }
                    if ($case === "permission") {
                        $f->access->staff = false;
                        $f->access->grants = [];
                    }
                    if ($case === "expiry") {
                        $f->clock->time = $f->clock->time->modify(
                            "+21 minutes",
                        );
                    }
                });
            $service = $f->imports($audit);
            $review = $service->preview("source", $csv, self::MAPPING);
            try {
                $service->commit(
                    "source",
                    $csv,
                    self::MAPPING,
                    $review["review_token"],
                );
                self::fail("An interrupted batch partially committed.");
            } catch (RuntimeException | AccessDeniedException | ConcurrentModificationException) {
                self::assertSame([], $f->sql->rows("sis_courses"));
                self::assertSame([], $f->sql->rows("sis_import_mappings"));
                self::assertSame([], $f->sql->rows("sis_catalog_operations"));
            }
        }
    }

    public function testConflictsIncludeChangedSourceDataDuplicateCodesAndCaseAmbiguitiesWithoutAutomaticUpdates(): void
    {
        $f = new SisFixture();
        $service = $f->imports();
        $csv = $this->csv("a,MATH.101,Algebra,3");
        $review = $service->preview("source", $csv, self::MAPPING);
        $service->commit(
            "source",
            $csv,
            self::MAPPING,
            $review["review_token"],
        );
        $changed = $service->preview(
            "source",
            $this->csv("a,MATH.101,Different title,3"),
            self::MAPPING,
        );
        self::assertFalse($changed["ready"]);
        self::assertSame("conflict", $changed["plan"][0]["action"]);
        $case = $service->preview(
            "source",
            $this->csv("A,MATH.101,Algebra,3"),
            self::MAPPING,
        );
        self::assertSame("conflict", $case["plan"][0]["action"]);
        $duplicate = $service->preview(
            "source",
            $this->csv("b,ENG.101,Writing,3", "c,eng.101,Writing,3"),
            self::MAPPING,
        );
        self::assertSame(
            ["create", "conflict"],
            array_column($duplicate["plan"], "action"),
        );
        $fixed = $service->preview(
            "source",
            $this->csv("b,ENG.101,Writing,3", "c,eng.101,Writing,3"),
            self::MAPPING,
            choices: [
                ["external_id" => "c", "action" => "skip", "course_id" => null],
            ],
        );
        self::assertTrue($fixed["ready"]);
        $badIds = $service->preview(
            "source",
            $this->csv("b,ENG.101,Writing,3", "B,ENG.102,Other writing,3"),
            self::MAPPING,
        );
        self::assertFalse($badIds["ready"]);
        self::assertNotEmpty($badIds["errors"]);
    }

    public function testMalformedAndForeignLinkChoicesCannotWriteOrManufactureAReadyReview(): void
    {
        $f = new SisFixture();
        $match = $this->course($f, "MATH.101");
        $csv = $this->csv("1,MATH.101,Algebra,3");
        $service = $f->imports();
        $wrong = $service->preview(
            "source",
            $csv,
            self::MAPPING,
            choices: [
                [
                    "external_id" => "1",
                    "action" => "link",
                    "course_id" => EntityId::generate()->value,
                ],
            ],
        );
        self::assertFalse($wrong["ready"]);
        self::assertNull($wrong["review_token"]);
        $this->expectException(InvalidArgumentException::class);
        $service->preview(
            "source",
            $csv,
            self::MAPPING,
            choices: [
                [
                    "external_id" => "foreign",
                    "action" => "link",
                    "course_id" => $match->value,
                ],
            ],
        );
    }

    public function testThousandRecordReviewIsBoundedAndDoesNotWriteDraftsOrOperationalReceipts(): void
    {
        $f = new SisFixture();
        $rows = [];
        for ($i = 0; $i < 1000; ++$i) {
            $rows[] =
            "source-" .
            $i .
            ",SUBJ." .
            $i .
            ",Fictional course " .
            $i .
            ",3";
        }
        $service = $f->imports();
        $review = $service->preview(
            "source",
            $this->csv(...$rows),
            self::MAPPING,
        );
        self::assertTrue($review["ready"]);
        self::assertCount(1000, $review["plan"]);
        self::assertSame(1000, $review["summary"]["create"]);
        self::assertSame([], $f->sql->rows("sis_courses"));
        self::assertSame([], $f->sql->rows("sis_catalog_operations"));
        $rows[] = "last,SUBJ.LAST,Too many,3";
        $this->expectException(InvalidArgumentException::class);
        $service->preview("source", $this->csv(...$rows), self::MAPPING);
    }

    public function testConcurrentCodeConflictReturnsTheSourceRowAndRollsBackEarlierRows(): void
    {
        $f = new SisFixture();
        $inner = new SqlCatalogImportRepository($f->sql);
        $repository = $this->createStub(CatalogImportRepository::class);
        $repository
            ->method("inspect")
            ->willReturnCallback($inner->inspect(...));
        $repository
            ->method("lockCourses")
            ->willReturnCallback($inner->lockCourses(...));
        $repository
            ->method("create")
            ->willReturnCallback(static function (
                string $source,
                CourseImportRow $row,
            ) use (
                $f,
                $inner
): EntityId {
                if ($row->externalId === "b") {
                    $f->registration->addCourse(
                        new Course(
                            EntityId::generate(),
                            $row->code,
                            "Fictional competing draft",
                            3000,
                        ),
                    );
                }
                return $inner->create($source, $row);
            });
        $service = $f->imports(repository: $repository);
        $csv = $this->csv("a,ENG.101,Writing,3", "b,MATH.101,Algebra,3");
        $review = $service->preview("source", $csv, self::MAPPING);
        try {
            $service->commit(
                "source",
                $csv,
                self::MAPPING,
                $review["review_token"],
            );
            self::fail("A competing code did not prevent partial import.");
        } catch (ValidationException $error) {
            self::assertSame("row_3", $error->errors[0]["field"]);
            self::assertSame([], $f->sql->rows("sis_courses"));
            self::assertSame([], $f->sql->rows("sis_import_mappings"));
            self::assertSame([], $f->sql->rows("sis_catalog_operations"));
        }
    }
}
