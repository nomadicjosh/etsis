<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/CatalogWorkflowFixture.php";

use Domain\Catalog\Service\SubjectService;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlSubjectRepository;
use PHPUnit\Framework\TestCase;

final class SubjectPublicationTest extends TestCase
{
    private CatalogWorkflowFixture $c;
    private SubjectService $subjects;
    protected function setUp(): void
    {
        $this->c = new CatalogWorkflowFixture();
        $f = $this->c->sis;
        $this->subjects = new SubjectService(
            new SqlSubjectRepository($f->sql),
            new SqlCatalogRevisionRepository($f->sql),
            $f->access,
            $f->transactions,
            $f->audit,
        );
    }
    private function assign(EntityId $course, ?EntityId $subject): void
    {
        $this->subjects->assign($course, $subject, $this->subjects->course($course)["revision"]);
    }
    private function publish(EntityId $course): EntityId
    {
        $r = $this->c->publications()->review($course, "Fictional subject publication approval");
        return new EntityId($this->c->publications()->publish($course, $r["review_token"])["operation_id"]);
    }
    public function testExplicitSubjectSurvivesRenamesAndFeedsRetainedPortalAndAcademicReports(): void
    {
        $f = $this->c->sis;
        $section = $f->section();
        $subject = $this->subjects->save(null, "math", "Mathematics", true, null);
        $this->assign($section->courseId, new EntityId($subject["id"]));
        $version = $this->publish($section->courseId);
        $bind = $this->c->bindings()->review($section->id, $version, "Fictional subject binding");
        $this->c->bindings()->commit($section->id, $bind["review_token"]);
        $student = $f->student();
        $f->service->register($student->id, $section->id, true);
        $before = $f->sql->rows("sis_history_events");
        $this->subjects->save(
            new EntityId($subject["id"]),
            "MTH",
            "Mathematical sciences",
            false,
            $subject["revision"],
        );
        $published = $this->c->publications()->publishedDefinition($section->courseId, $version);
        self::assertSame("MATH", $published->subject->code);
        self::assertSame("Mathematics", $published->subject->title);
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        $portal = new \Infrastructure\Persistence\Repository\SqlCampusRepository($f->sql);
        self::assertSame(
            "Mathematics",
            $portal->sections($f->term, "Mathematics", null)["items"][0]["subject_title"],
        );
        $source = new \Infrastructure\Reporting\SqlRelationalReportSource($f->sql);
        foreach (["sections", "credits"] as $dataset) {
            $datasets = $source->datasets();
            $query = new \Domain\Reporting\ValueObject\ReportQuery(
                [
                    "dataset" => $dataset,
                    "columns" => [
                        ["field" => "retained_subject_code", "label" => "Subject", "aggregate" => "none"],
                        ["field" => "retained_subject_title", "label" => "Name", "aggregate" => "none"],
                    ],
                    "filters" => null,
                    "having" => null,
                    "sort" => [],
                ],
                $datasets[$dataset],
            );
            self::assertSame("MATH", $source->execute($query, 0)[0]["c0"]);
            self::assertSame("Mathematics", $source->execute($query, 0)[0]["c1"]);
        }
        $later = $this->publish($section->courseId);
        self::assertSame(
            "MTH",
            $this->c->publications()->publishedDefinition($section->courseId, $later)->subject->code,
        );
        self::assertSame("Mathematics", $portal->sections($f->term, "", null)["items"][0]["subject_title"]);
    }
    public function testSubjectRenameInvalidatesPendingPublicationAndRetirementPreventsNewAssignments(): void
    {
        $section = $this->c->sis->section();
        $s = $this->subjects->save(null, "ENG", "English", true, null);
        $this->assign($section->courseId, new EntityId($s["id"]));
        $r = $this->c->publications()->review($section->courseId, "Fictional review");
        $this->subjects->save(new EntityId($s["id"]), "ENG", "English studies", false, $s["revision"]);
        try {
            $this->c->publications()->publish($section->courseId, $r["review_token"]);
            self::fail("Stale subject was published.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $this->c->sis->sql->rows("sis_history_events"));
        }
        $other = $this->c->sis->section();
        $this->expectException(ValidationException::class);
        $this->assign($other->courseId, new EntityId($s["id"]));
    }
    public function testAssignmentChangesRequireRevisionAndDoNotParseCourseCodes(): void
    {
        $course = $this->c->sis->section()->courseId;
        self::assertNull($this->subjects->course($course)["subject"]);
        $old = $this->subjects->course($course)["revision"];
        $s = $this->subjects->save(null, "HIST", "History", true, null);
        $this->assign($course, new EntityId($s["id"]));
        $this->expectException(ConcurrentModificationException::class);
        $this->subjects->assign($course, null, $old);
    }
    public function testRetiredSubjectCanBeEditedUsingItsPagedRevisionAndCodesAreUnique(): void
    {
        $s = $this->subjects->save(null, "CS", "Computing", false, null);
        $page = $this->subjects->page("CS");
        $this->subjects->save(
            new EntityId($s["id"]),
            "CS",
            "Computer science",
            true,
            $page["items"][0]["revision"],
        );
        $this->expectException(ValidationException::class);
        $this->subjects->save(null, "cs", "Duplicate", true, null);
    }
    public function testAssignmentAuditFailureRollsBackTheDraftAndDoesNotCreateHistory(): void
    {
        $f = $this->c->sis;
        $course = $f->section()->courseId;
        $s = $this->subjects->save(null, "BIO", "Biology", true, null);
        $f->connection->pdo->exec(
            "CREATE TRIGGER subject_audit_fail BEFORE INSERT ON sis_audit BEGIN SELECT RAISE(ABORT, 'fictional failure'); END",
        );
        try {
            $this->assign($course, new EntityId($s["id"]));
            self::fail("Audit failure left an assignment.");
        } catch (PDOException) {
            self::assertNull($this->subjects->course($course)["subject"]);
            self::assertSame([], $f->sql->rows("sis_history_events"));
        }
    }
    public function testCatalogPermissionProtectsSubjectSetupAndAssignment(): void
    {
        $course = $this->c->sis->section()->courseId;
        $this->c->sis->access->staff = false;
        $this->c->sis->access->grants = ["sis:registration:manage"];
        $this->expectException(\Domain\Shared\Exception\AccessDeniedException::class);
        $this->assign($course, null);
    }
    public function testSubjectCacheFailureRollsBackTheBindingAndItsReceipt(): void
    {
        $f = $this->c->sis;
        $section = $f->section();
        $s = $this->subjects->save(null, "CHEM", "Chemistry", true, null);
        $this->assign($section->courseId, new EntityId($s["id"]));
        $version = $this->publish($section->courseId);
        $r = $this->c->bindings()->review($section->id, $version, "Fictional binding");
        $before = $f->sql->rows("sis_history_events");
        $f->connection->pdo->exec(
            "CREATE TRIGGER fail_subject_cache BEFORE INSERT ON sis_course_version_subjects BEGIN SELECT RAISE(ABORT, 'fictional failure'); END",
        );
        try {
            $this->c->bindings()->commit($section->id, $r["review_token"]);
            self::fail("Subject projection failure left a partial binding.");
        } catch (PDOException) {
            self::assertSame($before, $f->sql->rows("sis_history_events"));
            self::assertSame([], $f->sql->rows("sis_course_versions"));
            self::assertSame([], $f->sql->rows("sis_section_course_versions"));
        }
        $f->connection->pdo->exec("DROP TRIGGER fail_subject_cache");
        $this->c->bindings()->commit($section->id, $r["review_token"]);
        self::assertSame("CHEM", $f->sql->rows("sis_course_version_subjects")[0]["code"]);
    }

    public function testCompletedPreviousProofWithSchemaTwoRemainsRetryableAfterExpiry(): void
    {
        $f = $this->c->sis;
        $course = $f->section()->courseId;
        $pub = $this->c->publications();
        $r = $pub->review($course, "Fictional preceding implementation");
        $proof = json_decode(
            $this->c->fixture->cipher->open($r["review_token"]),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $proof["kind"] = "course-publication-review.v3";
        unset($proof["definition"]["subject"]);
        $operation = new EntityId($proof["operation_id"]);
        $stream = new \Domain\Shared\EventSourcing\StreamReference(
            new \Domain\Catalog\ValueObject\CoursePublicationId($course->value),
            "course_publication",
        );
        $history = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(
                EventStoreFixture::config($f->connection->queryBuilder()),
            ),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
        $event = \Domain\Catalog\Event\CourseDefinitionWasPublished::occur(
            $stream->aggregateId,
            [
                "version_id" => $operation->value,
                "previous_version_id" => null,
                "definition" => $proof["definition"],
            ],
            ["__aggregate_type" => "course_publication", "schema_version" => 2],
        );
        $receipt = $history->append(
            $stream,
            $operation,
            0,
            new \Domain\Shared\EventSourcing\EventContext(
                $f->access->actor,
                $f->clock->now(),
                $operation,
                reason: $proof["reason"],
            ),
            [$event],
        );
        $before = $f->sql->rows("sis_history_events");
        $f->clock->time = $f->clock->time->modify("+2 days");
        $token = $this->c->fixture->cipher->seal(json_encode($proof, JSON_THROW_ON_ERROR));
        self::assertSame($receipt, $pub->publish($course, $token));
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        self::assertSame([], $f->sql->rows("sis_course_version_subjects"));
    }

    public function testMaximumMultilingualCourseAndSubjectFitTheBoundedReviewProof(): void
    {
        $f = $this->c->sis;
        $course = $f->section()->courseId;
        $subject = $this->subjects->save(null, "MATH", str_repeat("🎓", 191), true, null);
        $this->assign($course, new EntityId($subject["id"]));
        $editor = new \Domain\Catalog\Service\CatalogEditor(
            new SqlCatalogRevisionRepository($f->sql),
            $f->registration,
            $f->access,
            $f->transactions,
            $f->audit,
        );
        $editor->courseText(
            $course,
            new \Domain\Catalog\ValueObject\CourseText(str_repeat("🎓", 80), str_repeat("🎓", 10000)),
            $editor->view("courses", $course)["revision"],
        );
        $review = $this->c->publications()->review($course, str_repeat("🎓", 1000));
        self::assertLessThanOrEqual(524288, strlen($review["review_token"]));
        self::assertSame(
            1,
            $this->c->publications()->publish($course, $review["review_token"])["last_version"],
        );
    }

    public function testSubjectSnapshotRejectsMalformedHistoricalShapes(): void
    {
        $valid = ["id" => EntityId::generate()->value, "code" => "MATH", "title" => "Mathematics"];
        $cases = [
            array_replace($valid, ["code" => "math"]),
            array_replace($valid, ["title" => "Bad\nname"]),
            $valid + ["active" => true],
        ];
        foreach ($cases as $bad) {
            try {
                \Domain\Catalog\ValueObject\SubjectSnapshot::fromArray($bad);
                self::fail("Invalid subject snapshot accepted.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
