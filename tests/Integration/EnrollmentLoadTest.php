<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\EnrollmentLoadPolicyService;
use Domain\Student\ValueObject\EnrollmentLoadBands;
use Domain\Student\ValueObject\EnrollmentLoadPolicyId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class EnrollmentLoadTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function bands(int $full = 12000): array
    {
        return [
            "bands" => [
                ["code" => "PART", "label" => "Part time", "min_credits_milli" => 0],
                ["code" => "FULL", "label" => "Full time", "min_credits_milli" => $full],
            ],
        ];
    }

    private function publish(
        EnrollmentLoadPolicyService $service,
        ?EntityId $program,
        ?array $definition,
    ): array {
        return $this->confirm(
            $service,
            $service->review(
                $program,
                $definition,
                $service->view($program)["revision"],
                "Fictional institutional credit policy.",
            ),
        );
    }

    private function confirm(EnrollmentLoadPolicyService $service, array $r): array
    {
        return $service->publish(
            $r["program_id"] === null ? null : new EntityId($r["program_id"]),
            new EntityId($r["operation_id"]),
            $r["definition"],
            $r["revision"],
            $r["reason"],
            $r["review_token"],
        );
    }

    private function pursuit(SisFixture $s, EntityId $student): EntityId
    {
        return new EntityId(
            $s->sql->rows("sis_student_programs", ["student_id" => $student->value], 1)[0]["id"],
        );
    }

    public function testExactBoundariesUseInstitutionBandsInsteadOfHardCodedThresholds(): void
    {
        $bands = new EnrollmentLoadBands($this->bands(6250));
        self::assertSame("PART", $bands->classify(6249)["code"]);
        self::assertSame("FULL", $bands->classify(6250)["code"]);
        self::assertSame("PART", $bands->classify(0)["code"]);
        $this->expectException(ValidationException::class);
        new EnrollmentLoadBands($this->bands(0));
    }

    public function testPublishOverrideRestoreAndFrameworkReplayShareOneJournal(): void
    {
        $s = new SisFixture();
        $service = $s->loadPolicies();
        $before = count($s->sql->rows("sis_history_events"));
        $this->publish($service, null, $this->bands());
        self::assertSame("global", $service->resolve($s->program)["source"]);
        $this->publish($service, $s->program, $this->bands(6000));
        self::assertSame(6000, $service->resolve($s->program)["definition"]["bands"][1]["min_credits_milli"]);
        $this->publish($service, $s->program, null);
        self::assertSame("global", $service->resolve($s->program)["source"]);
        self::assertCount($before + 3, $s->sql->rows("sis_history_events"));
        $root = \Domain\Student\EnrollmentLoadPolicy::reconstituteFromEventStream(
            \EventStoreFixture::framework($s->connection->queryBuilder())->getAggregateHistoryFor(
                new EnrollmentLoadPolicyId($s->program->value),
            ),
        );
        self::assertSame(2, $root->playhead());
        self::assertNull($root->state()["definition"]);
        self::assertCount(2, $service->timeline($s->program)["items"]);
    }

    public function testRepeatedConfirmationReturnsOriginalResultAfterAnotherPublication(): void
    {
        $s = new SisFixture();
        $service = $s->loadPolicies();
        $review = $service->review(null, $this->bands(), $service->view()["revision"], "Fictional review.");
        $receipt = $this->confirm($service, $review);
        $this->publish($service, null, $this->bands(9000));
        $before = $s->sql->rows("sis_history_events");
        self::assertSame($receipt, $this->confirm($service, $review));
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        self::assertSame(9000, $service->view()["effective"]["definition"]["bands"][1]["min_credits_milli"]);
    }

    public function testGlobalChangeInvalidatesReviewedProgramOverride(): void
    {
        $s = new SisFixture();
        $service = $s->loadPolicies();
        $review = $service->review(
            $s->program,
            $this->bands(6000),
            $service->view($s->program)["revision"],
            "Fictional review.",
        );
        $this->publish($service, null, $this->bands());
        $this->expectException(ConcurrentModificationException::class);
        $this->confirm($service, $review);
    }

    public function testRuntimeReviewUsesRegisteredCreditsAndNeverAddsBusinessEvents(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $pursuit = $this->pursuit($s, $student->id);
        $section = $s->section();
        $s->service->register($student->id, $section->id);
        $this->publish($s->loadPolicies(), null, $this->bands(3000));
        $s->sql->update(
            "sis_courses",
            ["credits_milli" => 99000, "title" => "New catalog title"],
            ["id" => $section->courseId->value],
        );
        $before = $s->sql->rows("sis_history_events");
        $result = $s->enrollmentLoad()->review($student->id, $pursuit, $s->term, "staff");
        self::assertSame("ready", $result["status"]);
        self::assertSame(3000, $result["credits_milli"]);
        self::assertSame("FULL", $result["classification"]["code"]);
        self::assertSame("Computing", $result["items"][0]["course_title"]);
        self::assertFalse($result["official"]);
        self::assertSame($before, $s->sql->rows("sis_history_events"));
    }

    public function testNoRegistrationsAndUnconfiguredPolicyAreDistinct(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $pursuit = $this->pursuit($s, $student->id);
        $service = $s->enrollmentLoad();
        $empty = $service->review($student->id, $pursuit, $s->term, "owner");
        self::assertSame("not_registered", $empty["status"]);
        self::assertSame(0, $empty["credits_milli"]);
        self::assertNull($empty["classification"]);
        $s->service->register($student->id, $s->section()->id);
        $ready = $service->review($student->id, $pursuit, $s->term, "owner");
        self::assertSame(3000, $ready["credits_milli"]);
        self::assertNull($ready["classification"]);
        self::assertSame("not_configured", $ready["policy"]["source"]);
    }

    public function testMissingTrackedEnrollmentWithholdsPartialTotal(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $pursuit = $this->pursuit($s, $student->id);
        $first = $s->service->register($student->id, $s->section()->id);
        $s->service->register($student->id, $s->section()->id);
        $s->sql->execute("DELETE FROM sis_enrollments WHERE id = ?", [$first->id->value]);
        $result = $s->enrollmentLoad()->review($student->id, $pursuit, $s->term, "staff");
        self::assertSame("needs_review", $result["status"]);
        self::assertNull($result["credits_milli"]);
        self::assertNull($result["classification"]);
    }

    public function testPolicyProjectionTamperingWithholdsClassification(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $pursuit = $this->pursuit($s, $student->id);
        $this->publish($s->loadPolicies(), null, $this->bands());
        $s->sql->update(
            "sis_enrollment_load_policies",
            ["definition" => json_encode($this->bands(3000))],
            ["id" => EnrollmentLoadPolicyId::GLOBAL],
        );
        $result = $s->enrollmentLoad()->review($student->id, $pursuit, $s->term, "owner");
        self::assertSame("needs_review", $result["status"]);
        self::assertNull($result["credits_milli"]);
    }

    public function testOwnerAccessDoesNotInheritStaffPermission(): void
    {
        $s = new SisFixture();
        $student = $s->student($s->user());
        $this->expectException(AccessDeniedException::class);
        $s->enrollmentLoad()->review($student->id, $this->pursuit($s, $student->id), $s->term, "owner");
    }

    public function testPolicyPermissionIsIndependentAndNotGrantedToExistingRoles(): void
    {
        $s = new SisFixture();
        $permission = $s->sql->rows("sis_access_permissions", ["name" => "sis:load:policy"], 1)[0];
        self::assertSame(1, (int) $permission["is_system"]);
        self::assertSame(
            [],
            $s->sql->rows("sis_access_role_permissions", ["permission_id" => $permission["id"]]),
        );
        $s->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $s->loadPolicies()->view();
    }
    public function testEarlyAndLateDropsAreExcludedWithoutReturningRestrictedHistory(): void
    {
        foreach ([false, true] as $late) {
            $s = new SisFixture();
            $student = $s->student();
            $pursuit = $this->pursuit($s, $student->id);
            $section = $s->section();
            $s->service->register($student->id, $section->id);
            if ($late) {
                $s->clock->time = new \DateTimeImmutable("2026-09-01T10:00:00-07:00");
            }
            $s->drops->drop($student->id, $section->id);
            $events = $s->sql->rows("sis_history_events");
            $finance = $s->financialEvents();
            $result = $s->enrollmentLoad()->review($student->id, $pursuit, $s->term, "staff");
            self::assertSame("not_registered", $result["status"]);
            self::assertSame(0, $result["credits_milli"]);
            self::assertSame([], $result["items"]);
            self::assertSame($events, $s->sql->rows("sis_history_events"));
            self::assertSame($finance, $s->financialEvents());
        }
    }

    public function testProgramChangeKeepsAllActiveTermRegistrationsAndUsesNewPrimaryPolicy(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $old = $this->pursuit($s, $student->id);
        $s->service->register($student->id, $s->section()->id);
        $next = EntityId::generate();
        $s->sql->insert("sis_programs", [
            "id" => $next->value,
            "code" => "NEXT",
            "title" => "Fictional next program",
        ]);
        $this->publish($s->loadPolicies(), null, $this->bands(12000));
        $this->publish($s->loadPolicies(), $next, $this->bands(3000));
        $revision = hash(
            "sha256",
            json_encode(
                [
                    $student->id->value,
                    $student->personId->value,
                    $student->programId->value,
                    $student->entryTermId->value,
                    $student->active,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
        $review = $s
            ->programAssignmentService()
            ->reviewChange($student->id, $next, $s->term, $revision, "Fictional program change.");
        $changed = $s->programAssignmentService()->changeProgram($student->id, $review["review_token"]);
        $result = $s
            ->enrollmentLoad()
            ->review($student->id, new EntityId($changed["assignment_id"]), $s->term, "staff");
        self::assertSame(3000, $result["credits_milli"]);
        self::assertSame("FULL", $result["classification"]["code"]);
        self::assertSame("program", $result["policy"]["source"]);
        self::assertSame($next->value, $result["program"]["id"]);
        $this->expectException(ValidationException::class);
        $s->enrollmentLoad()->review($student->id, $old, $s->term, "staff");
    }

    public function testAcademicAwardCorrectionDoesNotReplaceOriginallyRegisteredUnits(): void
    {
        $s = new SisFixture();
        $s->gradeScale([
            [
                "code" => "A",
                "min_score_milli" => 0,
                "max_score_milli" => 100000,
                "points_milli" => 4000,
                "passes" => true,
                "counts_gpa" => true,
            ],
        ]);
        $student = $s->student();
        $pursuit = $this->pursuit($s, $student->id);
        $section = $s->section(faculty: $s->access->actor);
        $enrollment = $s->service->register($student->id, $section->id);
        $grade = new EntityId($s->sql->rows("sis_grades", [], 1)[0]["id"]);
        $initial = $s->academicCredits()->review($student->id, $section->id, $enrollment->id, $grade, 0);
        $s->academicCredits()->commit($student->id, $section->id, $initial["review_token"]);
        $review = $s
            ->academicCredits()
            ->review(
                $student->id,
                $section->id,
                $enrollment->id,
                $grade,
                1,
                reason: "Fictional credit award correction.",
                attemptedMilli: 6000,
            );
        $s->academicCredits()->commit($student->id, $section->id, $review["review_token"]);
        self::assertSame(
            6000,
            (int) $s->sql->one("sis_academic_credits", ["source_id" => $enrollment->id->value])[
                "attempted_milli"
            ],
        );
        $result = $s->enrollmentLoad()->review($student->id, $pursuit, $s->term, "staff");
        self::assertSame("ready", $result["status"]);
        self::assertSame(3000, $result["credits_milli"]);
    }

    public function testUnknownOpeningUnitsAreNotInventedFromCurrentRows(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section();
        $id = EntityId::generate();
        $s->sql->insert("sis_enrollments", [
            "id" => $id->value,
            "student_id" => $student->id->value,
            "section_id" => $section->id->value,
            "term_id" => $s->term->value,
            "status" => "A",
            "registered_at" => "2026-08-01T17:00:00Z",
            "status_at" => "2026-08-01T17:00:00Z",
            "version" => 0,
        ]);
        $s->sql->insert("sis_academic_credits", [
            "id" => EntityId::generate()->value,
            "student_id" => $student->id->value,
            "source_id" => $id->value,
            "course_id" => $section->courseId->value,
            "term_id" => $s->term->value,
            "credit_type" => "institutional",
            "course_code" => "OLD.101",
            "course_title" => "Observed course",
            "attempted_milli" => 3000,
            "completed_milli" => 0,
            "grade_points_milli" => 0,
            "grade_id" => null,
            "status" => "A",
            "version" => 0,
        ]);
        $events = $s->sql->rows("sis_history_events");
        $result = $s
            ->enrollmentLoad()
            ->review($student->id, $this->pursuit($s, $student->id), $s->term, "staff");
        self::assertSame("needs_review", $result["status"]);
        self::assertNull($result["credits_milli"]);
        self::assertNull($result["items"][0]["credits_milli"]);
        self::assertSame($events, $s->sql->rows("sis_history_events"));
    }

    public function testFinalAuditFailureAuthorityLossAndExpiryRollBackPublication(): void
    {
        foreach (["audit", "authority", "expiry"] as $failure) {
            $s = new SisFixture();
            $service = $s->loadPolicies();
            $review = $service->review(
                null,
                $this->bands(),
                $service->view()["revision"],
                "Fictional review.",
            );
            $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
            $audit->method("record")->willReturnCallback(static function () use ($s, $failure): void {
                if ($failure === "authority") {
                    $s->access->staff = false;
                } elseif ($failure === "expiry") {
                    $s->clock->time = $s->clock->time->modify("+20 minutes");
                } else {
                    throw new \RuntimeException("Fictional required audit failure.");
                }
            });
            $before = $s->sql->rows("sis_history_events");
            $projection = $s->sql->rows("sis_enrollment_load_policies");
            try {
                $this->confirm($s->loadPolicies($audit), $review);
                self::fail("A failed publication committed.");
            } catch (\RuntimeException | ValidationException $e) {
                self::assertNotSame("A failed publication committed.", $e->getMessage());
                self::assertSame($before, $s->sql->rows("sis_history_events"));
                self::assertSame($projection, $s->sql->rows("sis_enrollment_load_policies"));
            }
        }
    }

    public function testZeroCreditRegistrationIsNotReportedAsNoRegistration(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section();
        $s->sql->update("sis_courses", ["credits_milli" => 0], ["id" => $section->courseId->value]);
        $s->service->register($student->id, $section->id);
        $this->publish($s->loadPolicies(), null, $this->bands());
        $result = $s
            ->enrollmentLoad()
            ->review($student->id, $this->pursuit($s, $student->id), $s->term, "staff");
        self::assertSame("ready", $result["status"]);
        self::assertSame(0, $result["credits_milli"]);
        self::assertSame("PART", $result["classification"]["code"]);
    }

    public function testPickerPaginationIsBoundedAndLiteral(): void
    {
        $s = new SisFixture();
        for ($i = 0; $i < 23; $i++) {
            $s->sql->insert("sis_programs", [
                "id" => EntityId::generate()->value,
                "code" => "LOAD." . $i,
                "title" => "Fictional program",
            ]);
            $s->sql->insert("sis_terms", [
                "id" => EntityId::generate()->value,
                "code" => "LOAD." . $i,
                "starts_on" => "2028-08-01",
                "ends_on" => "2028-12-01",
                "add_drop_ends_on" => "2028-08-15",
            ]);
        }
        $programs = $s->loadPolicies()->programs("LOAD.");
        self::assertCount(20, $programs["items"]);
        self::assertCount(
            3,
            $s->loadPolicies()->programs("LOAD.", new EntityId($programs["next_after"]))["items"],
        );
        self::assertSame([], $s->loadPolicies()->programs("%")["items"]);
        $repository = new \Infrastructure\Persistence\Repository\SqlEnrollmentLoadRepository($s->sql);
        $terms = $repository->terms("LOAD.", null);
        self::assertCount(20, $terms["items"]);
        self::assertCount(3, $repository->terms("LOAD.", new EntityId($terms["next_after"]))["items"]);
        self::assertSame([], $repository->terms("_", null)["items"]);
    }

    public function testMigrationIsIdempotentPortableAndPreservesRecordedFactsAndGrants(): void
    {
        $s = new SisFixture();
        $definitions = [];
        $schema = $this->createStub(\Qubus\Expressive\Schema::class);
        $schema->method("hasTable")->willReturn(false);
        $schema
            ->method("create")
            ->willReturnCallback(static function (string $name, callable $build) use (&$definitions): void {
                $table = new \Qubus\Expressive\Schema\CreateTable($name);
                $build($table);
                $definitions[] = $table;
            });
        $connection = $this->createStub(\Qubus\Expressive\Connection::class);
        $connection->method("getSchema")->willReturn($schema);
        $connection->method("queryBuilder")->willReturn($s->connection->queryBuilder());
        $connection
            ->method("quoteIdentifier")
            ->willReturnCallback(static fn(string $id): string => '"' . $id . '"');
        $migration = new \CreateSisEnrollmentLoadPolicies("load-schema");
        $migration->setObjectMap(
            new \ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $connection,
                    "migration",
                ),
            ]),
        );
        $before = $s->sql->rows("sis_history_events");
        $grants = $s->sql->rows("sis_access_role_permissions");
        $migration->up();
        $migration->up();
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        self::assertSame($grants, $s->sql->rows("sis_access_role_permissions"));
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $sql = json_encode(new $class($connection)->create($definitions[0]), JSON_THROW_ON_ERROR);
            self::assertStringContainsString("selp_program_fk", $sql);
            self::assertStringContainsString("definition", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/AUTO_INCREMENT|sis_history_events|DELETE FROM/i",
                $sql,
            );
        }
    }
}
