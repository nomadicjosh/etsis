<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/CatalogWorkflowFixture.php";

use Domain\Catalog\Enum\ClassificationKind;
use Domain\Catalog\Service\ClassificationService;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlClassificationRepository;
use Infrastructure\Persistence\Repository\SqlSectionCourseBindingRepository;
use PHPUnit\Framework\TestCase;

final class CourseClassificationTest extends TestCase
{
    private CatalogWorkflowFixture $catalog;
    private ClassificationService $service;

    protected function setUp(): void
    {
        $this->catalog = new CatalogWorkflowFixture();
        $f = $this->catalog->sis;
        $this->service = new ClassificationService(
            new SqlClassificationRepository($f->sql),
            new SqlCatalogRevisionRepository($f->sql),
            $f->access,
            $f->transactions,
            $f->audit,
        );
    }

    private function assign(
        EntityId $course,
        ?EntityId $department,
        ?EntityId $academic = null,
        ?EntityId $level = null,
    ): void {
        $this->service->assign(
            $course,
            $department,
            $academic,
            $level,
            $this->service->course($course)["revision"],
        );
    }

    public function testPublicationsAndJoinedReportsKeepClassificationLabelsAfterRenamingAndRetirement(): void
    {
        $f = $this->catalog->sis;
        $section = $f->section();
        $rows = [];
        foreach (ClassificationKind::courseKinds() as $kind) {
            $rows[$kind->value] = $this->service->save(
                $kind,
                null,
                "OLD",
                "Original " . $kind->value,
                true,
                null,
            );
        }
        $this->assign(
            $section->courseId,
            new EntityId($rows["department"]["id"]),
            new EntityId($rows["academic_level"]["id"]),
            new EntityId($rows["course_level"]["id"]),
        );
        self::assertSame(
            [],
            $f->sql->rows("sis_history_events"),
            "Draft setup must not invent publication events.",
        );
        $review = $this->catalog
            ->publications()
            ->review($section->courseId, "Fictional classification approval");
        $version = new EntityId(
            $this->catalog->publications()->publish($section->courseId, $review["review_token"])[
                "operation_id"
            ],
        );
        $binding = $this->catalog
            ->bindings()
            ->review($section->id, $version, "Fictional classification binding");
        $this->catalog->bindings()->commit($section->id, $binding["review_token"]);
        $student = $f->student();
        $f->service->register($student->id, $section->id, true);
        $before = $f->sql->rows("sis_history_events");
        foreach (ClassificationKind::courseKinds() as $kind) {
            $row = $rows[$kind->value];
            $this->service->save(
                $kind,
                new EntityId($row["id"]),
                "NEW",
                "New " . $kind->value,
                false,
                $row["revision"],
            );
        }
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        $bound = new SqlSectionCourseBindingRepository($f->sql)->bound($section->id);
        self::assertSame(
            $review["definition"]["classifications"],
            $bound->definition->classifications->describe(),
        );
        self::assertSame("Original department", $bound->definition->classifications->department->title);
        $newReview = $this->catalog
            ->publications()
            ->review($section->courseId, "Fictional new catalog approval");
        $this->catalog->publications()->publish($section->courseId, $newReview["review_token"]);
        self::assertSame("NEW", $newReview["definition"]["classifications"]["department"]["code"]);
        self::assertSame(
            $review["definition"]["classifications"],
            $this->catalog
                ->publications()
                ->publishedDefinition($section->courseId, $version)
                ->definition->classifications->describe(),
        );
        $source = new \Infrastructure\Reporting\SqlRelationalReportSource($f->sql);
        foreach (["sections", "credits"] as $dataset) {
            $columns = [];
            foreach (ClassificationKind::courseKinds() as $kind) {
                $columns[] = [
                    "field" => "retained_" . $kind->value . "_title",
                    "label" => $kind->value,
                    "aggregate" => "none",
                ];
            }
            $query = new \Domain\Reporting\ValueObject\ReportQuery(
                [
                    "dataset" => $dataset,
                    "columns" => $columns,
                    "filters" => null,
                    "having" => null,
                    "sort" => [],
                ],
                $source->datasets()[$dataset],
            );
            self::assertSame(
                [
                    "c0" => "Original department",
                    "c1" => "Original academic_level",
                    "c2" => "Original course_level",
                ],
                $source->execute($query, 0)[0],
            );
        }
    }

    public function testRenamingAfterReviewRequiresFreshPublicationAndAssignmentReviews(): void
    {
        $course = $this->catalog->sis->section()->courseId;
        $row = $this->service->save(ClassificationKind::Department, null, "SCI", "Science", true, null);
        $id = new EntityId($row["id"]);
        $this->assign($course, $id);
        $oldRevision = $this->service->course($course)["revision"];
        $review = $this->catalog->publications()->review($course, "Fictional science review");
        $this->service->save(ClassificationKind::Department, $id, "SCI", "Sciences", true, $row["revision"]);
        try {
            $this->catalog->publications()->publish($course, $review["review_token"]);
            self::fail("Renamed classification was accepted under an earlier review.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $this->catalog->sis->sql->rows("sis_history_events"));
        }
        $this->expectException(ConcurrentModificationException::class);
        $this->service->assign($course, null, null, null, $oldRevision);
    }

    public function testRetirementPreservesCurrentAssignmentsAndPreventsNewOnes(): void
    {
        $course = $this->catalog->sis->section()->courseId;
        $row = $this->service->save(ClassificationKind::Department, null, "SCI", "Science", true, null);
        $id = new EntityId($row["id"]);
        $this->assign($course, $id);
        $this->service->save(ClassificationKind::Department, $id, "SCI", "Science", false, $row["revision"]);
        $this->assign($course, $id);
        self::assertSame("SCI", $this->service->course($course)["classifications"]["department"]["code"]);
        $this->expectException(ValidationException::class);
        $this->assign($this->catalog->sis->section()->courseId, $id);
    }

    public function testCategoryChecksPreventUsingADepartmentAsAnAcademicLevel(): void
    {
        $course = $this->catalog->sis->section()->courseId;
        $row = $this->service->save(ClassificationKind::Department, null, "SCI", "Science", true, null);
        try {
            $this->assign($course, null, new EntityId($row["id"]));
            self::fail("A department was used as an academic level.");
        } catch (ValidationException) {
            self::assertSame([], $this->catalog->sis->sql->rows("sis_course_classifications"));
        }
        $this->expectException(ConcurrentModificationException::class);
        $this->service->save(
            ClassificationKind::AcademicLevel,
            new EntityId($row["id"]),
            "SCI",
            "Moved",
            true,
            $row["revision"],
        );
    }

    public function testCodesAreUniqueWithinACategoryAndPagesStayBounded(): void
    {
        foreach (ClassificationKind::courseKinds() as $kind) {
            $this->service->save($kind, null, "SAME", "Separate " . $kind->value, true, null);
        }
        for ($number = 0; $number < 51; $number++) {
            $this->service->save(
                ClassificationKind::Department,
                null,
                "SCI." . $number,
                "Science " . $number,
                true,
                null,
            );
        }
        $first = $this->service->page(ClassificationKind::Department);
        self::assertCount(50, $first["items"]);
        $last = $this->service->page(ClassificationKind::Department, "", new EntityId($first["next_after"]));
        self::assertCount(2, $last["items"]);
        self::assertNull($last["next_after"]);
        self::assertCount(1, $this->service->page(ClassificationKind::AcademicLevel)["items"]);
        $this->expectException(ValidationException::class);
        $this->service->save(ClassificationKind::Department, null, "same", "Duplicate", true, null);
    }

    public function testRequiredAuditFailureRollsBackAnAssignment(): void
    {
        $f = $this->catalog->sis;
        $course = $f->section()->courseId;
        $row = $this->service->save(ClassificationKind::Department, null, "SCI", "Science", true, null);
        $f->connection->pdo->exec(
            "CREATE TRIGGER class_audit_fail BEFORE INSERT ON sis_audit BEGIN SELECT RAISE(ABORT, 'fictional failure'); END",
        );
        try {
            $this->assign($course, new EntityId($row["id"]));
            self::fail("Assignment survived required audit failure.");
        } catch (PDOException) {
            self::assertSame([], $f->sql->rows("sis_course_classifications"));
            self::assertSame([], $f->sql->rows("sis_history_events"));
        }
    }

    public function testRetainedClassificationFailureRollsBackBindingWithoutRewritingPublication(): void
    {
        $f = $this->catalog->sis;
        $section = $f->section();
        $review = $this->catalog->publications()->review($section->courseId, "Fictional publication");
        $version = new EntityId(
            $this->catalog->publications()->publish($section->courseId, $review["review_token"])[
                "operation_id"
            ],
        );
        $binding = $this->catalog->bindings()->review($section->id, $version, "Fictional binding");
        $before = $f->sql->rows("sis_history_events");
        $f->connection->pdo->exec(
            "CREATE TRIGGER class_cache_fail BEFORE INSERT ON sis_course_version_classes BEGIN SELECT RAISE(ABORT, 'fictional failure'); END",
        );
        try {
            $this->catalog->bindings()->commit($section->id, $binding["review_token"]);
            self::fail("A partial binding survived reference failure.");
        } catch (PDOException) {
            self::assertSame($before, $f->sql->rows("sis_history_events"));
            self::assertSame([], $f->sql->rows("sis_course_versions"));
            self::assertSame([], $f->sql->rows("sis_section_course_versions"));
        }
    }

    public function testMalformedRetainedClassificationsCannotBecomeAnUnspecifiedValue(): void
    {
        $f = $this->catalog->sis;
        $section = $f->section();
        $review = $this->catalog->publications()->review($section->courseId, "Fictional publication");
        $version = new EntityId(
            $this->catalog->publications()->publish($section->courseId, $review["review_token"])[
                "operation_id"
            ],
        );
        $binding = $this->catalog->bindings()->review($section->id, $version, "Fictional binding");
        $this->catalog->bindings()->commit($section->id, $binding["review_token"]);
        $f->sql->update(
            "sis_course_version_classes",
            ["department_title" => "Invented label"],
            ["id" => $version->value],
        );
        $this->expectException(CorruptHistoryException::class);
        new SqlSectionCourseBindingRepository($f->sql)->bound($section->id);
    }

    public function testRegistrationAuthorityAloneCannotReadOrChangeClassificationSetup(): void
    {
        $course = $this->catalog->sis->section()->courseId;
        $this->catalog->sis->access->staff = false;
        $this->catalog->sis->access->grants = ["sis:registration:manage"];
        $attempts = [
            fn() => $this->service->page(ClassificationKind::Department),
            fn() => $this->service->course($course),
            fn() => $this->service->save(ClassificationKind::Department, null, "NO", "Denied", true, null),
            fn() => $this->service->assign($course, null, null, null, str_repeat("a", 64)),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail("Registration authority exposed catalog configuration.");
            } catch (AccessDeniedException) {
                self::assertSame([], $this->catalog->sis->sql->rows("sis_catalog_classifications"));
            }
        }
    }
}
