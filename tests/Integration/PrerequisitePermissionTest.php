<?php

declare(strict_types=1);

namespace Tests\Integration;

use CatalogWorkflowFixture;
use Domain\Registration\PrerequisitePermission;
use Domain\Registration\Service\PrerequisitePermissionService;
use Domain\Registration\ValueObject\PrerequisitePermissionId;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class PrerequisitePermissionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/CatalogWorkflowFixture.php";
    }

    private function institution(bool $twoPrerequisites = false): array
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $student = $s->student()->id;
        $pursuit = $s->workPursuits()->activePursuit($student);
        $required = $s->section(faculty: $s->access->actor);
        $section = $s->section();
        $s->registration->addPrerequisite($section->courseId, $required->courseId);
        if ($twoPrerequisites) {
            $s->registration->addPrerequisite($section->courseId, $s->section()->courseId);
        }
        $version = $s->publishCourse($section->courseId);
        $review = $f->bindings()->review($section->id, $version, "Fictional selected course publication.");
        $f->bindings()->commit($section->id, $review["review_token"]);
        return [$s, $student, $pursuit, $section, $required, $f];
    }

    private function review(
        SisFixture $s,
        EntityId $student,
        EntityId $pursuit,
        EntityId $section,
        ?string $expires = "2026-08-20T17:00:00-04:00",
    ): array {
        $service = $s->prerequisitePermissions();
        return $service->review(
            $student,
            $pursuit,
            $section,
            $expires,
            $service->view($student, $pursuit, $section)["revision"],
            "Fictional registrar approval.",
        );
    }

    private function confirm(
        PrerequisitePermissionService $service,
        EntityId $student,
        EntityId $pursuit,
        EntityId $section,
        array $r,
    ): array {
        return $service->commit(
            $student,
            $pursuit,
            $section,
            new EntityId($r["stream_id"]),
            new EntityId($r["operation_id"]),
            $r["approval"],
            $r["revision"],
            $r["reason"],
            $r["review_token"],
        );
    }

    public function testApprovedRegistrationKeepsMissingCourseworkAndItsHistoryAfterRevocation(): void
    {
        [$s, $student, $pursuit, $section, $required] = $this->institution();
        $selection = new RegistrationBatchSelection($section->id, $student);
        self::assertSame(
            "missing",
            $s->registrationBatches()->review($selection)["students"][0]["prerequisites"]["status"],
        );
        $r = $this->review($s, $student, $pursuit, $section->id);
        $result = $this->confirm($s->prerequisitePermissions(), $student, $pursuit, $section->id, $r);
        self::assertSame("approved", $result["status"]);
        $ready = $s->registrationBatches()->review($selection)["students"][0]["prerequisites"];
        self::assertSame("permission", $ready["status"]);
        self::assertSame(1, $ready["missing_count"]);
        self::assertSame(["expires_at" => "2026-08-20T21:00:00Z"], $ready["permission"]);
        self::assertStringNotContainsString("Fictional registrar", json_encode($ready, JSON_THROW_ON_ERROR));
        $enrollment = $s->service->register($student, $section->id);
        self::assertCount(
            0,
            $s->sql->rows("sis_academic_credits", ["course_id" => $required->courseId->value]),
        );
        $events = iterator_to_array(
            \EventStoreFixture::framework($s->connection->queryBuilder())->getAggregateHistoryFor(
                new \Domain\Registration\ValueObject\EnrollmentHistoryId($enrollment->id->value),
            ),
        );
        $proof = $events[0]->metaParam("prerequisite_evidence");
        self::assertSame([], $proof["support"]);
        self::assertSame($r["stream_id"], $proof["permission"]["stream_id"]);
        $revocation = $this->review($s, $student, $pursuit, $section->id, null);
        self::assertSame(
            "revoked",
            $this->confirm($s->prerequisitePermissions(), $student, $pursuit, $section->id, $revocation)[
                "status"
            ],
        );
        $before = $s->sql->rows("sis_history_events");
        self::assertSame($enrollment->id->value, $s->service->register($student, $section->id)->id->value);
        self::assertSame(
            $result,
            $this->confirm($s->prerequisitePermissions(), $student, $pursuit, $section->id, $r),
        );
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        $root = PrerequisitePermission::reconstituteFromEventStream(
            \EventStoreFixture::framework($s->connection->queryBuilder())->getAggregateHistoryFor(
                new PrerequisitePermissionId($r["stream_id"]),
            ),
        );
        self::assertSame(2, $root->playhead());
        self::assertNull($root->state()["approval"]);
        self::assertCount(
            2,
            $s->prerequisitePermissions()->timeline($student, $pursuit, $section->id)["items"],
        );
    }

    public function testAnExceptionKeepsVerifiedCompletedCourseworkDistinctFromTheRemainingGap(): void
    {
        [$s, $student, $pursuit, $section, $required] = $this->institution(true);
        $s->legacyGrade(new \Domain\Gradebook\GradeDefinition(EntityId::generate(), "P", 0, true, false));
        $enrollment = $s->service->register($student, $required->id);
        $grade = new EntityId($s->gradeCatalog()->byCode("P", $student)["id"]);
        $posted = $s
            ->academicCredits()
            ->review(
                $student,
                $required->id,
                $enrollment->id,
                $grade,
                0,
                reason: "Fictional completed prerequisite.",
            );
        $s->academicCredits()->commit($student, $required->id, $posted["review_token"]);
        $this->confirm(
            $s->prerequisitePermissions(),
            $student,
            $pursuit,
            $section->id,
            $this->review($s, $student, $pursuit, $section->id),
        );
        $review = $s->registrationBatches()->review(new RegistrationBatchSelection($section->id, $student));
        $decision = $review["students"][0]["prerequisites"];
        self::assertSame("permission", $decision["status"]);
        self::assertSame(1, $decision["missing_count"]);
        self::assertContains("met", $decision["course_statuses"]);
        self::assertContains("missing", $decision["course_statuses"]);
        $accepted = $s->service->register($student, $section->id);
        $events = iterator_to_array(
            \EventStoreFixture::framework($s->connection->queryBuilder())->getAggregateHistoryFor(
                new \Domain\Registration\ValueObject\EnrollmentHistoryId($accepted->id->value),
            ),
        );
        self::assertCount(1, $events[0]->metaParam("prerequisite_evidence")["support"]);
    }

    public function testExpiryAndAnotherStudentOrSectionNeverReuseThePermission(): void
    {
        [$s, $student, $pursuit, $section] = $this->institution();
        $r = $this->review($s, $student, $pursuit, $section->id);
        $this->confirm($s->prerequisitePermissions(), $student, $pursuit, $section->id, $r);
        $course = $s->registration->courseForSection($section);
        $ledger = $s->prerequisitePermissionLedger();
        self::assertNotNull(
            $ledger->effective($student, $pursuit, $section->id, $s->term, $course->publicationVersionId),
        );
        self::assertNull(
            $ledger->effective(
                $student,
                EntityId::generate(),
                $section->id,
                $s->term,
                $course->publicationVersionId,
            ),
        );
        self::assertNull(
            $ledger->effective(
                $student,
                $pursuit,
                EntityId::generate(),
                $s->term,
                $course->publicationVersionId,
            ),
        );
        self::assertNull(
            $ledger->effective(
                $student,
                $pursuit,
                $section->id,
                EntityId::generate(),
                $course->publicationVersionId,
            ),
        );
        self::assertNull(
            $ledger->effective($student, $pursuit, $section->id, $s->term, EntityId::generate()),
        );
        $s->clock->time = new \DateTimeImmutable($r["approval"]["expires_at"]);
        self::assertNull(
            $ledger->effective($student, $pursuit, $section->id, $s->term, $course->publicationVersionId),
        );
        self::assertSame(
            "missing",
            $s->registrationBatches()->review(new RegistrationBatchSelection($section->id, $student))[
                "students"
            ][0]["prerequisites"]["status"],
        );
    }

    public function testRevocationInvalidatesPendingBatchAndKeepsSeatsChargesAndCreditsAtomic(): void
    {
        [$s, $student, $pursuit, $section] = $this->institution();
        $this->confirm(
            $s->prerequisitePermissions(),
            $student,
            $pursuit,
            $section->id,
            $this->review($s, $student, $pursuit, $section->id),
        );
        $batch = $s->registrationBatches();
        $selection = new RegistrationBatchSelection($section->id, $student);
        $review = $batch->review($selection);
        $this->confirm(
            $s->prerequisitePermissions(),
            $student,
            $pursuit,
            $section->id,
            $this->review($s, $student, $pursuit, $section->id, null),
        );
        $before = $s->sql->rows("sis_history_events");
        try {
            $batch->commit(
                new EntityId($review["operation_id"]),
                $selection,
                $review["revision"],
                $review["review_token"],
            );
            self::fail("A revoked exception was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows("sis_history_events"));
            self::assertNull($s->registration->enrollment($student, $section->id));
        }
    }

    public function testExplicitDutyAndStudentReadAreBothRequiredEvenForTheOwner(): void
    {
        [$s, $student, $pursuit, $section] = $this->institution();
        $s->access->staff = false;
        foreach ([[], [PrerequisitePermissionService::PERMISSION], ["sis:student:read"]] as $grants) {
            $s->access->grants = $grants;
            try {
                $s->prerequisitePermissions()->view($student, $pursuit, $section->id);
                self::fail("Missing independent registrar duty was ignored.");
            } catch (AccessDeniedException) {
                self::assertSame([], $s->sql->rows("sis_prerequisite_permissions"));
            }
        }
    }

    public function testExpiryMustBeFutureExplicitAndWithinTheTermAndReviewIsBoundToItsActor(): void
    {
        [$s, $student, $pursuit, $section] = $this->institution();
        $dates = ["2026-08-20T17:00", "2026-02-30T17:00:00Z", "2026-08-01T17:00:00Z", "2027-01-01T17:00:00Z"];
        foreach ($dates as $date) {
            try {
                $this->review($s, $student, $pursuit, $section->id, $date);
                self::fail("Invalid expiry was accepted.");
            } catch (ValidationException) {
                self::assertSame([], $s->sql->rows("sis_prerequisite_permissions"));
            }
        }
        $review = $this->review($s, $student, $pursuit, $section->id);
        $s->access->actor = $s->user();
        $this->expectException(AccessDeniedException::class);
        $this->confirm($s->prerequisitePermissions(), $student, $pursuit, $section->id, $review);
    }

    public function testOwnerAndReviewedBatchBothUseTheSharedApprovalAndOtherEligibilityStillBlocks(): void
    {
        [$s, $student, $pursuit, $section] = $this->institution();
        $this->confirm(
            $s->prerequisitePermissions(),
            $student,
            $pursuit,
            $section->id,
            $this->review($s, $student, $pursuit, $section->id),
        );
        $s->access->staff = false;
        $owner = $s->service->register($student, $section->id);
        self::assertSame($section->id->value, $owner->sectionId->value);
        $s->access->staff = true;
        $second = $s->student()->id;
        $otherPursuit = $s->workPursuits()->activePursuit($second);
        $this->confirm(
            $s->prerequisitePermissions(),
            $second,
            $otherPursuit,
            $section->id,
            $this->review($s, $second, $otherPursuit, $section->id),
        );
        $batch = $s->registrationBatches();
        $selection = new RegistrationBatchSelection($section->id, $second);
        $r = $batch->review($selection);
        self::assertSame(
            1,
            $batch->commit(new EntityId($r["operation_id"]), $selection, $r["revision"], $r["review_token"])[
                "created"
            ],
        );
        self::assertCount(2, $s->sql->rows("sis_enrollments", ["section_id" => $section->id->value]));
    }

    public function testApprovalDoesNotBypassCapacityOrCorequisites(): void
    {
        [$s, $student, $pursuit, $section, $required, $f] = $this->institution();
        $this->confirm(
            $s->prerequisitePermissions(),
            $student,
            $pursuit,
            $section->id,
            $this->review($s, $student, $pursuit, $section->id),
        );
        $s->sql->update("sis_sections", ["capacity" => 1], ["id" => $section->id->value]);
        $other = $s->student()->id;
        $otherPursuit = $s->workPursuits()->activePursuit($other);
        $this->confirm(
            $s->prerequisitePermissions(),
            $other,
            $otherPursuit,
            $section->id,
            $this->review($s, $other, $otherPursuit, $section->id),
        );
        $s->service->register($other, $section->id);
        $before = $s->sql->rows("sis_history_events");
        try {
            $s->service->register($student, $section->id);
            self::fail("Capacity was bypassed.");
        } catch (\DomainException) {
            self::assertSame($before, $s->sql->rows("sis_history_events"));
        }
        $section = $s->section();
        $s->registration->addPrerequisite($section->courseId, $required->courseId);
        $lab = $s->section();
        $drafts = new \Domain\Catalog\Service\CoursePrerequisiteService(
            new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlCatalogWorkflowRepository($s->sql),
            $s->access,
            $s->transactions,
            $s->audit,
        );
        $draft = $drafts->current($section->courseId);
        $drafts->save(
            $section->courseId,
            $draft["groups"],
            $draft["revision"],
            $draft["minimums"],
            $draft["grade_points_milli"],
            [$lab->courseId->value],
            $draft["minimum_requirements"],
            $draft["rule"],
        );
        $version = $s->publishCourse($section->courseId);
        $binding = $f->bindings()->review($section->id, $version, "Fictional lab requirement.");
        $f->bindings()->commit($section->id, $binding["review_token"]);
        $this->confirm(
            $s->prerequisitePermissions(),
            $student,
            $pursuit,
            $section->id,
            $this->review($s, $student, $pursuit, $section->id),
        );
        $this->expectException(ValidationException::class);
        $s->service->register($student, $section->id);
    }

    public function testProjectionTamperingCannotGrantRegistration(): void
    {
        [$s, $student, $pursuit, $section] = $this->institution();
        $review = $this->review($s, $student, $pursuit, $section->id);
        $this->confirm($s->prerequisitePermissions(), $student, $pursuit, $section->id, $review);
        $s->sql->update("sis_prerequisite_permissions", ["position" => 2], ["id" => $review["stream_id"]]);
        $this->expectException(CorruptHistoryException::class);
        $s->service->register($student, $section->id);
    }
    public function testProgramChangeLeavesOldApprovalReadableWithoutReusingItAndRetainsTheOriginalReceipt(): void
    {
        [$s, $student, $pursuit, $section] = $this->institution();
        $review = $this->review($s, $student, $pursuit, $section->id);
        $receipt = $this->confirm($s->prerequisitePermissions(), $student, $pursuit, $section->id, $review);
        $program = EntityId::generate();
        $s->sql->insert("sis_programs", [
            "id" => $program->value,
            "code" => "NEXT.PROGRAM",
            "title" => "Fictional next program",
        ]);
        $record = $s->admissions->student($student);
        $revision = hash(
            "sha256",
            json_encode(
                [
                    $record->id->value,
                    $record->personId->value,
                    $record->programId->value,
                    $record->entryTermId->value,
                    $record->active,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
        $change = $s
            ->programAssignmentService()
            ->reviewChange($student, $program, $s->term, $revision, "Fictional program change.");
        $s->programAssignmentService()->changeProgram($student, $change["review_token"]);
        self::assertFalse($s->prerequisitePermissions()->view($student, $pursuit, $section->id)["editable"]);
        self::assertSame(
            $receipt,
            $this->confirm($s->prerequisitePermissions(), $student, $pursuit, $section->id, $review),
        );
        self::assertSame(
            "missing",
            $s->registrationBatches()->review(new RegistrationBatchSelection($section->id, $student))[
                "students"
            ][0]["prerequisites"]["status"],
        );
        $this->expectException(ValidationException::class);
        $this->review($s, $student, $pursuit, $section->id, null);
    }

    public function testScheduleConfirmationUsesTheSameException(): void
    {
        [$s, $student, $pursuit, $section] = $this->institution();
        $this->confirm(
            $s->prerequisitePermissions(),
            $student,
            $pursuit,
            $section->id,
            $this->review($s, $student, $pursuit, $section->id),
        );
        $plan = new \Domain\Registration\ValueObject\RegistrationPlanSelection(
            $student,
            $section->id,
            $s->section()->id,
        );
        $service = $s->registrationBatches();
        $review = $service->reviewPlan($plan, true);
        self::assertSame(
            2,
            $service->commitPlan(
                new EntityId($review["operation_id"]),
                $plan,
                $review["revision"],
                $review["review_token"],
                true,
            )["created"],
        );
    }

    public function testRevocationDuringFinalBatchAuditRollsBackEveryCoupledFact(): void
    {
        [$s, $student, $pursuit, $section] = $this->institution();
        $this->confirm(
            $s->prerequisitePermissions(),
            $student,
            $pursuit,
            $section->id,
            $this->review($s, $student, $pursuit, $section->id),
        );
        $revocation = $this->review($s, $student, $pursuit, $section->id, null);
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit
            ->expects(self::once())
            ->method("record")
            ->willReturnCallback(function (
                EntityId $actor,
                string $action,
                EntityId $subject,
            ) use (
                $s,
                $student,
                $pursuit,
                $section,
                $revocation,
            ): void {
                $s->audit->record($actor, $action, $subject);
                $this->confirm($s->prerequisitePermissions(), $student, $pursuit, $section->id, $revocation);
            });
        $batch = $s->registrationBatches($audit);
        $selection = new RegistrationBatchSelection($section->id, $student);
        $review = $batch->review($selection);
        $tables = [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_enrollments",
            "sis_academic_credits",
            "sis_student_accounts",
            "sis_registration_batches",
            "sis_prerequisite_permissions",
            "sis_audit",
        ];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $s->sql->rows($table);
        }
        try {
            $batch->commit(
                new EntityId($review["operation_id"]),
                $selection,
                $review["revision"],
                $review["review_token"],
            );
            self::fail("A permission revoked during completion was accepted.");
        } catch (\DomainException | ConcurrentModificationException $e) {
            foreach ($tables as $table) {
                self::assertSame($before[$table], $s->sql->rows($table), $table);
            }
        }
    }

    public function testExpiryDuringApprovalAuditRollsBackTheEventProjectionAndAudit(): void
    {
        [$s, $student, $pursuit, $section] = $this->institution();
        $review = $this->review($s, $student, $pursuit, $section->id);
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit
            ->expects(self::once())
            ->method("record")
            ->willReturnCallback(static function () use ($s, $review): void {
                $s->clock->time = new \DateTimeImmutable($review["approval"]["expires_at"])->setTimezone(
                    $s->clock->now()->getTimezone(),
                );
            });
        $before = $s->sql->rows("sis_history_events");
        try {
            $this->confirm($s->prerequisitePermissions($audit), $student, $pursuit, $section->id, $review);
            self::fail("Expired approval was committed.");
        } catch (ValidationException) {
            self::assertSame($before, $s->sql->rows("sis_history_events"));
            self::assertSame([], $s->sql->rows("sis_prerequisite_permissions"));
        }
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
        $migration = new \CreateSisPrerequisitePermissions("prerequisite-schema");
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
            self::assertStringContainsString("spp_scope_uq", $sql);
            self::assertStringContainsString("approval", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/AUTO_INCREMENT|sis_history_events|DELETE FROM/i",
                $sql,
            );
        }
    }
}
