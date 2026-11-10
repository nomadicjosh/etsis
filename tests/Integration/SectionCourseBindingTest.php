<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Codefy\Domain\EventSourcing\EventStream;
use Domain\Catalog\Course;
use Domain\Catalog\Service\CatalogEditor;
use Domain\Catalog\Service\CoursePublicationService;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Section;
use Domain\Registration\SectionCourseBinding;
use Domain\Registration\Service\SectionCourseBindingService;
use Domain\Registration\ValueObject\SectionCourseBindingId;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\Persistence\Repository\SqlCampusRepository;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlCatalogWorkflowRepository;
use Infrastructure\Persistence\Repository\SqlCourseTextProjection;
use Infrastructure\Persistence\Repository\SqlSectionCourseBindingRepository;
use PHPUnit\Framework\TestCase;

final class SectionCourseBindingTest extends TestCase
{
    private SisFixture $f;
    private EntityId $course;
    private Section $section;
    private CoursePublicationService $publications;
    private SectionCourseBindingService $service;
    private SqlHistoricalEventStore $history;
    private CatalogEditor $editor;

    protected function setUp(): void
    {
        $this->f = $f = new SisFixture();
        $this->course = EntityId::generate();
        $f->registration->addCourse(new Course($this->course, "MATH.101", "Algebra", 3000));
        $this->section = $this->newSection("MATH.101.A");
        $this->history = new SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new ProtectedEventCodec(EventStoreFixture::config($f->connection->queryBuilder())),
            new HistoricalSchemaRegistry(),
        );
        $catalog = new SqlCatalogRevisionRepository($f->sql);
        $this->publications = new CoursePublicationService(
            $catalog,
            new SqlCatalogWorkflowRepository($f->sql),
            $this->history,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
            new SqlCourseTextProjection($f->sql),
        );
        $this->service = new SectionCourseBindingService(
            $catalog,
            $this->publications,
            new SqlSectionCourseBindingRepository($f->sql),
            $this->history,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
        );
        $this->editor = new CatalogEditor(
            $catalog,
            $f->registration,
            $f->access,
            $f->transactions,
            $f->audit,
        );
    }

    private function newSection(string $code): Section
    {
        $s = new Section(
            EntityId::generate(),
            $this->course,
            $this->f->term,
            null,
            $code,
            20,
            true,
            true,
            new Money(10000, "USD"),
        );
        $this->f->registration->addSection($s);
        return $s;
    }

    private function publish(): EntityId
    {
        $review = $this->publications->review($this->course, "Fictional catalog publication");
        return new EntityId(
            $this->publications->publish($this->course, $review["review_token"])["operation_id"],
        );
    }

    private function bind(EntityId $version, ?Section $section = null): array
    {
        $id = ($section ?? $this->section)->id;
        $review = $this->service->review($id, $version, "Fictional offering decision");
        return $this->service->commit($id, $review["review_token"]);
    }

    public function testExplicitBindingRetainsAReplayableEventAndSharesTheDefinitionAcrossSections(): void
    {
        $version = $this->publish();
        $review = $this->service->review($this->section->id, $version, "Fictional offering decision");
        self::assertSame([], $this->f->sql->rows("sis_course_versions"));
        self::assertCount(1, $this->f->sql->rows("sis_history_events"));
        $receipt = $this->service->commit($this->section->id, $review["review_token"]);
        $stream = new StreamReference(
            new SectionCourseBindingId($this->section->id->value),
            "section_course_binding",
        );
        $events = array_map(static fn($stored) => $stored->event, $this->history->page($stream)->events);
        $restored = SectionCourseBinding::reconstituteFromEventStream(
            new EventStream($stream->aggregateId, $events),
        );
        self::assertSame($version->value, $restored->courseVersionId());
        self::assertSame(1, $restored->playhead());
        self::assertSame($version->value, $this->service->show($this->section->id)["bound"]["version_id"]);
        $this->bind($version, $this->newSection("MATH.101.B"));
        self::assertCount(1, $this->f->sql->rows("sis_course_versions"));
        self::assertCount(2, $this->f->sql->rows("sis_section_course_versions"));
        self::assertSame(1, $receipt["last_version"]);
        self::assertStringNotContainsString(
            "Fictional offering",
            json_encode($this->f->sql->rows("sis_history_events")),
        );
    }

    public function testNewRegistrationsAndPortalKeepTheSelectedDefinitionAfterCatalogEdits(): void
    {
        $revision = $this->editor->view("courses", $this->course)["revision"];
        $this->editor->courseText(
            $this->course,
            new \Domain\Catalog\ValueObject\CourseText("Algebra I", "Original description"),
            $revision,
        );
        $version = $this->publish();
        $this->bind($version);
        $unbound = $this->newSection("MATH.101.C");
        $revision = $this->editor->view("courses", $this->course)["revision"];
        $this->editor->course(new Course($this->course, "MATH.201", "Advanced Algebra", 4000), $revision);
        $required = EntityId::generate();
        $this->f->registration->addCourse(new Course($required, "MATH.090", "Foundations", 1000));
        $this->f->registration->addPrerequisite($this->course, $required);
        $revision = $this->editor->view("courses", $this->course)["revision"];
        $this->editor->courseText(
            $this->course,
            new \Domain\Catalog\ValueObject\CourseText("Algebra II", "Later description"),
            $revision,
        );
        $this->publish();
        $student = $this->f->student();
        $e = $this->f->service->register($student->id, $this->section->id);
        $credit = $this->f->sql->one("sis_academic_credits", ["source_id" => $e->id->value]);
        self::assertSame("MATH.101", $credit["course_code"]);
        self::assertSame("Algebra I", $credit["course_title"]);
        self::assertSame(3000, (int) $credit["attempted_milli"]);
        self::assertSame(
            $version->value,
            $this->f->sql->one("sis_credit_course_versions", ["id" => $credit["id"]])["course_version_id"],
        );
        $portal = new SqlCampusRepository($this->f->sql);
        $rows = $portal->sections($this->f->term, "Algebra", null)["items"];
        $rows = array_column($rows, null, "id");
        self::assertSame("Algebra", $rows[$this->section->id->value]["title"]);
        self::assertSame("Original description", $rows[$this->section->id->value]["description"]);
        self::assertSame("Later description", $rows[$unbound->id->value]["description"]);
        self::assertSame("Advanced Algebra", $rows[$unbound->id->value]["title"]);
        self::assertNull($rows[$unbound->id->value]["course_version_id"]);
        self::assertSame("Algebra", $portal->enrollments($student->id)[0]["title"]);
        self::assertFalse($this->f->registration->prerequisitesMet($student->id, $this->course));
        self::assertTrue($this->f->registration->prerequisitesMet($student->id, $this->course, []));
    }

    public function testPinnedPrerequisiteRemainsRequiredAfterRemovalFromCurrentCatalog(): void
    {
        $required = EntityId::generate();
        $this->f->registration->addCourse(new Course($required, "MATH.090", "Foundations", 1000));
        $this->f->registration->addPrerequisite($this->course, $required);
        $this->bind($this->publish());
        $this->f->registration->removePrerequisite($this->course, $required);
        $student = $this->f->student();
        self::assertTrue($this->f->registration->prerequisitesMet($student->id, $this->course));
        $this->expectException(DomainException::class);
        $this->f->service->register($student->id, $this->section->id);
    }

    public function testStaleSectionReviewRollsBackEventReceiptAndProjection(): void
    {
        $review = $this->service->review($this->section->id, $this->publish(), "Fictional review");
        $before = $this->f->sql->rows("sis_history_operations");
        $this->f->sql->update("sis_sections", ["capacity" => 21], ["id" => $this->section->id->value]);
        try {
            $this->service->commit($this->section->id, $review["review_token"]);
            self::fail("A stale section review was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertCount(1, $this->f->sql->rows("sis_history_events"));
            self::assertSame($before, $this->f->sql->rows("sis_history_operations"));
            self::assertSame([], $this->f->sql->rows("sis_course_versions"));
            self::assertSame([], $this->f->sql->rows("sis_section_course_versions"));
        }
    }

    public function testEnrollmentAfterReviewPreventsRetrospectiveBinding(): void
    {
        $version = $this->publish();
        $review = $this->service->review($this->section->id, $version, "Fictional review");
        $student = $this->f->student();
        $this->f->service->register($student->id, $this->section->id);
        $events = $this->f->sql->rows("sis_history_events");
        try {
            $this->service->commit($this->section->id, $review["review_token"]);
            self::fail("A populated section was bound retrospectively.");
        } catch (DomainException) {
            self::assertSame($events, $this->f->sql->rows("sis_history_events"));
            self::assertSame([], $this->f->sql->rows("sis_section_course_versions"));
        }
        $this->expectException(DomainException::class);
        $this->service->review($this->section->id, $version, "Fictional second review");
    }

    public function testRetryAfterEnrollmentAndEditsReturnsOriginalReceiptWithoutDuplicateAudit(): void
    {
        $version = $this->publish();
        $review = $this->service->review($this->section->id, $version, "Fictional review");
        $receipt = $this->service->commit($this->section->id, $review["review_token"]);
        $student = $this->f->student();
        $this->f->service->register($student->id, $this->section->id);
        $this->f->sql->update("sis_sections", ["capacity" => 21], ["id" => $this->section->id->value]);
        $audit = $this->f->sql->rows("sis_audit");
        self::assertSame($receipt, $this->service->commit($this->section->id, $review["review_token"]));
        self::assertSame($audit, $this->f->sql->rows("sis_audit"));
        $this->expectException(DomainException::class);
        $this->service->review($this->section->id, $version, "Fictional second binding");
    }

    public function testPermissionsActorScopeAndTamperAreRechecked(): void
    {
        $review = $this->service->review($this->section->id, $this->publish(), "Fictional review");
        foreach ([$review["review_token"] . "tampered", $this->f->dropCipher->seal("{}")] as $token) {
            try {
                $this->service->commit($this->section->id, $token);
                self::fail("Invalid token accepted.");
            } catch (InvalidArgumentException) {
                self::assertSame([], $this->f->sql->rows("sis_section_course_versions"));
            }
        }
        $this->f->access->actor = $this->f->user();
        try {
            $this->service->commit($this->section->id, $review["review_token"]);
            self::fail("Another actor used a section review.");
        } catch (AccessDeniedException) {
            self::assertCount(1, $this->f->sql->rows("sis_history_events"));
        }
        $this->f->access->staff = false;
        $calls = [
            fn() => $this->service->show($this->section->id),
            fn() => $this->service->commit($this->section->id, $review["review_token"]),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail("Permission was not rechecked.");
            } catch (AccessDeniedException) {
                self::assertSame([], $this->f->sql->rows("sis_section_course_versions"));
            }
        }
    }

    public function testAuditFailureRollsBackAndTheSameReviewCanBeRetried(): void
    {
        $review = $this->service->review($this->section->id, $this->publish(), "Fictional review");
        $this->f->connection->pdo->exec(
            "CREATE TRIGGER fail_binding_audit BEFORE INSERT ON sis_audit " .
                "WHEN NEW.action = 'section.course_version_bound' " .
                "BEGIN SELECT RAISE(ABORT, 'fictional failure'); END",
        );
        try {
            $this->service->commit($this->section->id, $review["review_token"]);
            self::fail("The audit failure was ignored.");
        } catch (PDOException) {
            self::assertCount(1, $this->f->sql->rows("sis_history_events"));
            self::assertSame([], $this->f->sql->rows("sis_course_versions"));
            self::assertSame([], $this->f->sql->rows("sis_section_course_versions"));
        }
        $this->f->connection->pdo->exec("DROP TRIGGER fail_binding_audit");
        $this->service->commit($this->section->id, $review["review_token"]);
        self::assertCount(2, $this->f->sql->rows("sis_history_events"));
    }

    public function testEarlyDropRemovesCurrentCreditReferenceButRetainsProtectedBeforeImageAndSectionBinding(): void
    {
        $version = $this->publish();
        $this->bind($version);
        $student = $this->f->student();
        $this->f->service->register($student->id, $this->section->id);
        $review = $this->f->service->reviewDrop($student->id, $this->section->id);
        $this->f->service->drop($student->id, $this->section->id);
        self::assertSame([], $this->f->sql->rows("sis_credit_course_versions"));
        self::assertCount(1, $this->f->sql->rows("sis_section_course_versions"));
        $receipt = $this->f->sql->rows("sis_course_drop_receipts")[0];
        self::assertStringContainsString(
            $version->value,
            $this->f->dropCipher->open($receipt["encrypted_content"]),
        );
        self::assertNotEmpty($review);
    }
    public function testEarlierSchemaDoesNotBorrowTodayDescriptionOrTranscriptTitle(): void
    {
        $operation = EntityId::generate();
        $stream = new StreamReference(
            new \Domain\Catalog\ValueObject\CoursePublicationId($this->course->value),
            "course_publication",
        );
        $event = \Domain\Catalog\Event\CourseDefinitionWasPublished::occur(
            $stream->aggregateId,
            [
                "version_id" => $operation->value,
                "previous_version_id" => null,
                "definition" => [
                    "code" => "MATH.101",
                    "title" => "Algebra",
                    "credits_milli" => 3000,
                    "prerequisites" => [],
                ],
            ],
            ["__aggregate_type" => "course_publication"],
        );
        $this->history->append(
            $stream,
            $operation,
            0,
            new \Domain\Shared\EventSourcing\EventContext(
                $this->f->access->actor,
                $this->f->clock->now(),
                $operation,
                reason: "Fictional earlier publication",
            ),
            [$event],
        );
        $bytes = $this->f->sql->rows("sis_history_events")[0];
        $this->bind($operation);
        $revision = $this->editor->view("courses", $this->course)["revision"];
        $this->editor->courseText(
            $this->course,
            new \Domain\Catalog\ValueObject\CourseText("New short title", "New description"),
            $revision,
        );
        $this->publish();
        $bound = $this->service->show($this->section->id)["bound"];
        self::assertSame(1, $bound["schema_version"]);
        self::assertArrayNotHasKey("description", $bound["definition"]);
        $rows = new SqlCampusRepository($this->f->sql)->sections($this->f->term, "", null)["items"];
        self::assertNull($rows[0]["description"]);
        $student = $this->f->student();
        $e = $this->f->service->register($student->id, $this->section->id);
        self::assertSame(
            "Algebra",
            $this->f->sql->one("sis_academic_credits", ["source_id" => $e->id->value])["course_title"],
        );
        self::assertSame($bytes, $this->f->sql->one("sis_history_events", ["id" => $bytes["id"]]));
    }

    public function testRetainedReportingFieldsAndWorkspaceReadsStayDistinctFromCurrentCatalog(): void
    {
        $version = $this->publish();
        $this->bind($version);
        $revision = $this->editor->view("courses", $this->course)["revision"];
        $this->editor->course(new Course($this->course, "MATH.201", "Advanced Algebra", 4000), $revision);
        $workspace = new \Infrastructure\Persistence\Repository\SqlWorkspaceRepository($this->f->sql, $this->f->gradeCatalog());
        $rows = $workspace->records("sections", null, "Algebra", null)["items"];
        self::assertSame("Algebra", $rows[0]["course_title"]);
        self::assertSame("MATH.101", $rows[0]["course_code"]);
        $source = new \Infrastructure\Reporting\SqlRelationalReportSource($this->f->sql);
        $query = new \Domain\Reporting\ValueObject\ReportQuery(
            [
                "dataset" => "sections",
                "columns" => [
                    ["field" => "retained_course_version_id", "label" => "Version", "aggregate" => "none"],
                    ["field" => "retained_course_title", "label" => "Retained", "aggregate" => "none"],
                    ["field" => "course_title", "label" => "Current", "aggregate" => "none"],
                ],
                "filters" => null,
                "having" => null,
                "sort" => [],
            ],
            $source->datasets()["sections"],
        );
        $rows = $source->execute($query, 0);
        self::assertCount(1, $rows);
        self::assertSame([$version->value, "Algebra", "Advanced Algebra"], array_values($rows[0]));
    }

    public function testCompetingReviewsAndCrossCourseVersionsCannotReplaceAnAcceptedDefinition(): void
    {
        $version = $this->publish();
        $first = $this->service->review($this->section->id, $version, "Fictional first review");
        $second = $this->service->review($this->section->id, $version, "Fictional competing review");
        $this->service->commit($this->section->id, $first["review_token"]);
        $events = $this->f->sql->rows("sis_history_events");
        try {
            $this->service->commit($this->section->id, $second["review_token"]);
            self::fail("A competing binding was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($events, $this->f->sql->rows("sis_history_events"));
        }
        $other = $this->f->section();
        $this->expectException(\Domain\Shared\Exception\RecordNotFoundException::class);
        $this->service->review($other->id, $version, "Fictional wrong-course selection");
    }

    public function testGroupRegistrationReviewUsesTheRetainedDefinitionAndDetectsANewBinding(): void
    {
        $version = $this->publish();
        $student = $this->f->student();
        $batch = new \Domain\Registration\Service\RegistrationBatchService(
            new \Infrastructure\Persistence\Repository\SqlRegistrationBatchRepository($this->f->sql),
            $this->f->registration,
            $this->f->admissions,
            $this->f->service,
            $this->f->access,
            $this->f->transactions,
            $this->f->audit,
            $this->f->clock,
            new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($this->f->sql),
        );
        $selection = new \Domain\Registration\ValueObject\RegistrationBatchSelection(
            $this->section->id,
            $student->id,
        );
        $old = $batch->review($selection);
        $this->bind($version);
        try {
            $batch->commit(new EntityId($old["operation_id"]), $selection, $old["revision"]);
            self::fail("A new binding bypassed the group review.");
        } catch (ConcurrentModificationException) {
            self::assertNull($this->f->registration->enrollment($student->id, $this->section->id));
        }
        $review = $batch->review($selection);
        self::assertSame($version->value, $review["section"]["course_version_id"]);
        $revision = $this->editor->view("courses", $this->course)["revision"];
        $this->editor->course(new Course($this->course, "MATH.201", "Advanced Algebra", 4000), $revision);
        self::assertSame("Algebra", $batch->review($selection)["section"]["course_title"]);
        $batch->commit(new EntityId($review["operation_id"]), $selection, $review["revision"]);
        self::assertSame("Algebra", $this->f->sql->rows("sis_academic_credits")[0]["course_title"]);
    }

    public function testInconsistentCachedDefinitionCannotBeReusedByAnotherBinding(): void
    {
        $version = $this->publish();
        $this->bind($version);
        $other = $this->newSection("MATH.101.B");
        $review = $this->service->review($other->id, $version, "Fictional review");
        $this->f->sql->update(
            "sis_course_versions",
            ["title" => "Inconsistent projection"],
            ["id" => $version->value],
        );
        $events = $this->f->sql->rows("sis_history_events");
        try {
            $this->service->commit($other->id, $review["review_token"]);
            self::fail("An inconsistent cache was reused.");
        } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
            self::assertSame($events, $this->f->sql->rows("sis_history_events"));
            self::assertCount(1, $this->f->sql->rows("sis_section_course_versions"));
        }
    }

    public function testBindingSchemaRejectsAdditionalFactsInvalidReferencesAndUnregisteredVersions(): void
    {
        $stream = new SectionCourseBindingId($this->section->id->value);
        $valid = ["course_id" => $this->course->value, "course_version_id" => EntityId::generate()->value];
        $cases = [
            [$valid + ["unreviewed_policy" => "x"], 1],
            [array_replace($valid, ["course_version_id" => "not-a-reference"]), 1],
            [$valid, 2],
        ];
        foreach ($cases as [$payload, $schema]) {
            try {
                new HistoricalSchemaRegistry()->describe(
                    \Domain\Registration\Event\SectionCourseVersionWasBound::occur($stream, $payload, [
                        "__aggregate_type" => "section_course_binding",
                        "schema_version" => $schema,
                    ]),
                );
                self::fail("An incompatible binding schema was accepted.");
            } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
                self::assertSame([], $this->f->sql->rows("sis_history_events"));
            }
        }
    }
    public function testEarlierFullDefinitionReviewCanCommitAndRetryAlongsideCompactProof(): void
    {
        $review = $this->service->review($this->section->id, $this->publish(), "Fictional offering decision");
        $proof = json_decode($this->f->dropCipher->open($review["review_token"]), true, flags: JSON_THROW_ON_ERROR);
        $proof["kind"] = "section-course-binding-review.v1";
        $proof["selected"] = $review["selected"];
        unset($proof["definition_hash"], $proof["correlation_id"]);
        $oldToken = $this->f->dropCipher->seal(json_encode($proof, JSON_THROW_ON_ERROR));
        $receipt = $this->service->commit($this->section->id, $oldToken);
        self::assertSame($receipt, $this->service->commit($this->section->id, $review["review_token"]));
        self::assertCount(2, $this->f->sql->rows("sis_history_events"));
        self::assertCount(1, $this->f->sql->rows("sis_section_course_versions"));
    }
}
