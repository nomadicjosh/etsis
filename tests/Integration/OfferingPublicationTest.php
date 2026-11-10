<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/CatalogWorkflowFixture.php";

use Domain\Catalog\Course;
use Domain\Catalog\Service\OfferingService;
use Domain\Catalog\ValueObject\OfferingPlan;
use Domain\Catalog\ValueObject\CourseText;
use Domain\Registration\Section;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class OfferingPublicationTest extends TestCase
{
    private CatalogWorkflowFixture $c;
    private OfferingService $service;
    private OfferingPlan $plan;
    private EntityId $id;
    private EntityId $version;
    /** @var array<string, EntityId> */
    private array $choices;

    protected function setUp(): void
    {
        $this->c = $c = new CatalogWorkflowFixture();
        $f = $c->sis;
        $source = $c->plan()->sections[0];
        $f->sql->update(
            "sis_courses",
            ["code" => "MATH.101", "title" => "Algebra"],
            ["id" => $source->courseId->value],
        );
        $this->plan = new OfferingPlan(
            $f->term,
            $source,
            new Section(
                EntityId::generate(),
                $source->courseId,
                $f->term,
                null,
                "MATH.101.26FA.B",
                20,
                false,
                false,
                $source->fee,
            ),
        );
        $this->service = $c->offerings();
        $review = $this->service->review($this->plan);
        $this->id = new EntityId($review["operation_id"]);
        $this->service->save($this->id, $this->plan, $review["revision"]);
        $editor = new \Domain\Catalog\Service\CatalogEditor(
            new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($f->sql),
            $f->registration,
            $f->access,
            $f->transactions,
            $f->audit,
        );
        $editor->courseText(
            $source->courseId,
            new CourseText("Algebra I", "Fictional original description"),
            $editor->view("courses", $source->courseId)["revision"],
        );
        $this->version = $this->publishCourse();
        $this->choices = [];
        foreach ($this->plan->sections as $s) {
            $this->choices[$s->id->value] = $this->version;
        }
    }

    private function publishCourse(): EntityId
    {
        $pub = $this->c->publications();
        $course = $this->plan->sections[0]->courseId;
        $review = $pub->review($course, "Fictional course approval");
        return new EntityId($pub->publish($course, $review["review_token"])["operation_id"]);
    }

    private function review(): array
    {
        return $this->service->reviewPublication($this->id, $this->choices, "Fictional offering approval");
    }

    private function digest(string $table): string
    {
        return hash("sha256", json_encode($this->c->sis->sql->rows($table), JSON_THROW_ON_ERROR));
    }

    public function testReviewDoesNotWriteAndPublicationRetainsDefinitionsAndActivatesAtomically(): void
    {
        $f = $this->c->sis;
        $events = $this->digest("sis_history_events");
        $setup = $this->service->preparePublication($this->id);
        self::assertCount(2, $setup["sections"]);
        self::assertNull($setup["sections"][0]["course_version_id"]);
        $review = $this->review();
        self::assertSame($events, $this->digest("sis_history_events"));
        self::assertSame([], $f->sql->rows("sis_section_course_versions"));
        self::assertSame($this->version->value, $review["sections"][0]["course_version_id"]);
        $receipt = $this->service->publish($this->id, $review["review_token"]);
        self::assertNotNull($receipt["published_at"]);
        foreach ($this->plan->sections as $index => $s) {
            $section = $f->registration->section($s->id);
            self::assertTrue($section->active);
            self::assertSame($s->webRegistration, $section->webRegistration);
            self::assertSame(
                $this->version->value,
                $receipt["review"]["sections"][$index]["course_version_id"],
            );
        }
        self::assertCount(1, $f->sql->rows("sis_course_versions"));
        self::assertCount(2, $f->sql->rows("sis_section_course_versions"));
        self::assertSame(
            [
                "course-definition-was-published",
                "section-course-version-was-bound",
                "section-definition-was-published",
                "section-offering-version-was-selected",
                "section-course-version-was-bound",
                "section-definition-was-published",
                "section-offering-version-was-selected",
            ],
            array_column($f->sql->rows("sis_history_events"), "event_type"),
        );
        self::assertSame([], $f->sql->rows("sis_academic_credits"));
        self::assertSame([], $f->sql->rows("sis_student_accounts"));
    }

    public function testChosenVersionSurvivesLaterCatalogEditsAndPublicationAndFeedsNewAcademicCredit(): void
    {
        $f = $this->c->sis;
        $review = $this->review();
        $course = $this->plan->sections[0]->courseId;
        $f->sql->update(
            "sis_courses",
            ["title" => "Later Algebra", "credits_milli" => 4000],
            ["id" => $course->value],
        );
        $this->publishCourse();
        $receipt = $this->service->publish($this->id, $review["review_token"]);
        self::assertSame("Algebra", $receipt["review"]["sections"][0]["course_title"]);
        self::assertSame(3000, $receipt["review"]["sections"][0]["credits_milli"]);
        $student = $f->student();
        $f->service->register($student->id, $this->plan->sections[0]->id);
        $credit = $f->sql->rows("sis_academic_credits")[0];
        self::assertSame("Algebra I", $credit["course_title"]);
        self::assertSame(3000, (int) $credit["attempted_milli"]);
        self::assertSame(
            $this->version->value,
            $f->sql->one("sis_credit_course_versions", ["id" => $credit["id"]])["course_version_id"],
        );
    }

    public function testEachUnboundDraftRequiresAnExplicitChoiceAndExtraneousSelectionsAreRejected(): void
    {
        $cases1 = [
            [],
            [$this->plan->sections[0]->id->value => $this->version],
            $this->choices + [EntityId::generate()->value => $this->version],
        ];
        foreach ($cases1 as $choices) {
            try {
                $this->service->reviewPublication($this->id, $choices, "Fictional offering approval");
                self::fail("A missing or unrelated definition choice was accepted.");
            } catch (ValidationException) {
                self::assertSame([], $this->c->sis->sql->rows("sis_section_course_versions"));
            }
        }
    }

    public function testForeignCourseVersionAndReplacementOfAnExistingBindingAreRejected(): void
    {
        $other = $this->c->sis->section();
        $pub = $this->c->publications();
        $r = $pub->review($other->courseId, "Fictional other course");
        $foreign = new EntityId($pub->publish($other->courseId, $r["review_token"])["operation_id"]);
        try {
            $choices = $this->choices;
            $choices[$this->plan->sections[0]->id->value] = $foreign;
            $this->service->reviewPublication($this->id, $choices, "Fictional review");
            self::fail("A foreign course definition was selected.");
        } catch (\Domain\Shared\Exception\RecordNotFoundException) {
            self::assertSame([], $this->c->sis->sql->rows("sis_section_course_versions"));
        }
        $first = $this->plan->sections[0]->id;
        $r = $this->c->bindings()->review($first, $this->version, "Fictional prior binding");
        $this->c->bindings()->commit($first, $r["review_token"]);
        $choices = $this->choices;
        $choices[$first->value] = $this->publishCourse();
        $this->expectException(ValidationException::class);
        $this->service->reviewPublication($this->id, $choices, "Fictional replacement");
    }

    public function testAnAlreadyBoundDraftKeepsItsVersionAndDoesNotReceiveADuplicateBinding(): void
    {
        $first = $this->plan->sections[0]->id;
        $r = $this->c->bindings()->review($first, $this->version, "Fictional prior binding");
        $this->c->bindings()->commit($first, $r["review_token"]);
        $choices = $this->choices;
        unset($choices[$first->value]);
        $r = $this->service->reviewPublication($this->id, $choices, "Fictional offering approval");
        self::assertTrue($r["sections"][0]["was_bound"]);
        $this->service->publish($this->id, $r["review_token"]);
        self::assertCount(7, $this->c->sis->sql->rows("sis_history_events"));
        self::assertCount(2, $this->c->sis->sql->rows("sis_section_course_versions"));
    }

    public function testSectionChangesOrAnIndependentBindingRequireAnotherReview(): void
    {
        $f = $this->c->sis;
        $review = $this->review();
        $id = $this->plan->sections[0]->id;
        $f->sql->update("sis_sections", ["fee_minor" => 999], ["id" => $id->value]);
        try {
            $this->service->publish($this->id, $review["review_token"]);
            self::fail("An unreviewed price was published.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows("sis_section_course_versions"));
        }
        $review = $this->review();
        $r = $this->c->bindings()->review($id, $this->version, "Fictional competing binding");
        $this->c->bindings()->commit($id, $r["review_token"]);
        $this->expectException(ConcurrentModificationException::class);
        $this->service->publish($this->id, $review["review_token"]);
    }

    public function testLateAuditFailureRollsBackAllBindingsEventsReceiptsAndActivations(): void
    {
        $f = $this->c->sis;
        $review = $this->review();
        $tables = [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_course_versions",
            "sis_section_course_versions",
            "sis_section_versions",
            "sis_section_offering_versions",
            "sis_catalog_operations",
            "sis_audit",
        ];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $this->digest($table);
        }
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method("record")->willReturnCallback(static function ($actor, $action, $subject): void {
            if ($action === "catalog.offerings_published") {
                throw new RuntimeException("Fictional late failure");
            }
        });
        try {
            $this->c->offerings($audit)->publish($this->id, $review["review_token"]);
            self::fail("A late failure left partial publication.");
        } catch (RuntimeException $error) {
            self::assertSame("Fictional late failure", $error->getMessage());
            foreach ($tables as $table) {
                self::assertSame($before[$table], $this->digest($table), $table);
            }
            foreach ($this->plan->sections as $s) {
                self::assertFalse($f->registration->section($s->id)->active);
            }
        }
        $this->service->publish($this->id, $review["review_token"]);
        self::assertCount(2, $f->sql->rows("sis_section_course_versions"));
    }

    public function testRetryIsBoundToExactReviewActorAndCurrentPermissionAfterPublication(): void
    {
        $f = $this->c->sis;
        $review = $this->review();
        $competing = $this->review();
        $receipt = $this->service->publish($this->id, $review["review_token"]);
        $before = $this->digest("sis_history_events");
        $audit = $this->digest("sis_audit");
        $f->sql->update("sis_sections", ["capacity" => 50], ["id" => $this->plan->sections[0]->id->value]);
        self::assertSame($receipt, $this->service->publish($this->id, $review["review_token"]));
        self::assertSame($before, $this->digest("sis_history_events"));
        self::assertSame($audit, $this->digest("sis_audit"));
        try {
            $this->service->publish($this->id, $competing["review_token"]);
            self::fail("Another review was accepted after publication.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->digest("sis_history_events"));
        }
        $f->access->actor = $f->user();
        try {
            $this->service->publish($this->id, $review["review_token"]);
            self::fail("Another actor used a publication review.");
        } catch (AccessDeniedException) {
            self::assertSame($before, $this->digest("sis_history_events"));
        }
        $f->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $this->service->publish($this->id, $review["review_token"]);
    }

    public function testTamperedCrossPlanAndOldRevisionOnlyRequestsCannotPublish(): void
    {
        $review = $this->review();
        $cases2 = [
            $review["review_token"] . "tampered",
            str_repeat("a", 64),
            $this->c->fixture->cipher->seal("{}"),
        ];
        foreach ($cases2 as $token) {
            try {
                $this->service->publish($this->id, $token);
                self::fail("Invalid proof activated sections.");
            } catch (InvalidArgumentException) {
                self::assertSame([], $this->c->sis->sql->rows("sis_section_course_versions"));
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $this->service->publish(EntityId::generate(), $review["review_token"]);
    }
    public function testMaxLengthUnicodeDefinitionsUseBoundedProofsAndAttributableBindingEvents(): void
    {
        $f = $this->c->sis;
        $course = $this->plan->sections[0]->courseId;
        $editor = new \Domain\Catalog\Service\CatalogEditor(
            new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($f->sql),
            $f->registration,
            $f->access,
            $f->transactions,
            $f->audit,
        );
        $editor->courseText(
            $course,
            new CourseText(str_repeat("學", 80), str_repeat("學", 10000)),
            $editor->view("courses", $course)["revision"],
        );
        $version = $this->publishCourse();
        foreach ($this->choices as $key => $old) {
            $this->choices[$key] = $version;
        }
        $reason = str_repeat("🎓", 1000);
        $review = $this->service->reviewPublication($this->id, $this->choices, $reason);
        self::assertLessThan(65536, strlen($review["review_token"]));
        $standalone = $this->c->bindings()->review($this->plan->sections[0]->id, $version, $reason);
        self::assertLessThan(65536, strlen($standalone["review_token"]));
        $f->clock->time = $f->clock->time->modify("+1 minute");
        $this->service->publish($this->id, $review["review_token"]);
        $history = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(
                EventStoreFixture::config($f->connection->queryBuilder()),
            ),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
        foreach ($this->plan->sections as $section) {
            $stream = new \Domain\Shared\EventSourcing\StreamReference(
                new \Domain\Registration\ValueObject\SectionCourseBindingId($section->id->value),
                "section_course_binding",
            );
            $event = $history->page($stream)->events[0];
            self::assertSame($this->id->value, $event->context->correlation->value);
            self::assertSame($f->clock->now()->getTimestamp(), $event->context->effectiveAt->getTimestamp());
            self::assertSame($reason, $event->context->reason);
            $retainedStreams = [
                "section_publication" => \Domain\Registration\ValueObject\SectionPublicationId::class,
                "section_offering_binding" => \Domain\Registration\ValueObject\SectionOfferingBindingId::class,
            ];
            foreach ($retainedStreams as $type => $idClass) {
                $retained = $history->page(
                    new \Domain\Shared\EventSourcing\StreamReference(
                        new $idClass($section->id->value),
                        $type,
                    ),
                )->events[0];
                self::assertSame($this->id->value, $retained->context->causation->value);
                self::assertSame(
                    $f->clock->now()->getTimestamp(),
                    $retained->context->effectiveAt->getTimestamp(),
                );
                self::assertSame($reason, $retained->context->reason);
            }
        }
    }

    public function testUnboundDraftWithAnAcademicRecordCannotBeRetrospectivelyPublished(): void
    {
        $f = $this->c->sis;
        $review = $this->review();
        $student = $f->student();
        $section = $this->plan->sections[0];
        $enrollment = new \Domain\Registration\Enrollment(
            EntityId::generate(),
            $student->id,
            $section->id,
            $f->term,
            \Domain\Registration\Enum\RegistrationStatus::StaffRegistered,
            $f->clock->now(),
            $f->clock->now(),
        );
        $f->sql->insert(
            "sis_enrollments",
            \Domain\Registration\ValueObject\EnrollmentFacts::fromEnrollment($enrollment)->facts,
        );
        $f->academicCredits()->open(
            $enrollment,
            $f->registration->course($section->courseId),
            $f->access->actor,
        );
        $before = $this->digest("sis_history_events");
        try {
            $this->service->publish($this->id, $review["review_token"]);
            self::fail("An existing student record was retrospectively assigned a definition.");
        } catch (ValidationException) {
            self::assertSame($before, $this->digest("sis_history_events"));
            self::assertSame([], $f->sql->rows("sis_section_course_versions"));
            self::assertNull($this->service->saved($this->id)["published_at"]);
        }
    }

    public function testPlanDatesAndOfferingReferencesReachEnrollmentAndCreditWithoutChargingAtActivation(): void
    {
        $f = $this->c->sis;
        $first = $this->plan->sections[0]->id;
        $dates = [
            $first->value => ["instruction_starts_on" => "2026-09-01", "instruction_ends_on" => "2027-01-05"],
        ];
        $review = $this->service->reviewPublication(
            $this->id,
            $this->choices,
            "Reviewed short teaching period",
            $dates,
        );
        self::assertSame("2026-09-01", $review["sections"][0]["section_definition"]["instruction_starts_on"]);
        self::assertSame("2026-08-01", $review["sections"][1]["instruction_starts_on"]);
        self::assertSame([], $f->sql->rows("sis_section_versions"));
        $receipt = $this->service->publish($this->id, $review["review_token"]);
        self::assertSame([], $f->sql->rows("sis_student_accounts"));
        $versions = new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($f->sql);
        $history = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(
                EventStoreFixture::config($f->connection->queryBuilder()),
            ),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
        foreach ($this->plan->sections as $index => $section) {
            $retained = $versions->selected($section->id);
            self::assertSame(
                $receipt["review"]["sections"][$index]["section_version_id"],
                $retained->publication->value,
            );
            self::assertSame($review["sections"][$index]["section_definition"], $retained->definition->data);
            $student = $f->student();
            $enrollment = $f->service->register($student->id, $section->id);
            self::assertSame(
                $retained->publication->value,
                $versions->enrollmentVersion($enrollment->id)->value,
            );
            $credit = $f->sql->rows("sis_academic_credits", ["source_id" => $enrollment->id->value])[0];
            $events = $history->page(
                new \Domain\Shared\EventSourcing\StreamReference(
                    new \Domain\AcademicRecord\ValueObject\AcademicCreditId($credit["id"]),
                    "academic_credit",
                ),
            )->events;
            self::assertCount(1, $events);
            self::assertSame(
                $retained->publication->value,
                $events[0]->event->metadata()["section_version_id"],
            );
        }
        self::assertCount(2, $f->sql->rows("sis_student_accounts"));
    }

    public function testCatalogPermissionIsRecheckedAfterThePlanLockWait(): void
    {
        $f = $this->c->sis;
        $review = $this->review();
        $transactions = new class ($f) implements \Domain\Shared\Persistence\UnitOfWork {
            public function __construct(private SisFixture $fixture)
            {
            }
            public function run(Closure $operation): mixed
            {
                return $this->fixture->transactions->run(function () use ($operation): mixed {
                    $this->fixture->access->staff = false;
                    $this->fixture->access->grants = [];
                    return $operation();
                });
            }
        };
        $before = $this->digest("sis_history_events");
        try {
            $this->c->offerings(transactions: $transactions)->publish($this->id, $review["review_token"]);
            self::fail("Permission revoked during the wait was ignored.");
        } catch (AccessDeniedException) {
            self::assertSame($before, $this->digest("sis_history_events"));
            self::assertSame([], $f->sql->rows("sis_section_offering_versions"));
        }
    }

    public function testBoundDraftWithStudentRecordsCannotReceiveAnInferredOfferingSelection(): void
    {
        $f = $this->c->sis;
        $first = $this->plan->sections[0]->id;
        $binding = $this->c->bindings()->review($first, $this->version, "Earlier course binding");
        $this->c->bindings()->commit($first, $binding["review_token"]);
        $f->sql->update("sis_sections", ["active" => 1], ["id" => $first->value]);
        $f->service->register($f->student()->id, $first, true);
        $f->sql->update("sis_sections", ["active" => 0], ["id" => $first->value]);
        $before = $this->digest("sis_history_events");
        try {
            $this->review();
            self::fail("An earlier student was retrospectively assigned a section version.");
        } catch (ValidationException) {
            self::assertSame($before, $this->digest("sis_history_events"));
            self::assertSame([], $f->sql->rows("sis_section_versions"));
        }
    }

    public function testAlreadySelectedDraftIsRetainedWithoutDuplicatePublicationOrSelection(): void
    {
        $f = $this->c->sis;
        $first = $this->plan->sections[0]->id;
        $binding = $this->c->bindings()->review($first, $this->version, "Earlier binding");
        $this->c->bindings()->commit($first, $binding["review_token"]);
        $sections = $this->c->sectionPublications();
        $review = $sections->review($first, "2026-09-01", "2026-11-30", "Earlier section publication");
        $publication = $sections->publish($first, $review["review_token"]);
        $selection = $sections->reviewSelection(
            $first,
            new EntityId($publication["operation_id"]),
            "Earlier selection",
        );
        $sections->select($first, $selection["review_token"]);
        // A later publication is available but does not replace the selected version or its default dates.
        $later = $sections->review($first, "2026-10-01", "2026-11-30", "Unselected alternative");
        $sections->publish($first, $later["review_token"]);
        $before = $f->sql->rows("sis_history_events");
        $review = $this->review();
        self::assertSame("2026-09-01", $review["sections"][0]["instruction_starts_on"]);
        $receipt = $this->service->publish($this->id, $review["review_token"]);
        self::assertSame(
            $publication["operation_id"],
            $receipt["review"]["sections"][0]["section_version_id"],
        );
        self::assertSame($before, array_slice($f->sql->rows("sis_history_events"), 0, count($before)));
        self::assertCount(count($before) + 3, $f->sql->rows("sis_history_events"));
    }

    public function testAnInterveningSectionPublicationInvalidatesThePlanReview(): void
    {
        $first = $this->plan->sections[0]->id;
        $binding = $this->c->bindings()->review($first, $this->version, "Earlier binding");
        $this->c->bindings()->commit($first, $binding["review_token"]);
        $review = $this->review();
        $sections = $this->c->sectionPublications();
        $other = $sections->review(
            $first,
            "2026-08-01",
            "2026-12-20",
            "Intervening publication with matching dates",
        );
        $sections->publish($first, $other["review_token"]);
        $before = $this->digest("sis_history_events");
        try {
            $this->service->publish($this->id, $review["review_token"]);
            self::fail("An intervening section publication bypassed review.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->digest("sis_history_events"));
            self::assertSame([], $this->c->sis->sql->rows("sis_section_offering_versions"));
        }
    }

    public function testAnAlreadySelectedOfferingCannotBeAmendedImplicitlyDuringPlanActivation(): void
    {
        $first = $this->plan->sections[0]->id;
        $binding = $this->c->bindings()->review($first, $this->version, "Earlier binding");
        $this->c->bindings()->commit($first, $binding["review_token"]);
        $sections = $this->c->sectionPublications();
        $r = $sections->review($first, "2026-09-01", "2026-11-30", "Earlier publication");
        $publication = $sections->publish($first, $r["review_token"]);
        $r = $sections->reviewSelection(
            $first,
            new EntityId($publication["operation_id"]),
            "Earlier selection",
        );
        $sections->select($first, $r["review_token"]);
        $before = $this->digest("sis_history_events");
        try {
            $this->service->reviewPublication($this->id, $this->choices, "Change selected dates", [
                $first->value => [
                    "instruction_starts_on" => "2026-10-01",
                    "instruction_ends_on" => "2026-11-30",
                ],
            ]);
            self::fail("Activation silently amended an already selected offering.");
        } catch (ValidationException) {
            self::assertSame($before, $this->digest("sis_history_events"));
        }
    }

    public function testPlanDatesRejectForeignSectionsInvalidDatesAndReversedPeriods(): void
    {
        $first = $this->plan->sections[0]->id->value;
        $cases = [
            [
                EntityId::generate()->value => [
                    "instruction_starts_on" => "2026-09-01",
                    "instruction_ends_on" => "2026-11-30",
                ],
            ],
            [$first => ["instruction_starts_on" => "2026-02-30", "instruction_ends_on" => "2026-11-30"]],
            [$first => ["instruction_starts_on" => "2026-09-01", "instruction_ends_on" => "2026-08-31"]],
        ];
        $before = $this->digest("sis_history_events");
        foreach ($cases as $dates) {
            try {
                $this->service->reviewPublication($this->id, $this->choices, "Invalid dates", $dates);
                self::fail("Invalid instructional dates were accepted.");
            } catch (ValidationException) {
                self::assertSame($before, $this->digest("sis_history_events"));
            }
        }
    }

    public function testExpiredUnconfirmedPlanReviewFailsButAcceptedRetrySurvivesExpiryAndAmendments(): void
    {
        $f = $this->c->sis;
        $expired = $this->review();
        $f->clock->time = $f->clock->time->modify("+20 minutes");
        $before = $this->digest("sis_history_events");
        try {
            $this->service->publish($this->id, $expired["review_token"]);
            self::fail("An expired unconfirmed review was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->digest("sis_history_events"));
        }
        $review = $this->review();
        $receipt = $this->service->publish($this->id, $review["review_token"]);
        $first = $this->plan->sections[0]->id;
        $sections = $this->c->sectionPublications();
        $later = $sections->review($first, "2026-09-01", "2026-11-30", "Later publication");
        $sections->publish($first, $later["review_token"]);
        $f->clock->time = $f->clock->time->modify("+2 days");
        $before = $this->digest("sis_history_events");
        self::assertSame($receipt, $this->service->publish($this->id, $review["review_token"]));
        self::assertSame($before, $this->digest("sis_history_events"));
    }

    public function testOldUnconfirmedProofRequiresReviewAndOldCompletedReceiptCreatesNoNewEvents(): void
    {
        $f = $this->c->sis;
        $cipher = $this->c->fixture->cipher;
        $r = $this->review();
        $proof = json_decode($cipher->open($r["review_token"]), true, flags: JSON_THROW_ON_ERROR);
        $proof["kind"] = "offering-publication-review.v1";
        unset($proof["instruction_dates"], $proof["expires_at"]);
        $token = $cipher->seal(json_encode($proof, JSON_THROW_ON_ERROR));
        try {
            $this->service->publish($this->id, $token);
            self::fail("An old unconfirmed review activated a plan without section references.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows("sis_section_versions"));
        }
        // Fictional fixture for a completed receipt from the preceding implementation.
        $operation = $this->c->operations->operation($this->id);
        $data = json_decode($cipher->open($operation["encrypted_content"]), true, flags: JSON_THROW_ON_ERROR);
        $data["publication"] = [
            "actor_id" => $f->access->actor->value,
            "review_hash" => hash("sha256", $token),
            "review" => $data["review"],
        ];
        $this->c->operations->published(
            $this->id,
            $f->clock->now()->format(DATE_ATOM),
            $cipher->seal(json_encode($data, JSON_THROW_ON_ERROR)),
        );
        $before = $this->digest("sis_history_events");
        $receipt = $this->service->publish($this->id, $token);
        self::assertNotNull($receipt["published_at"]);
        self::assertSame($before, $this->digest("sis_history_events"));
        self::assertSame([], $f->sql->rows("sis_section_offering_versions"));
    }

    public function testMaximumPlanKeepsAllSectionReferencesAndBoundsItsReviewProof(): void
    {
        $f = $this->c->sis;
        $sections = [];
        foreach (range(1, 25) as $i) {
            $sections[] = new Section(
                EntityId::generate(),
                $this->plan->sections[0]->courseId,
                $f->term,
                null,
                "MATH.101.MAX." . $i,
                20,
                false,
                true,
                new \Domain\Finance\ValueObject\Money(0, "USD"),
            );
        }
        $plan = new OfferingPlan($f->term, ...$sections);
        $draft = $this->service->review($plan);
        $id = new EntityId($draft["operation_id"]);
        $this->service->save($id, $plan, $draft["revision"]);
        $choices = array_fill_keys(
            array_map(static fn(Section $s): string => $s->id->value, $sections),
            $this->version,
        );
        $review = $this->service->reviewPublication($id, $choices, str_repeat("🎓", 1000));
        self::assertLessThanOrEqual(65536, strlen($review["review_token"]));
        $receipt = $this->service->publish($id, $review["review_token"]);
        self::assertCount(
            25,
            array_filter(array_column($receipt["review"]["sections"], "section_version_id")),
        );
        self::assertCount(25, $f->sql->rows("sis_section_offering_versions"));
    }

    public function testChangedRetainedCacheStopsPublicationUntilProjectionRecovery(): void
    {
        $f = $this->c->sis;
        $first = $this->plan->sections[0]->id;
        $r = $this->c->bindings()->review($first, $this->version, "Fictional prior binding");
        $this->c->bindings()->commit($first, $r["review_token"]);
        $f->sql->update(
            "sis_course_versions",
            ["title" => "Incorrect cached title"],
            ["id" => $this->version->value],
        );
        $before = $this->digest("sis_history_events");
        try {
            $this->review();
            self::fail("An inconsistent retained definition was approved.");
        } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
            self::assertSame($before, $this->digest("sis_history_events"));
            self::assertNull($this->service->saved($this->id)["published_at"]);
        }
    }
}
