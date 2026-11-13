<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Codefy\Domain\EventSourcing\EventStream;
use Domain\Catalog\Service\CoursePublicationService;
use Domain\Registration\Event\SectionDefinitionWasPublished;
use Domain\Registration\Section;
use Domain\Registration\SectionPublication;
use Domain\Registration\Service\SectionCourseBindingService;
use Domain\Registration\Service\SectionPublicationService;
use Domain\Registration\ValueObject\SectionDefinitionV1;
use Domain\Registration\ValueObject\SectionPublicationId;
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
use Infrastructure\Persistence\Repository\SqlCourseTextProjection;
use Infrastructure\Persistence\Repository\SqlSectionCourseBindingRepository;
use PHPUnit\Framework\TestCase;

final class MoodleSectionSyncTest extends TestCase
{
    private SisFixture $f;
    private Section $section;
    private SectionPublicationService $service;
    private SqlHistoricalEventStore $history;
    private CoursePublicationService $courses;
    private SqlCatalogRevisionRepository $catalog;

    protected function setUp(): void
    {
        $this->f = $f = new SisFixture();
        $f->connection->pdo->exec("ALTER TABLE users ADD COLUMN username VARCHAR(100)");
        $this->section = $f->section();
        $subject = new \Domain\Catalog\ValueObject\SubjectSnapshot(
            EntityId::generate(),
            "MATH",
            "Mathematics",
        );
        $subjects = new \Infrastructure\Persistence\Repository\SqlSubjectRepository($f->sql);
        $subjects->save($subject, true, true);
        $subjects->assign($this->section->courseId, $subject->id);
        $department = new \Domain\Catalog\ValueObject\ClassificationSnapshot(
            EntityId::generate(),
            "SCI",
            "School of Sciences",
        );
        $classifications = new \Infrastructure\Persistence\Repository\SqlClassificationRepository($f->sql);
        $classifications->save(\Domain\Catalog\Enum\ClassificationKind::Department, $department, true, true);
        $classifications->assign(
            $this->section->courseId,
            new \Domain\Catalog\ValueObject\CourseClassifications(department: $department),
        );
        $this->catalog = new SqlCatalogRevisionRepository($f->sql);
        $this->history = new SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new ProtectedEventCodec(EventStoreFixture::config($f->connection->queryBuilder())),
            new HistoricalSchemaRegistry(),
        );
        $relationships = new SqlCatalogWorkflowRepository($f->sql);
        $this->courses = new CoursePublicationService(
            $this->catalog,
            $relationships,
            $this->history,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
            new SqlCourseTextProjection($f->sql),
            new \Infrastructure\Persistence\Repository\SqlSubjectRepository($f->sql),
            new \Infrastructure\Persistence\Repository\SqlClassificationRepository($f->sql),
        );
        $bindings = new SqlSectionCourseBindingRepository($f->sql);
        $review = $this->courses->review($this->section->courseId, "Fictional course publication");
        $version = $this->courses->publish($this->section->courseId, $review["review_token"]);
        $bind = new SectionCourseBindingService(
            $this->catalog,
            $this->courses,
            $bindings,
            $this->history,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
        );
        $proof = $bind->review(
            $this->section->id,
            new EntityId($version["operation_id"]),
            "Fictional binding",
        );
        $bind->commit($this->section->id, $proof["review_token"]);
        $this->service = new SectionPublicationService(
            $this->catalog,
            $relationships,
            $bindings,
            $this->courses,
            $this->history,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
            new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($f->sql),
        );
    }

    private function context(bool $select = true): array
    {
        $f = $this->f;
        $review = $this->service->review(
            $this->section->id,
            "2026-08-24",
            "2026-12-18",
            "Fictional published section",
        );
        $publication = $this->service->publish($this->section->id, $review["review_token"]);
        $selection = $this->service->reviewSelection(
            $this->section->id,
            new EntityId($publication["operation_id"]),
            "Fictional selected section",
        );
        if ($select) {
            $this->service->select($this->section->id, $selection["review_token"]);
        }
        $policy = new class ($f->access->actor) implements \Domain\Lms\Service\MoodleSectionSyncPolicy {
            public bool $on = true;
            public int $before = 14;
            public function __construct(private EntityId $id) {}
            public function enabled(): bool
            {
                return $this->on;
            }
            public function actor(): EntityId
            {
                return $this->id;
            }
            public function beforeDays(): int
            {
                return $this->before;
            }
            public function afterDays(): int
            {
                return 7;
            }
        };
        $repo = new \Infrastructure\Lms\SqlMoodleSectionRepository(
            $f->sql,
            $f->registration,
            new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($f->sql),
            $this->history,
            $f->admissions,
            $f->enrollmentLedger(),
            $f->activityLedger(),
            $f->transactions,
            $policy,
            $f->clock,
        );
        $bindings = new \Infrastructure\Lms\SqlMoodleBindingRepository($f->sql);
        $binding = new \Domain\Lms\ValueObject\MoodleBinding(
            EntityId::generate(),
            str_repeat("a", 64),
            str_repeat("b", 64),
            $f->access->actor,
            "2026-08-10T17:00:00Z",
        );
        $bindings->add($binding, "fictional-encrypted");
        $remote = new class ($f) implements \Domain\Lms\Service\MoodleSectionGateway {
            public array $members = [];
            public ?Closure $duringCall = null;
            public bool $fail = false;
            public function __construct(private SisFixture $f) {}
            public array $categories = [];
            public array $categoryIds = [];
            public ?Closure $duringCategory = null;
            public function syncCategory(
                \Domain\Lms\ValueObject\MoodleBinding $binding,
                array $category,
            ): array {
                TestCase::assertFalse($this->f->connection->pdo->inTransaction());
                $this->categories[] = $category;
                $key = $category["source"] . ":" . $category["reference_id"];
                $this->categoryIds[$key] ??= [
                    "id" =>
                        $category["category_id"] === "0"
                            ? (string) (20 + count($this->categoryIds))
                            : $category["category_id"],
                    "owned" => $category["category_id"] === "0",
                    "status" => "synchronized",
                ];
                if ($this->duringCategory !== null) {
                    ($this->duringCategory)();
                }
                return $this->categoryIds[$key];
            }
            public array $courses = [];
            public ?Closure $duringCourse = null;
            public function provisionCourse(
                \Domain\Lms\ValueObject\MoodleBinding $binding,
                EntityId $operation,
                array $course,
            ): \Domain\Lms\ValueObject\MoodleExternalId {
                TestCase::assertFalse($this->f->connection->pdo->inTransaction());
                $this->courses[] = ["operation" => $operation->value, "course" => $course];
                if ($this->duringCourse !== null) {
                    ($this->duringCourse)();
                }
                if ($this->fail) {
                    throw new RuntimeException("fictional-private-upstream");
                }
                return new \Domain\Lms\ValueObject\MoodleExternalId("81");
            }
            public function rosterPolicy(\Domain\Lms\ValueObject\MoodleBinding $binding): array
            {
                TestCase::assertFalse($this->f->connection->pdo->inTransaction());
                return ["policy_hash" => str_repeat("c", 64)];
            }
            public function syncMember(
                \Domain\Lms\ValueObject\MoodleBinding $binding,
                string $policyHash,
                array $member,
            ): string {
                TestCase::assertFalse($this->f->connection->pdo->inTransaction());
                $this->members[] = $member;
                if ($this->duringCall !== null) {
                    ($this->duringCall)();
                }
                if ($this->fail) {
                    throw new RuntimeException("fictional-private-upstream");
                }
                return "synchronized";
            }
        };
        $lookup = $this->createStub(\Domain\Lms\Service\MoodleBoundRecordReader::class);
        $lookup
            ->method("inspectBound")
            ->willReturn(
                new \Domain\Lms\ValueObject\MoodleRecordCheck(
                    \Domain\Lms\ValueObject\MoodleResourceKind::Course,
                    true,
                ),
            );
        $people = new \Infrastructure\Lms\SqlMoodlePersonLinkRepository($f->sql);
        $roles = new \Infrastructure\Persistence\Repository\SqlAccessRepository($f->sql);
        $roles->assign(
            \Domain\Access\Enum\GrantKind::UserRole,
            $f->access->actor,
            $roles->roleNamed("super")->id,
            true,
        );
        $automationPolicy = new class ($f->access->actor) implements
            \Domain\Lms\Service\MoodleAutomationPolicy
        {
            public bool $automatic = false;
            public bool $courses = true;
            public function __construct(private EntityId $id) {}
            public function automaticAccountsEnabled(): bool
            {
                return $this->automatic;
            }
            public function courseCreationEnabled(): bool
            {
                return $this->courses;
            }
            public function actor(): EntityId
            {
                return $this->id;
            }
            public function signIn(): string
            {
                return "sso";
            }
        };
        $accountPolicy = new class implements \Domain\Lms\Service\MoodleAccountRequestPolicy {
            public function enabled(): bool
            {
                return true;
            }
            public function processingEnabled(): bool
            {
                return true;
            }
        };
        $accountRemote = new class ($f) implements \Domain\Lms\Service\MoodleAccountProvisioner {
            public array $created = [];
            public function __construct(private SisFixture $f) {}
            public function policy(
                \Domain\Lms\ValueObject\MoodleBinding $binding,
            ): \Domain\Lms\ValueObject\MoodleAccountPolicy {
                TestCase::assertFalse($this->f->connection->pdo->inTransaction());
                return new \Domain\Lms\ValueObject\MoodleAccountPolicy(str_repeat("c", 64), true, true);
            }
            public function provision(
                \Domain\Lms\ValueObject\MoodleBinding $binding,
                EntityId $operation,
                string $policyHash,
                \Domain\Lms\ValueObject\MoodleAccountDraft $draft,
                string $firstname,
                string $lastname,
            ): \Domain\Lms\ValueObject\MoodleProvisionedAccount {
                TestCase::assertFalse($this->f->connection->pdo->inTransaction());
                $this->created[$operation->value] ??= new \Domain\Lms\ValueObject\MoodleProvisionedAccount(
                    new \Domain\Lms\ValueObject\MoodleExternalId((string) (700 + count($this->created))),
                    "sso_ready",
                );
                return $this->created[$operation->value];
            }
        };
        $defaults = new \Infrastructure\Lms\SqlMoodleAccountDefaults($f->sql);
        $automatic = new \Domain\Lms\Service\MoodleAutomaticAccountService(
            $automationPolicy,
            $accountPolicy,
            new \Infrastructure\Lms\SqlMoodleAccountRequestRepository($f->sql),
            $defaults,
            $bindings,
            $people,
            $repo,
            $f->admissions,
            $roles,
            $accountRemote,
            new \Domain\Lms\Service\MoodleAccountProfile($defaults),
            $f->dropCipher,
            $f->transactions,
            $f->audit,
            $f->clock,
        );
        $service = new \Domain\Lms\Service\MoodleSectionSyncService(
            $repo,
            $remote,
            $lookup,
            $bindings,
            $people,
            $policy,
            $roles,
            $f->access,
            $f->transactions,
            $f->clock,
            $f->dropCipher,
            $f->audit,
            $automatic,
            new \Infrastructure\Lms\SqlMoodleCourseRequestRepository($f->sql),
        );
        $categoryRepo = new \Infrastructure\Lms\SqlMoodleCategoryRepository($f->sql);
        $categoryRepo->configure($binding->id, "subjects", 0, $f->access->actor, "2026-08-10T17:00:00Z");
        $categories = new \Domain\Lms\Service\MoodleCategoryService(
            $categoryRepo,
            $bindings,
            $remote,
            $policy,
            $roles,
            $f->access,
            $f->transactions,
            $f->clock,
            $f->audit,
            $f->dropCipher,
        );
        $courseService = new \Domain\Lms\Service\MoodleCourseProvisioningService(
            $automationPolicy,
            new \Infrastructure\Lms\SqlMoodleCourseRequestRepository($f->sql),
            $bindings,
            $repo,
            new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($f->sql),
            new SqlSectionCourseBindingRepository($f->sql),
            $remote,
            $roles,
            $f->access,
            $f->transactions,
            $f->dropCipher,
            $f->audit,
            $f->clock,
            $categories,
        );
        $worker = new \Domain\Lms\Service\MoodleProvisioningWorker(
            $accountPolicy,
            new \Infrastructure\Lms\SqlMoodleProvisioningQueue($f->sql),
            $bindings,
            $people,
            $f->admissions,
            $roles,
            new \Domain\Lms\Service\MoodleAccountProfile($defaults),
            $accountRemote,
            $f->dropCipher,
            $f->transactions,
            $f->audit,
            $f->clock,
            $automatic,
        );
        return [
            $service,
            $repo,
            $remote,
            $policy,
            $binding,
            $people,
            $roles,
            $automatic,
            $courseService,
            $automationPolicy,
            $worker,
            $accountRemote,
            $categories,
            $categoryRepo,
        ];
    }
    private function studentLink(array $context): \Domain\Student\Student
    {
        $f = $this->f;
        $student = $f->student();
        $f->service->register($student->id, $this->section->id, true);
        $owner = $f->admissions->person($student->personId)->ownerUserId;
        $context[6]->assign(
            \Domain\Access\Enum\GrantKind::UserRole,
            $owner,
            $context[6]->roleNamed("student")->id,
            true,
        );
        $context[5]->add(
            new \Domain\Lms\ValueObject\MoodlePersonLink(
                EntityId::generate(),
                $context[4]->id,
                $student->personId,
                new \Domain\Lms\ValueObject\MoodleExternalId("501"),
                $f->access->actor,
                "2026-08-10T17:00:00Z",
            ),
            "fictional-person-link",
        );
        return $student;
    }
    public function testPublishedSectionLinksOnceAndSynchronizesStudentWithoutAcademicMutations(): void
    {
        $c = $this->context();
        $student = $this->studentLink($c);
        $s = $c[0];
        $history = $this->f->sql->rows("sis_history_events");
        self::assertSame(
            ["linked" => true],
            $s->link(
                $this->section->id,
                new \Domain\Lms\ValueObject\MoodleExternalId("81"),
                "Verified the matching Moodle course.",
                true,
            ),
        );
        self::assertSame(
            ["linked" => true],
            $s->link(
                $this->section->id,
                new \Domain\Lms\ValueObject\MoodleExternalId("81"),
                "Retry after response interruption.",
                true,
            ),
        );
        self::assertCount(1, $this->f->sql->rows("sis_moodle_section_links"));
        self::assertSame("synchronized", $s->runOne());
        self::assertTrue($c[2]->members[0]["student"]);
        self::assertFalse($c[2]->members[0]["instructor"]);
        self::assertSame($student->personId->value, $c[2]->members[0]["person"]);
        self::assertSame("cycle_completed", $s->runOne());
        self::assertSame($history, $this->f->sql->rows("sis_history_events"));
        self::assertStringNotContainsString("Alex", json_encode($c[2]->members));
        self::assertSame(
            "81",
            $s->directory(substr($this->section->code, 0, 3))["items"][0]["moodle_course_id"],
        );
    }
    public function testMissingAccountIsRetainedAsActionableSetupInsteadOfSkippingThePerson(): void
    {
        $c = $this->context();
        $student = $this->f->student();
        $this->f->service->register($student->id, $this->section->id, true);
        $owner = $this->f->admissions->person($student->personId)->ownerUserId;
        $c[6]->assign(\Domain\Access\Enum\GrantKind::UserRole, $owner, $c[6]->roleNamed("student")->id, true);
        $c[0]->link(
            $this->section->id,
            new \Domain\Lms\ValueObject\MoodleExternalId("81"),
            "Verified course.",
            true,
        );
        self::assertSame("setup_required", $c[0]->runOne());
        self::assertSame(
            "account_setup_required",
            $this->f->sql->rows("sis_moodle_section_members")[0]["issue_code"],
        );
        self::assertSame([], $c[2]->members);
        self::assertSame("cycle_completed", $c[0]->runOne());
        self::assertSame(1, $c[0]->directory($this->section->code)["items"][0]["members_needing_setup"]);
        $c[5]->add(
            new \Domain\Lms\ValueObject\MoodlePersonLink(
                EntityId::generate(),
                $c[4]->id,
                $student->personId,
                new \Domain\Lms\ValueObject\MoodleExternalId("501"),
                $this->f->access->actor,
                "2026-08-10T17:00:00Z",
            ),
            "fictional-person-link",
        );
        $this->f->clock->time = $this->f->clock->time->modify("+61 seconds");
        self::assertSame("synchronized", $c[0]->runOne());
        self::assertSame(0, $c[0]->directory($this->section->code)["items"][0]["members_needing_setup"]);
    }
    public function testRoleLossDuringEffectKeepsCursorForImmediateConvergence(): void
    {
        $c = $this->context();
        $student = $this->studentLink($c);
        $owner = $this->f->admissions->person($student->personId)->ownerUserId;
        $c[0]->link(
            $this->section->id,
            new \Domain\Lms\ValueObject\MoodleExternalId("81"),
            "Verified course.",
            true,
        );
        $c[2]->duringCall = function () use ($c, $owner): void {
            $c[6]->assign(
                \Domain\Access\Enum\GrantKind::UserRole,
                $owner,
                $c[6]->roleNamed("student")->id,
                false,
            );
        };
        self::assertSame("source_changed", $c[0]->runOne());
        self::assertNull($this->f->sql->rows("sis_moodle_section_links")[0]["after_person"]);
        $c[2]->duringCall = null;
        self::assertSame("setup_required", $c[0]->runOne());
        self::assertFalse($c[2]->members[1]["student"]);
        self::assertGreaterThan($c[2]->members[0]["version"], $c[2]->members[1]["version"]);
    }
    public function testDisabledWorkerAndMissingConfirmationMakeNoExternalChanges(): void
    {
        $c = $this->context();
        $c[3]->on = false;
        self::assertSame("disabled", $c[0]->runOne());
        self::assertSame([], $c[2]->members);
        $this->expectException(\Domain\Shared\Exception\ValidationException::class);
        $c[0]->link(
            $this->section->id,
            new \Domain\Lms\ValueObject\MoodleExternalId("81"),
            "Verified course.",
            false,
        );
    }
    public function testAnApprovedSectionCannotBeMovedToAnotherCourse(): void
    {
        $c = $this->context();
        $c[0]->link(
            $this->section->id,
            new \Domain\Lms\ValueObject\MoodleExternalId("81"),
            "Verified course.",
            true,
        );
        $this->expectException(ConcurrentModificationException::class);
        $c[0]->link(
            $this->section->id,
            new \Domain\Lms\ValueObject\MoodleExternalId("82"),
            "Different course.",
            true,
        );
    }
    public function testUnknownOutcomeKeepsReferencesAndResumesWithAHigherFence(): void
    {
        $c = $this->context();
        $this->studentLink($c);
        $c[0]->link(
            $this->section->id,
            new \Domain\Lms\ValueObject\MoodleExternalId("81"),
            "Verified course.",
            true,
        );
        $c[2]->fail = true;
        self::assertSame("review_required", $c[0]->runOne());
        self::assertCount(1, $this->f->sql->rows("sis_moodle_section_members"));
        self::assertNull($this->f->sql->rows("sis_moodle_section_links")[0]["after_person"]);
        self::assertSame("idle", $c[0]->runOne());
        $this->f->clock->time = $this->f->clock->time->modify("+61 seconds");
        $c[2]->fail = false;
        self::assertSame("synchronized", $c[0]->runOne());
        self::assertGreaterThan($c[2]->members[0]["version"], $c[2]->members[1]["version"]);
    }
    public function testEarlyDropRetainsARevocationCandidateAfterCurrentEnrollmentIsRemoved(): void
    {
        $c = $this->context();
        $student = $this->studentLink($c);
        $c[0]->link(
            $this->section->id,
            new \Domain\Lms\ValueObject\MoodleExternalId("81"),
            "Verified course.",
            true,
        );
        self::assertSame("synchronized", $c[0]->runOne());
        self::assertSame("cycle_completed", $c[0]->runOne());
        $this->f->clock->time = $this->f->clock->time->modify("+61 seconds");
        $this->f->drops->drop($student->id, $this->section->id);
        self::assertNull($this->f->registration->enrollment($student->id, $this->section->id));
        $history = $this->f->sql->rows("sis_history_events");
        self::assertSame("synchronized", $c[0]->runOne());
        self::assertFalse($c[2]->members[1]["student"]);
        self::assertSame($history, $this->f->sql->rows("sis_history_events"));
    }
    public function testPublishedInstructorAndCurrentFacultyRoleControlCourseAccess(): void
    {
        $owner = $this->f->user();
        $person = new \Domain\Person\Person(
            EntityId::generate(),
            $owner,
            "Fictional",
            "Instructor",
            new \Domain\Person\ValueObject\InstitutionalId("FICTIONAL-FACULTY"),
        );
        $this->f->admissions->addPerson($person);
        $roles = new \Infrastructure\Persistence\Repository\SqlAccessRepository($this->f->sql);
        $roles->assign(
            \Domain\Access\Enum\GrantKind::UserRole,
            $owner,
            $roles->roleNamed("faculty")->id,
            true,
        );
        $this->f->sql->update(
            "sis_sections",
            ["faculty_user_id" => $owner->value],
            ["id" => $this->section->id->value],
        );
        $c = $this->context();
        $c[5]->add(
            new \Domain\Lms\ValueObject\MoodlePersonLink(
                EntityId::generate(),
                $c[4]->id,
                $person->id,
                new \Domain\Lms\ValueObject\MoodleExternalId("502"),
                $this->f->access->actor,
                "2026-08-10T17:00:00Z",
            ),
            "fictional-link",
        );
        $c[0]->link(
            $this->section->id,
            new \Domain\Lms\ValueObject\MoodleExternalId("81"),
            "Verified course.",
            true,
        );
        self::assertSame("synchronized", $c[0]->runOne());
        self::assertTrue($c[2]->members[0]["instructor"]);
        self::assertFalse($c[2]->members[0]["student"]);
        self::assertSame("cycle_completed", $c[0]->runOne());
        $this->f->clock->time = $this->f->clock->time->modify("+61 seconds");
        $roles->assign(
            \Domain\Access\Enum\GrantKind::UserRole,
            $owner,
            $roles->roleNamed("faculty")->id,
            false,
        );
        self::assertSame("setup_required", $c[0]->runOne());
        self::assertFalse($c[2]->members[1]["instructor"]);
    }
    private function workflow(array $c): \Application\Workflow\SectionPublicationWorkflow
    {
        return new \Application\Workflow\SectionPublicationWorkflow(
            $this->service,
            $c[8],
            $this->f->dropCipher,
            $this->f->transactions,
            $this->f->access,
        );
    }
    private function queueCourse(array $c): array
    {
        $w = $this->workflow($c);
        $review = $w->review($this->section->id, "2026-08-24", "2026-12-18", "Publish with Moodle.", true);
        return [$review, $w->publish($this->section->id, $review["review_token"])];
    }
    private function selectCoursePublication(string $publication): void
    {
        $r = $this->service->reviewSelection(
            $this->section->id,
            new EntityId($publication),
            "Use reviewed offering.",
        );
        $this->service->select($this->section->id, $r["review_token"]);
    }
    public function testPublicationQueuesCreationAtomicallyAndOneWorkerRetainsItsCourseLink(): void
    {
        $c = $this->context(false);
        [$review, $published] = $this->queueCourse($c);
        self::assertTrue($published["moodle_creation_queued"]);
        self::assertSame([], $c[2]->courses);
        self::assertSame("course_waiting", $c[8]->runOne());
        self::assertSame([], $c[2]->courses);
        $this->selectCoursePublication($published["operation_id"]);
        $this->f->clock->time = $this->f->clock->time->modify("+61 seconds");
        $history = $this->f->sql->rows("sis_history_events");
        self::assertSame("course_completed", $c[8]->runOne());
        self::assertSame("81", $this->f->sql->rows("sis_moodle_section_links")[0]["moodle_course_id"]);
        self::assertSame("etsis-" . $this->section->id->value, $c[2]->courses[0]["course"]["shortname"]);
        self::assertSame("idle", $c[8]->runOne());
        self::assertCount(1, $c[2]->courses);
        $c[9]->courses = false;
        self::assertSame(
            $published,
            $this->workflow($c)->publish($this->section->id, $review["review_token"]),
        );
        self::assertCount(1, $this->f->sql->rows("sis_moodle_course_requests"));
        self::assertSame($history, $this->f->sql->rows("sis_history_events"));
    }
    public function testUncertainCourseCreationRetainsItsOperationForRetry(): void
    {
        $c = $this->context(false);
        [, $published] = $this->queueCourse($c);
        $this->selectCoursePublication($published["operation_id"]);
        $c[2]->fail = true;
        self::assertSame("course_retry_pending", $c[8]->runOne());
        self::assertSame("idle", $c[8]->runOne());
        $c[2]->fail = false;
        $this->f->clock->time = $this->f->clock->time->modify("+121 seconds");
        self::assertSame("course_completed", $c[8]->runOne());
        self::assertSame($c[2]->courses[0], $c[2]->courses[1]);
        self::assertCount(1, $this->f->sql->rows("sis_moodle_section_links"));
    }
    public function testCourseIntentFailureRollsBackTheAcademicPublication(): void
    {
        $c = $this->context();
        $w = $this->workflow($c);
        $review = $w->review($this->section->id, "2026-08-24", "2026-12-18", "Atomic decision.", true);
        $before = $this->f->sql->rows("sis_history_events");
        $this->f->connection->pdo->exec(
            "CREATE TRIGGER reject_course_request BEFORE INSERT ON sis_moodle_course_requests BEGIN SELECT RAISE(ABORT,'fictional rejection'); END",
        );
        try {
            $w->publish($this->section->id, $review["review_token"]);
            self::fail("Publication survived a failed intent.");
        } catch (PDOException) {
        }
        self::assertSame($before, $this->f->sql->rows("sis_history_events"));
        self::assertSame([], $this->f->sql->rows("sis_moodle_course_requests"));
    }
    public function testCourseCreationRequiresCurrentIndependentMoodleAuthority(): void
    {
        $c = $this->context();
        $w = $this->workflow($c);
        $review = $w->review($this->section->id, "2026-08-24", "2026-12-18", "Authorized decision.", true);
        $before = $this->f->sql->rows("sis_history_events");
        $this->f->access->staff = false;
        $this->f->access->grants = ["sis:catalog:manage"];
        try {
            $w->publish($this->section->id, $review["review_token"]);
            self::fail();
        } catch (AccessDeniedException) {
        }
        self::assertSame($before, $this->f->sql->rows("sis_history_events"));
        self::assertSame([], $c[2]->courses);
    }
    private function automaticStudent(array $c, bool $byStaff = true): \Domain\Student\Student
    {
        $f = $this->f;
        $student = $f->student();
        $owner = $f->admissions->person($student->personId)->ownerUserId;
        $actor = $f->access->actor;
        if (!$byStaff) {
            $f->access->actor = $owner;
            $f->access->staff = false;
        }
        try {
            $f->service->register($student->id, $this->section->id, $byStaff);
        } finally {
            $f->access->actor = $actor;
            $f->access->staff = true;
        }
        $c[6]->assign(\Domain\Access\Enum\GrantKind::UserRole, $owner, $c[6]->roleNamed("student")->id, true);
        $f->sql->update(
            "users",
            ["username" => "alex_fictional", "email" => "alex@example.invalid"],
            ["user_id" => $owner->value],
        );
        $c[0]->link(
            $this->section->id,
            new \Domain\Lms\ValueObject\MoodleExternalId("81"),
            "Verified original course.",
            true,
        );
        $c[9]->automatic = true;
        return $student;
    }
    public function testFirstRegistrationQueuesOneAccountThenRetainsLinkAndSynchronizesAccess(): void
    {
        $c = $this->context();
        $student = $this->automaticStudent($c);
        $before = $this->f->sql->rows("sis_history_events");
        self::assertSame("setup_required", $c[0]->runOne());
        self::assertSame([], $c[11]->created);
        $link = $this->f->sql->rows("sis_moodle_section_links")[0];
        self::assertSame(
            "account_pending",
            $c[7]->ensure($this->section->id, new EntityId($link["id"]), $student->personId),
        );
        self::assertCount(1, $this->f->sql->rows("sis_moodle_account_requests"));
        self::assertSame("completed", $c[10]->runOne());
        self::assertCount(1, $c[11]->created);
        self::assertNotNull($c[5]->forPerson($c[4]->id, $student->personId));
        self::assertSame("cycle_completed", $c[0]->runOne());
        $this->f->clock->time = $this->f->clock->time->modify("+61 seconds");
        self::assertSame("synchronized", $c[0]->runOne());
        self::assertTrue($c[2]->members[0]["student"]);
        self::assertSame("700", $c[2]->members[0]["user"]);
        self::assertSame($before, $this->f->sql->rows("sis_history_events"));
    }
    public function testFirstUseCanPrepareAccountBeforeStudentCourseAccessOpens(): void
    {
        $c = $this->context();
        $c[3]->before = 0;
        $this->automaticStudent($c);
        self::assertSame("setup_required", $c[0]->runOne());
        self::assertSame("completed", $c[10]->runOne());
        self::assertSame("cycle_completed", $c[0]->runOne());
        $this->f->clock->time = $this->f->clock->time->modify("+61 seconds");
        self::assertSame("synchronized", $c[0]->runOne());
        self::assertFalse($c[2]->members[0]["student"]);
    }
    public function testDropBeforeProvisioningStopsAutomaticAccountCreation(): void
    {
        $c = $this->context();
        $student = $this->automaticStudent($c);
        $c[0]->runOne();
        $this->f->drops->drop(
            $student->id,
            $this->section->id,
            "Changed registration.",
            EntityId::generate(),
        );
        self::assertSame("review_required", $c[10]->runOne());
        self::assertSame([], $c[11]->created);
        self::assertNull($c[5]->forPerson($c[4]->id, $student->personId));
    }
    public function testIncompleteProfileAndUnregisteredPeopleAreNotAutomaticallyCreated(): void
    {
        $c = $this->context();
        $student = $this->automaticStudent($c);
        $owner = $this->f->admissions->person($student->personId)->ownerUserId;
        $this->f->sql->update("users", ["email" => null], ["user_id" => $owner->value]);
        self::assertSame("setup_required", $c[0]->runOne());
        self::assertSame([], $this->f->sql->rows("sis_moodle_account_requests"));
        self::assertSame([], $c[11]->created);
    }

    public function testAssignedFacultyIsProvisionedBeforeTheStudentAccessWindow(): void
    {
        $owner = $this->f->user();
        $person = new \Domain\Person\Person(
            EntityId::generate(),
            $owner,
            "Fictional",
            "Instructor",
            new \Domain\Person\ValueObject\InstitutionalId("FACULTY-AUTO"),
        );
        $this->f->admissions->addPerson($person);
        $this->f->sql->update(
            "users",
            ["username" => "faculty_fictional", "email" => "faculty@example.invalid"],
            ["user_id" => $owner->value],
        );
        $this->f->sql->update(
            "sis_sections",
            ["faculty_user_id" => $owner->value],
            ["id" => $this->section->id->value],
        );
        $c = $this->context();
        $c[9]->automatic = true;
        $c[3]->before = 0;
        $c[6]->assign(\Domain\Access\Enum\GrantKind::UserRole, $owner, $c[6]->roleNamed("faculty")->id, true);
        $c[0]->link(
            $this->section->id,
            new \Domain\Lms\ValueObject\MoodleExternalId("81"),
            "Verified teaching course.",
            true,
        );
        self::assertSame("setup_required", $c[0]->runOne());
        self::assertSame("completed", $c[10]->runOne());
        self::assertSame("cycle_completed", $c[0]->runOne());
        $this->f->clock->time = $this->f->clock->time->modify("+61 seconds");
        self::assertSame("synchronized", $c[0]->runOne());
        self::assertTrue($c[2]->members[0]["instructor"]);
        self::assertFalse($c[2]->members[0]["student"]);
    }
    public function testStudentPortalRegistrationProvisionsTheStudentRatherThanTheRegistrationActor(): void
    {
        $c = $this->context();
        $student = $this->automaticStudent($c, false);
        self::assertSame(
            "N",
            $this->f->registration->enrollment($student->id, $this->section->id)->status->value,
        );
        self::assertSame("setup_required", $c[0]->runOne());
        $request = $this->f->sql->rows("sis_moodle_account_requests")[0];
        self::assertSame($student->personId->value, $request["person_id"]);
        self::assertSame($c[9]->actor()->value, $request["requested_by"]);
        self::assertSame("completed", $c[10]->runOne());
        self::assertNotNull($c[5]->forPerson($c[4]->id, $student->personId));
    }

    public function testCombinedWorkerCreatesCourseAccountAndRosterWithoutASecondManualLink(): void
    {
        $c = $this->context(false);
        [, $published] = $this->queueCourse($c);
        $this->selectCoursePublication($published["operation_id"]);
        $engine = new \Domain\Lms\Service\MoodleSynchronizationService($c[3], $c[8], $c[10], $c[0], $c[12]);
        self::assertSame("course_completed", $engine->runOne(1));
        $student = $this->automaticStudent($c);
        self::assertSame(
            "A",
            $this->f->registration->enrollment($student->id, $this->section->id)->status->value,
        );
        self::assertSame("setup_required", $engine->runOne(1));
        self::assertSame("account_completed", $engine->runOne(1));
        self::assertSame("cycle_completed", $engine->runOne(2));
        $this->f->clock->time = $this->f->clock->time->modify("+61 seconds");
        self::assertSame("synchronized", $engine->runOne(2));
        self::assertCount(1, $c[2]->courses);
        self::assertCount(1, $c[11]->created);
        self::assertCount(1, $this->f->sql->rows("sis_moodle_person_links"));
        self::assertTrue($c[2]->members[0]["student"]);
    }
    public function testCourseCreationStopsAtTheExistingBoundedRetryBudget(): void
    {
        $c = $this->context(false);
        [, $published] = $this->queueCourse($c);
        $this->selectCoursePublication($published["operation_id"]);
        $c[2]->fail = true;
        for ($i = 0; $i < 5; $i++) {
            self::assertSame($i === 4 ? "review_required" : "course_retry_pending", $c[8]->runOne());
            $this->f->clock->time = $this->f->clock->time->modify("+121 seconds");
        }
        self::assertSame("idle", $c[8]->runOne());
        self::assertCount(1, array_unique(array_column($c[2]->courses, "operation")));
        $row = $this->f->sql->rows("sis_moodle_course_requests")[0];
        self::assertSame("review_required", $row["state"]);
        self::assertSame("attempt_limit", $row["issue_code"]);
        self::assertSame(5, (int) $row["attempts"]);
    }

    public function testManualCourseLinkCannotCompeteWithARetainedCreationRequest(): void
    {
        $c = $this->context();
        $this->queueCourse($c);
        $this->expectException(ConcurrentModificationException::class);
        $c[0]->link(
            $this->section->id,
            new \Domain\Lms\ValueObject\MoodleExternalId("90"),
            "Different existing course.",
            true,
        );
    }
    public function testCategoryPolicySyncsNewReferencesAndOwnedRenamesWithoutRepeatingUnchangedReads(): void
    {
        $c = $this->context();
        $history = $this->f->sql->rows("sis_history_events");
        self::assertSame("category_synchronized", $c[12]->runOne());
        self::assertSame("idle", $c[12]->runOne());
        self::assertCount(1, $c[2]->categories);
        $id = $this->f->sql->rows("sis_subjects")[0]["id"];
        $this->f->sql->update("sis_subjects", ["title" => "Mathematical Sciences"], ["id" => $id]);
        self::assertSame("category_synchronized", $c[12]->runOne());
        self::assertSame("Mathematical Sciences", $c[2]->categories[1]["title"]);
        self::assertSame("20", $c[2]->categories[1]["category_id"]);
        self::assertGreaterThan($c[2]->categories[0]["version"], $c[2]->categories[1]["version"]);
        self::assertCount(1, $this->f->sql->rows("sis_moodle_categories"));
        self::assertSame($history, $this->f->sql->rows("sis_history_events"));
    }
    public function testExplicitCategoryLinkRetainsEncryptedEvidenceAndCannotBeReplaced(): void
    {
        $c = $this->context();
        $ref = new EntityId($this->f->sql->rows("sis_subjects")[0]["id"]);
        try {
            $c[12]->link(
                $ref,
                new \Domain\Lms\ValueObject\MoodleExternalId("42"),
                false,
                "Verified category.",
            );
            self::fail();
        } catch (\Domain\Shared\Exception\ValidationException) {
        }
        self::assertSame([], $c[2]->categories);
        self::assertSame(
            ["linked" => true],
            $c[12]->link(
                $ref,
                new \Domain\Lms\ValueObject\MoodleExternalId("42"),
                true,
                "Verified independent mapping.",
            ),
        );
        $row = $this->f->sql->rows("sis_moodle_categories")[0];
        self::assertSame("42", $row["external_id"]);
        self::assertSame(0, (int) $row["is_owned"]);
        self::assertStringNotContainsString("independent", $row["encrypted_link"]);
        self::assertStringContainsString("independent", $this->f->dropCipher->open($row["encrypted_link"]));
        $this->expectException(ConcurrentModificationException::class);
        $c[12]->link($ref, new \Domain\Lms\ValueObject\MoodleExternalId("99"), true, "Different category.");
    }
    public function testDepartmentPolicyRoutesThroughThePublishedDepartment(): void
    {
        $c = $this->context(false);
        $c[12]->configure("departments", 1);
        [, $publication] = $this->queueCourse($c);
        $this->selectCoursePublication($publication["operation_id"]);
        self::assertSame("course_completed", $c[8]->runOne());
        self::assertSame("departments", $c[2]->categories[0]["source"]);
        self::assertSame("SCI", $c[2]->categories[0]["code"]);
        self::assertSame("20", $c[2]->courses[0]["course"]["category"]);
    }
    public function testChangedCategoryPolicyRejectsAStaleReviewWithoutAcademicWrites(): void
    {
        $c = $this->context();
        $w = $this->workflow($c);
        $r = $w->review($this->section->id, "2026-08-24", "2026-12-18", "Subject organization.", true);
        $history = $this->f->sql->rows("sis_history_events");
        $c[12]->configure("departments", 1);
        try {
            $w->publish($this->section->id, $r["review_token"]);
            self::fail();
        } catch (ConcurrentModificationException) {
        }
        self::assertSame($history, $this->f->sql->rows("sis_history_events"));
        self::assertSame([], $this->f->sql->rows("sis_moodle_course_requests"));
    }
    public function testAcceptedRequestKeepsOriginalRoutingAfterThePolicyChanges(): void
    {
        $c = $this->context(false);
        [, $r] = $this->queueCourse($c);
        $this->selectCoursePublication($r["operation_id"]);
        $c[12]->configure("departments", 1);
        self::assertSame("course_completed", $c[8]->runOne());
        self::assertSame("subjects", $c[2]->categories[0]["source"]);
        self::assertSame("category_synchronized", $c[12]->runOne());
        self::assertSame("departments", $c[2]->categories[1]["source"]);
        self::assertCount(1, $c[2]->courses);
        self::assertSame("20", $c[2]->courses[0]["course"]["category"]);
    }
    public function testCategoryChoiceRequiresCurrentMoodleAndCatalogAuthority(): void
    {
        $c = $this->context();
        $this->f->access->staff = false;
        $this->f->access->grants = ["sis:catalog:manage"];
        $this->expectException(AccessDeniedException::class);
        $c[12]->configure("departments", 1);
    }
    public function testPermissionLossDuringManualCategoryLinkRetainsItsReferenceWithoutCompleting(): void
    {
        $c = $this->context();
        $ref = new EntityId($this->f->sql->rows("sis_subjects")[0]["id"]);
        $c[2]->duringCategory = function (): void {
            $this->f->access->staff = false;
            $this->f->access->grants = [];
        };
        try {
            $c[12]->link(
                $ref,
                new \Domain\Lms\ValueObject\MoodleExternalId("42"),
                true,
                "Verified original category.",
            );
            self::fail();
        } catch (AccessDeniedException) {
        }
        $row = $this->f->sql->rows("sis_moodle_categories")[0];
        self::assertNull($row["external_id"]);
        self::assertSame("source_changed", $row["issue_code"]);
    }

    public function testUnchangedCategoryPollingAvoidsNetworkAndStillDetectsLaterChanges(): void
    {
        $c = $this->context();
        self::assertSame("category_synchronized", $c[12]->runOne());
        $this->f->clock->time = $this->f->clock->time->modify("+301 seconds");
        self::assertSame("category_unchanged", $c[12]->runOne());
        self::assertCount(1, $c[2]->categories);
        self::assertSame("idle", $c[12]->runOne());
    }
}
