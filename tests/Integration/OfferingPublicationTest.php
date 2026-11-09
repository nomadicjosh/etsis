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
                "section-course-version-was-bound",
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
        self::assertCount(3, $this->c->sis->sql->rows("sis_history_events"));
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
        $reason = str_repeat("學", 1000);
        $review = $this->service->reviewPublication($this->id, $this->choices, $reason);
        self::assertLessThan(65536, strlen($review["review_token"]));
        $standalone = $this->c->bindings()->review($this->plan->sections[0]->id, $version, $reason);
        self::assertLessThan(65536, strlen($standalone["review_token"]));
        $f->clock->time = $f->clock->time->modify("+1 hour");
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
