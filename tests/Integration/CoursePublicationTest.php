<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Codefy\Domain\EventSourcing\EventStream;
use Domain\Catalog\Course;
use Domain\Catalog\CoursePublication;
use Domain\Catalog\Event\CourseDefinitionWasPublished;
use Domain\Catalog\Service\CatalogEditor;
use Domain\Catalog\Service\CoursePublicationService;
use Domain\Catalog\ValueObject\CoursePublicationId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlCatalogWorkflowRepository;
use PHPUnit\Framework\TestCase;

final class CoursePublicationTest extends TestCase
{
    private SisFixture $f;
    private EntityId $course;
    private SqlHistoricalEventStore $history;
    private CoursePublicationService $service;
    private CatalogEditor $editor;

    protected function setUp(): void
    {
        $this->f = $f = new SisFixture();
        $this->course = EntityId::generate();
        $f->registration->addCourse(new Course($this->course, "MATH.101", "Algebra", 3000));
        $this->history = new SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new ProtectedEventCodec(EventStoreFixture::config($f->connection->queryBuilder())),
            new HistoricalSchemaRegistry(),
        );
        $catalog = new SqlCatalogRevisionRepository($f->sql);
        $this->service = new CoursePublicationService(
            $catalog,
            new SqlCatalogWorkflowRepository($f->sql),
            $this->history,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
            new \Infrastructure\Persistence\Repository\SqlCourseTextProjection($f->sql),
        );
        $this->editor = new CatalogEditor(
            $catalog,
            $f->registration,
            $f->access,
            $f->transactions,
            $f->audit,
        );
    }

    private function change(): void
    {
        $revision = $this->editor->view("courses", $this->course)["revision"];
        $this->editor->course(new Course($this->course, "MATH.101", "Advanced Algebra", 4000), $revision);
    }

    public function testPublicationIsExplicitAndEarlierDefinitionsSurviveLaterDraftAndPrerequisiteChanges(): void
    {
        self::assertSame([], $this->service->versions($this->course)["items"]);
        $required = EntityId::generate();
        $this->f->registration->addCourse(new Course($required, "MATH.090", "Foundations", 1000));
        $this->f->registration->addPrerequisite($this->course, $required);
        $review = $this->service->review($this->course, "Initial fictional publication");
        self::assertSame([], $this->f->sql->rows("sis_history_events"));
        $receipt = $this->service->publish($this->course, $review["review_token"]);
        self::assertSame(1, $receipt["last_version"]);
        $first = $this->service->versions($this->course)["items"][0];
        self::assertSame("Algebra", $first["definition"]["title"]);
        self::assertSame([$required->value], $first["definition"]["prerequisites"]);
        $this->change();
        $this->f->registration->removePrerequisite($this->course, $required);
        self::assertSame($first, $this->service->versions($this->course)["items"][0]);
        $second = $this->service->review($this->course, "Updated fictional publication");
        $this->service->publish($this->course, $second["review_token"]);
        $versions = $this->service->versions($this->course)["items"];
        self::assertSame($first, $versions[0]);
        self::assertSame("Advanced Algebra", $versions[1]["definition"]["title"]);
        self::assertSame(4000, $versions[1]["definition"]["credits_milli"]);
        self::assertSame([], $versions[1]["definition"]["prerequisites"]);
        $stream = new StreamReference(new CoursePublicationId($this->course->value), "course_publication");
        $events = array_map(static fn($stored) => $stored->event, $this->history->page($stream)->events);
        $restored = CoursePublication::reconstituteFromEventStream(
            new EventStream($stream->aggregateId, $events),
        );
        self::assertSame($second["operation_id"], $restored->versionId());
        self::assertSame(2, $restored->playhead());
        self::assertSame($first["version_id"], $events[1]->payload()["previous_version_id"]);
        $raw = json_encode($this->f->sql->rows("sis_history_events"), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString("Initial fictional", $raw);
        self::assertStringNotContainsString("Algebra", $raw);
        self::assertSame([], $this->f->sql->rows("sis_academic_credits"));
    }

    public function testRetryReturnsOriginalReceiptEvenAfterLaterPublicationAndDraftEditsWithoutDuplicateAudit(): void
    {
        $review = $this->service->review($this->course, "Fictional first review");
        $receipt = $this->service->publish($this->course, $review["review_token"]);
        $this->change();
        $later = $this->service->review($this->course, "Fictional second review");
        $this->service->publish($this->course, $later["review_token"]);
        $before = $this->f->sql->rows("sis_audit");
        self::assertSame($receipt, $this->service->publish($this->course, $review["review_token"]));
        self::assertCount(2, $this->f->sql->rows("sis_history_events"));
        self::assertSame($before, $this->f->sql->rows("sis_audit"));
    }

    public function testStaleDraftReviewRollsBackTheTentativeEventHeadAndReceipt(): void
    {
        $review = $this->service->review($this->course, "Fictional review");
        $this->change();
        try {
            $this->service->publish($this->course, $review["review_token"]);
            self::fail("A stale draft review was published.");
        } catch (ConcurrentModificationException) {
            foreach (["sis_history_events", "sis_history_streams", "sis_history_operations"] as $table) {
                self::assertSame([], $this->f->sql->rows($table));
            }
        }
    }

    public function testCompetingPublicationReviewCannotOverwriteAnAcceptedVersion(): void
    {
        $first = $this->service->review($this->course, "First fictional review");
        $second = $this->service->review($this->course, "Other fictional review");
        $this->service->publish($this->course, $first["review_token"]);
        $this->expectException(ConcurrentModificationException::class);
        $this->service->publish($this->course, $second["review_token"]);
    }

    public function testCurrentPermissionsAndReviewOwnershipAreRequiredForEveryCall(): void
    {
        $review = $this->service->review($this->course, "Fictional review");
        $this->f->access->actor = $this->f->user();
        try {
            $this->service->publish($this->course, $review["review_token"]);
            self::fail("Another staff member used a publication review.");
        } catch (AccessDeniedException) {
            self::assertSame([], $this->f->sql->rows("sis_history_events"));
        }
        $this->f->access->staff = false;
        $cases1 = [
                fn() => $this->service->review($this->course, "Fictional review"),
                fn() => $this->service->publish($this->course, $review["review_token"]),
                fn() => $this->service->versions($this->course),
            ];
        foreach ($cases1 as $call) {
            try {
                $call();
                self::fail("Unprivileged catalog publication access succeeded.");
            } catch (AccessDeniedException) {
                self::assertSame([], $this->f->sql->rows("sis_history_events"));
            }
        }
    }

    public function testTamperedAndCrossCourseReviewTokensAreRejected(): void
    {
        $review = $this->service->review($this->course, "Fictional review");
        $cases2 = [
                [$this->course, substr($review["review_token"], 0, -8) . "BADTOKEN"],
                [EntityId::generate(), $review["review_token"]],
            ];
        foreach ($cases2 as [$id, $token]) {
            try {
                $this->service->publish($id, $token);
                self::fail("An invalid publication token was accepted.");
            } catch (InvalidArgumentException) {
                self::assertSame([], $this->f->sql->rows("sis_history_events"));
            }
        }
    }

    public function testLaterAuditFailureRollsBackPublicationAndAllowsAnUnchangedRetry(): void
    {
        $review = $this->service->review($this->course, "Fictional review");
        $this->f->connection->pdo->exec(
            "CREATE TRIGGER fictional_audit_failure BEFORE INSERT ON sis_audit " .
                "BEGIN SELECT RAISE(ABORT, 'Fictional audit failure'); END",
        );
        try {
            $this->service->publish($this->course, $review["review_token"]);
            self::fail("A failed audit committed a publication.");
        } catch (PDOException) {
            self::assertSame([], $this->f->sql->rows("sis_history_events"));
            self::assertSame([], $this->f->sql->rows("sis_history_operations"));
        }
        $this->f->connection->pdo->exec("DROP TRIGGER fictional_audit_failure");
        self::assertSame(1, $this->service->publish($this->course, $review["review_token"])["last_version"]);
    }

    public function testPublicationSchemasRejectUnknownFieldsAndAggregateReplayRejectsBrokenRevisionLinks(): void
    {
        $review = $this->service->review($this->course, "Fictional review");
        $this->service->publish($this->course, $review["review_token"]);
        $stream = new StreamReference(new CoursePublicationId($this->course->value), "course_publication");
        $first = $this->history->page($stream)->events[0]->event;
        $payload = $first->payload();
        $payload["definition"]["unexpected"] = "Fictional unreviewed data";
        try {
            new HistoricalSchemaRegistry()->describe(
                CourseDefinitionWasPublished::occur($stream->aggregateId, $payload, [
                    "__aggregate_type" => "course_publication",
                ]),
            );
            self::fail("An unknown course-definition field was accepted.");
        } catch (CorruptHistoryException) {
            self::assertCount(1, $this->f->sql->rows("sis_history_events"));
        }
        $payload = $first->payload();
        $payload["previous_version_id"] = EntityId::generate()->value;
        $bad = CourseDefinitionWasPublished::occur($stream->aggregateId, $payload, [
            "__aggregate_type" => "course_publication",
        ])->withPlayhead(2);
        $this->expectException(CorruptHistoryException::class);
        CoursePublication::reconstituteFromEventStream(new EventStream($stream->aggregateId, [$first, $bad]));
    }

    public function testVersionsUseBoundedStablePagesAndExposeOnlyCatalogDefinitions(): void
    {
        for ($i = 0; $i < 22; $i++) {
            $review = $this->service->review($this->course, "Fictional publication " . $i);
            $this->service->publish($this->course, $review["review_token"]);
        }
        $first = $this->service->versions($this->course);
        self::assertCount(20, $first["items"]);
        self::assertNotNull($first["next_cursor"]);
        $review = $this->service->review($this->course, "Fictional later publication");
        $this->service->publish($this->course, $review["review_token"]);
        $next = $this->service->versions($this->course, $first["next_cursor"]);
        self::assertCount(2, $next["items"]);
        self::assertNull($next["next_cursor"]);
        self::assertSame(22, $next["through_version"]);
        $json = json_encode($first, JSON_THROW_ON_ERROR);
        foreach (["actor_id", "reason", "metadata", "sealed_event", "Fictional publication"] as $name) {
            self::assertStringNotContainsString($name, $json);
        }
    }
    private function saveText(?string $title, ?string $description): void
    {
        $this->editor->courseText(
            $this->course,
            new \Domain\Catalog\ValueObject\CourseText($title, $description),
            $this->editor->view("courses", $this->course)["revision"],
        );
    }

    public function testV1EventsAndReceiptsSurviveV2PublicationWithoutAddingInventedOldFields(): void
    {
        $operation = EntityId::generate();
        $definition = [
            "code" => "MATH.101",
            "title" => "Algebra",
            "credits_milli" => 3000,
            "prerequisites" => [],
        ];
        $context = new \Domain\Shared\EventSourcing\EventContext(
            $this->f->access->actor,
            new DateTimeImmutable(
                $this->f->clock->now()->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d\TH:i:s.u\Z"),
            ),
            $operation,
            reason: "Fictional original publication",
        );
        $stream = new StreamReference(new CoursePublicationId($this->course->value), "course_publication");
        $event = CourseDefinitionWasPublished::occur(
            $stream->aggregateId,
            ["version_id" => $operation->value, "previous_version_id" => null, "definition" => $definition],
            ["__aggregate_type" => "course_publication"],
        );
        $receipt = $this->history->append($stream, $operation, 0, $context, [$event]);
        $oldToken = $this->f->dropCipher->seal(
            json_encode(
                [
                    "kind" => "course-publication-review.v1",
                    "course_id" => $this->course->value,
                    "operation_id" => $operation->value,
                    "actor_id" => $this->f->access->actor->value,
                    "expected_version" => 0,
                    "previous_version_id" => null,
                    "definition" => $definition,
                    "effective_at" => $context->describe()["effective_at"],
                    "reason" => $context->reason,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
        $before = $this->f->sql->rows("sis_history_events")[0];
        $this->saveText("Algebra I", "First paragraph.\nSecond paragraph.");
        $review = $this->service->review($this->course, "Fictional v2 publication");
        $this->service->publish($this->course, $review["review_token"]);
        self::assertSame($receipt, $this->service->publish($this->course, $oldToken));
        self::assertSame($before, $this->f->sql->rows("sis_history_events", ["id" => $before["id"]])[0]);
        $page = $this->service->versions($this->course);
        self::assertSame(1, $page["items"][0]["schema_version"]);
        self::assertArrayNotHasKey("transcript_title", $page["items"][0]["definition"]);
        self::assertSame(2, $page["items"][1]["schema_version"]);
        self::assertSame("Algebra I", $page["items"][1]["definition"]["transcript_title"]);
        $events = array_map(static fn($e) => $e->event, $this->history->page($stream)->events);
        self::assertSame([1, 2], array_map(static fn($e) => $e->metaParam("schema_version", 1), $events));
        $restored = CoursePublication::reconstituteFromEventStream(
            new EventStream($stream->aggregateId, $events),
        );
        self::assertSame($review["operation_id"], $restored->versionId());
    }

    public function testPendingOldReviewCannotCreateANewIncompleteDefinitionAfterUpgrade(): void
    {
        $token = $this->f->dropCipher->seal(
            json_encode(
                [
                    "kind" => "course-publication-review.v1",
                    "course_id" => $this->course->value,
                    "operation_id" => EntityId::generate()->value,
                    "actor_id" => $this->f->access->actor->value,
                    "expected_version" => 0,
                    "previous_version_id" => null,
                    "definition" => [
                        "code" => "MATH.101",
                        "title" => "Algebra",
                        "credits_milli" => 3000,
                        "prerequisites" => [],
                    ],
                    "effective_at" => $this->f->clock->now()->format("Y-m-d\TH:i:s.u\Z"),
                    "reason" => "Fictional pending review",
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
        $this->expectException(ConcurrentModificationException::class);
        try {
            $this->service->publish($this->course, $token);
        } finally {
            self::assertSame([], $this->f->sql->rows("sis_history_events"));
            self::assertSame([], $this->f->sql->rows("sis_course_descriptions"));
        }
    }

    public function testDraftTextStaysPrivateUntilPublicationAndRecordedTitlesSurviveFurtherChanges(): void
    {
        $f = $this->f;
        $section = new \Domain\Registration\Section(
            EntityId::generate(),
            $this->course,
            $f->term,
            null,
            "MATH.101.A",
            20,
            true,
            true,
            new \Domain\Finance\ValueObject\Money(0, "USD"),
        );
        $f->registration->addSection($section);
        $portal = new \Infrastructure\Persistence\Repository\SqlCampusRepository($f->sql);
        $this->saveText("Algebra I", "Published paragraph.\n<script>fictional</script>");
        self::assertNull($portal->sections($f->term, "MATH.101", null)["items"][0]["description"]);
        $review = $this->service->review($this->course, "Fictional approved description");
        $this->service->publish($this->course, $review["review_token"]);
        self::assertSame(
            "Published paragraph.\n<script>fictional</script>",
            $portal->sections($f->term, "MATH.101", null)["items"][0]["description"],
        );
        $student = $f->student();
        $f->service->register($student->id, $section->id);
        $original = $f->sql->one("sis_academic_credits", ["student_id" => $student->id->value]);
        self::assertSame("Algebra I", $original["course_title"]);
        $this->saveText("Algebra II", "Unpublished draft description");
        self::assertSame(
            "Published paragraph.\n<script>fictional</script>",
            $portal->sections($f->term, "MATH.101", null)["items"][0]["description"],
        );
        $review = $this->service->review($this->course, "Fictional revised description");
        $this->service->publish($this->course, $review["review_token"]);
        self::assertSame($original, $f->sql->one("sis_academic_credits", ["id" => $original["id"]]));
        $nextStudent = $f->student();
        $f->service->register($nextStudent->id, $section->id);
        self::assertSame(
            "Algebra II",
            $f->sql->one("sis_academic_credits", ["student_id" => $nextStudent->id->value])["course_title"],
        );
        $projection = $f->sql->one("sis_course_descriptions", ["course_id" => $this->course->value]);
        $f->transactions->run(function () use ($f, $projection): void {
            $f->sql->lock("sis_courses", $this->course->value, "id");
            $f->sql->execute("DELETE FROM sis_course_descriptions WHERE course_id = ?", [
                $this->course->value,
            ]);
            $stream = new StreamReference(
                new CoursePublicationId($this->course->value),
                "course_publication",
            );
            foreach ($this->history->page($stream)->events as $stored) {
                $p = $stored->event->payload();
                new \Infrastructure\Persistence\Repository\SqlCourseTextProjection($f->sql)->apply(
                    $this->course,
                    \Domain\Catalog\ValueObject\CourseText::fromArray($p["definition"]),
                    new EntityId($p["version_id"]),
                    $stored->event->playhead(),
                );
            }
            self::assertSame(
                $projection["publication_version_id"],
                $f->sql->one("sis_course_descriptions", ["course_id" => $this->course->value])[
                    "publication_version_id"
                ],
            );
        });
    }

    public function testTextEditsParticipateInSharedRevisionChecksAndRejectAStalePublicationReview(): void
    {
        $oldRevision = $this->editor->view("courses", $this->course)["revision"];
        $review = $this->service->review($this->course, "Fictional review");
        $this->saveText("Algebra I", "First description");
        try {
            $this->editor->courseText(
                $this->course,
                new \Domain\Catalog\ValueObject\CourseText("Algebra II"),
                $oldRevision,
            );
            self::fail("A stale text edit was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame("Algebra I", $this->editor->view("courses", $this->course)["transcript_title"]);
        }
        $this->expectException(ConcurrentModificationException::class);
        try {
            $this->service->publish($this->course, $review["review_token"]);
        } finally {
            self::assertSame([], $this->f->sql->rows("sis_history_events"));
            self::assertNull(
                $this->f->sql->one("sis_course_descriptions", ["course_id" => $this->course->value])[
                    "publication_version_id"
                ],
            );
        }
    }

    public function testBlankTextIsExplicitlyUnspecifiedAndInvalidTextCannotBeSaved(): void
    {
        $this->saveText("   ", "\n ");
        self::assertNull($this->editor->view("courses", $this->course)["transcript_title"]);
        $cases3 = [
                [str_repeat("x", 81), null],
                ["Bad\ntitle", null],
                [null, str_repeat("x", 10001)],
                [null, "Bad\x00description"],
                [null, "Bad\x80"],
            ];
        foreach ($cases3 as [$title, $description]) {
            try {
                $this->saveText($title, $description);
                self::fail("Invalid course text was accepted.");
            } catch (InvalidArgumentException) {
                self::assertSame([], $this->f->sql->rows("sis_course_descriptions"));
            }
        }
        $this->f->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $this->saveText("Algebra I", null);
    }

    public function testCourseTextSchemaVersionsAreStrictlyRegistered(): void
    {
        $review = $this->service->review($this->course, "Fictional schema review");
        $payload = [
            "version_id" => $review["operation_id"],
            "previous_version_id" => null,
            "definition" => $review["definition"],
        ];
        $stream = new StreamReference(new CoursePublicationId($this->course->value), "course_publication");
        foreach ([1, 3] as $version) {
            try {
                new HistoricalSchemaRegistry()->describe(
                    CourseDefinitionWasPublished::occur($stream->aggregateId, $payload, [
                        "__aggregate_type" => "course_publication",
                        "schema_version" => $version,
                    ]),
                );
                self::fail("A course payload was accepted under an incompatible schema.");
            } catch (CorruptHistoryException) {
                self::assertSame([], $this->f->sql->rows("sis_history_events"));
            }
        }
        unset($payload["definition"]["description"]);
        $this->expectException(CorruptHistoryException::class);
        new HistoricalSchemaRegistry()->describe(
            CourseDefinitionWasPublished::occur($stream->aggregateId, $payload, [
                "__aggregate_type" => "course_publication",
                "schema_version" => 2,
            ]),
        );
    }

    public function testCurrentPublishedCourseTextIsAvailableToReportingWithoutExposingDraftText(): void
    {
        $f = $this->f;
        $f->registration->addSection(
            new \Domain\Registration\Section(
                EntityId::generate(),
                $this->course,
                $f->term,
                null,
                "MATH.101.A",
                20,
                true,
                true,
                new \Domain\Finance\ValueObject\Money(0, "USD"),
            ),
        );
        $this->saveText("Algebra I", "Published description");
        $review = $this->service->review($this->course, "Fictional approved publication");
        $this->service->publish($this->course, $review["review_token"]);
        $this->saveText("Draft title", "Private unapproved draft");
        $source = new \Infrastructure\Reporting\SqlRelationalReportSource($f->sql);
        $query = new \Domain\Reporting\ValueObject\ReportQuery(
            [
                "dataset" => "sections",
                "columns" => [
                    [
                        "field" => "published_transcript_title",
                        "label" => "Transcript title",
                        "aggregate" => "none",
                    ],
                    [
                        "field" => "published_course_description",
                        "label" => "Description",
                        "aggregate" => "none",
                    ],
                ],
                "filters" => null,
                "having" => null,
                "sort" => [],
            ],
            $source->datasets()["sections"],
        );
        $rows = $source->execute($query, 0);
        self::assertCount(1, $rows);
        self::assertSame("Algebra I", $rows[0]["c0"]);
        self::assertSame("Published description", $rows[0]["c1"]);
    }

    public function testNewTransferCreditsRetainThePublishedTranscriptTitle(): void
    {
        $f = $this->f;
        $this->saveText("Transfer Algebra", "Published transfer description");
        $review = $this->service->review($this->course, "Fictional approved publication");
        $this->service->publish($this->course, $review["review_token"]);
        $student = $f->student();
        $grade = EntityId::generate();
        $f->sql->insert("sis_grades", [
            "id" => $grade->value,
            "code" => "TR",
            "points_milli" => 0,
            "passes" => 1,
            "counts_gpa" => 0,
        ]);
        $transfer = new \Domain\Transfer\Service\TransferService(
            new \Infrastructure\Persistence\Repository\SqlTransferRepository($f->sql, $f->gradeCatalog()),
            $f->registration,
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->audit,
            new \Infrastructure\Persistence\Repository\SqlTransferCatalogRepository(
                $f->sql,
                $f->gradeCatalog(),
            ),
            $f->transferCredits(),
        );
        $institution = $transfer->addInstitution("EXT", "Fictional College");
        $external = $transfer->addExternalCourse($institution, "MATH.101", "External Algebra");
        $equivalency = new \Domain\Transfer\TransferEquivalency(
            EntityId::generate(),
            $institution,
            $external,
            $this->course,
            new \Domain\Shared\ValueObject\DateRange(new DateTimeImmutable("2020-01-01")),
            2,
            3000,
            "TR",
        );
        $transfer->equate($equivalency);
        $catalog = new \Infrastructure\Persistence\Repository\SqlTransferCatalogRepository(
            $f->sql,
            $f->gradeCatalog(),
        );
        $revision = \Domain\Transfer\ValueObject\TransferRevision::of(
            "equivalencies",
            $catalog->get("equivalencies", $equivalency->id),
        );
        $transfer->award(
            $student->id,
            $equivalency->id,
            $institution,
            $f->term,
            new DateTimeImmutable("2026-05-01"),
            2,
            $revision,
        );
        $before = $transfer->transcript($student->id);
        self::assertSame("Transfer Algebra", $before[0]["course_title"]);
        $this->saveText("Later title", null);
        $review = $this->service->review($this->course, "Fictional later publication");
        $this->service->publish($this->course, $review["review_token"]);
        self::assertSame($before, $transfer->transcript($student->id));
    }
    public function testCloningCopiesReviewedTextOnlyIntoAnInactiveDraft(): void
    {
        $f = $this->f;
        $this->saveText("Algebra I", "Fictional retained description");
        $review = $this->service->review($this->course, "Fictional publication");
        $this->service->publish($this->course, $review["review_token"]);
        $clones = new \Domain\Catalog\Service\CourseCloneService(
            new SqlCatalogWorkflowRepository($f->sql),
            new SqlCatalogRevisionRepository($f->sql),
            $f->registration,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
        );
        $plan = $clones->review($this->course);
        $id = new EntityId($plan["operation_id"]);
        $clones->commit(
            $this->course,
            new Course($id, "MATH.102", "Algebra II", 3000, false),
            [],
            $plan["revision"],
        );
        $draft = $this->editor->view("courses", $id);
        self::assertSame("Algebra I", $draft["transcript_title"]);
        self::assertSame("Fictional retained description", $draft["description"]);
        self::assertFalse((bool) $draft["active"]);
        self::assertNull(
            $f->sql->one("sis_course_descriptions", ["course_id" => $id->value])["publication_version_id"],
        );
        self::assertSame([], $this->service->versions($id)["items"]);
        self::assertCount(1, $f->sql->rows("sis_history_events"));
    }
}
