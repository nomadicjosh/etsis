<?php

declare(strict_types=1);
require_once dirname(__DIR__) . "/Support/StudentRequirementsFixture.php";
use Domain\DegreeAudit\Service\RequirementAdjustmentService;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
final class RequirementAdjustmentTest extends TestCase
{
    private StudentRequirementsFixture $f;
    private RequirementAdjustmentService $service;
    protected function setUp(): void
    {
        $this->f = new StudentRequirementsFixture();
        $this->f->assign();
        $this->service = $this->service();
    }
    private function service(?\Domain\Audit\Repository\AuditTrail $audit = null): RequirementAdjustmentService
    {
        $f = $this->f;
        $s = $f->f->sis;
        return new RequirementAdjustmentService(
            new \Infrastructure\Persistence\Repository\SqlRequirementAdjustmentRepository($s->sql),
            $f->service,
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($s->sql),
            $f->f->history,
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $f->f->catalog->fixture->cipher,
            $s->clock,
            new \Domain\DegreeAudit\Service\PublishedRequirementsReader($f->f->history),
            new \Infrastructure\Persistence\Repository\SqlReplacementCourseRepository($s->sql),
        );
    }
    private function review(array $d): array
    {
        return $this->service->reviewGrant(
            $this->f->student,
            $this->f->assignment,
            $d,
            "Fictional registrar exception approval",
        );
    }
    private function commit(array $r): array
    {
        return $this->service->commit($this->f->student, $this->f->assignment, $r["review_token"]);
    }
    private function waive(): array
    {
        return $this->review([
            "kind" => "waiver",
            "course_id" => $this->f->f->groupDefinition["courses"][0]["course_id"],
        ]);
    }
    private function minimum(
        int $value = 108000,
        ?string $group = null,
        string $key = "min_credits_milli",
    ): array {
        return $this->review([
            "kind" => "minimum",
            "group_id" => $group,
            "minimum" => $key,
            "required_milli" => $value,
        ]);
    }
    private function view(): array
    {
        return $this->service->view($this->f->student, $this->f->assignment);
    }
    public function testWaiverTargetsCourseIdentityWithoutAGroupAndChangesNoEarnedCreditOrMinimum(): void
    {
        $f = $this->f;
        $before = $f->f->digest("sis_history_events");
        $r = $this->waive();
        self::assertSame($before, $f->f->digest("sis_history_events"));
        self::assertArrayNotHasKey("group_id", $r["adjustment"]["definition"]);
        self::assertSame(["General education"], $r["affected_groups"]);
        $this->commit($r);
        $v = $this->view();
        self::assertSame(
            [$f->f->groupDefinition["courses"][0]["course_id"]],
            $v["effective_minimums"]["waived_course_ids"],
        );
        self::assertSame(120000, $v["effective_minimums"]["degree"]["min_credits_milli"]);
        self::assertSame(12000, $v["effective_minimums"]["groups"][$f->f->group->value]["min_credits_milli"]);
        self::assertSame([], $f->f->sis->sql->rows("sis_academic_credits"));
        self::assertSame([], $f->f->sis->sql->rows("sis_student_accounts"));
        $raw = json_encode($f->f->sis->sql->rows("sis_history_events"), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString("Fictional registrar exception approval", $raw);
    }
    public function testMinimumExceptionUsesExplicitNewValueAndRetainsPublicationUnchanged(): void
    {
        $before = $this->f->f->degrees->version($this->f->f->degree, $this->f->version);
        $r = $this->minimum();
        self::assertSame(120000, $r["adjustment"]["definition"]["original_milli"]);
        $this->commit($r);
        self::assertSame(108000, $this->view()["effective_minimums"]["degree"]["min_credits_milli"]);
        self::assertSame($before, $this->f->f->degrees->version($this->f->f->degree, $this->f->version));
        $this->commit($this->minimum(9000, $this->f->f->group->value));
        self::assertSame(
            9000,
            $this->view()["effective_minimums"]["groups"][$this->f->f->group->value]["min_credits_milli"],
        );
    }
    public function testReplacingAnExceptionAndRevokingItPreserveEarlierFactsAndRetryReceipts(): void
    {
        $a = $this->minimum();
        $receipt = $this->commit($a);
        $b = $this->minimum(102000);
        self::assertSame($a["adjustment"]["adjustment_id"], $b["adjustment"]["supersedes_adjustment_id"]);
        $this->commit($b);
        self::assertCount(1, $this->view()["items"]);
        $r = $this->service->reviewRevoke(
            $this->f->student,
            $this->f->assignment,
            new EntityId($b["adjustment"]["adjustment_id"]),
            "Fictional restoration",
        );
        $this->commit($r);
        self::assertSame(120000, $this->view()["effective_minimums"]["degree"]["min_credits_milli"]);
        self::assertSame([], $this->view()["items"]);
        $audit = $this->f->f->digest("sis_audit");
        self::assertSame($receipt, $this->commit($a));
        self::assertSame($audit, $this->f->f->digest("sis_audit"));
        $history = $this->service->history($this->f->student, $this->f->assignment)["items"];
        self::assertSame(["grant", "grant", "revoke"], array_column($history, "action"));
        self::assertArrayNotHasKey("reason", $history[0]);
        self::assertArrayNotHasKey("actor_id", $history[0]);
    }
    public function testWaiverSurvivesRequirementVersionChangesWithinTheSamePursuitButMinimumDoesNotCarry(): void
    {
        $this->commit($this->waive());
        $this->commit($this->minimum());
        $f = $this->f;
        $d = $f->f->definition;
        $d["min_credits_milli"] = 123000;
        $f->f->degrees->save($f->f->degree, 1, new AuditRequirementsV3($d));
        $v = $f->f->publishDegree();
        $f->version = new EntityId($v["operation_id"]);
        $f->assign();
        $view = $this->view();
        self::assertSame(123000, $view["effective_minimums"]["degree"]["min_credits_milli"]);
        self::assertCount(1, $view["effective_minimums"]["waived_course_ids"]);
        self::assertSame([true, false], array_column($view["items"], "applicable"));
    }
    public function testReadOwnerCanInspectWithoutOfficeAuthorityAndOtherStudentCannotRead(): void
    {
        $this->commit($this->waive());
        $a = $this->f->f->sis->access;
        $a->staff = false;
        self::assertCount(1, $this->view()["items"]);
        try {
            $this->waive();
            self::fail("Student approved their own waiver.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $a->actor = $this->f->f->sis->user();
        $this->expectException(AccessDeniedException::class);
        $this->view();
    }
    public function testAdjustmentPermissionIsSeparateFromCatalogRegistrationAndRequirementAssignment(): void
    {
        $a = $this->f->f->sis->access;
        $a->staff = false;
        $a->grants = [
            "sis:catalog:manage",
            "sis:registration:manage",
            "sis:student:read",
            "sis:degree-audit:assign",
        ];
        try {
            $this->minimum();
            self::fail("Other office permissions approved an exception.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $a->grants = [RequirementAdjustmentService::PERMISSION];
        try {
            $this->minimum();
            self::fail("Student-read authority bypassed.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $a->grants[] = "sis:student:read";
        $this->commit($this->minimum());
        self::assertCount(1, $this->view()["items"]);
    }
    public function testStaleRequirementChoiceAndCompetingReviewsRollBack(): void
    {
        $r = $this->minimum();
        $this->f->assign();
        $before = $this->f->f->digest("sis_history_events");
        try {
            $this->commit($r);
            self::fail("Stale requirement decision accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->f->f->digest("sis_history_events"));
        }
        $a = $this->minimum();
        $b = $this->waive();
        $this->commit($a);
        $this->expectException(ConcurrentModificationException::class);
        $this->commit($b);
    }
    public function testTamperedWrongActorAndCrossStudentReviewsFailClosed(): void
    {
        $r = $this->waive();
        try {
            $this->commit(["review_token" => $r["review_token"] . "bad"]);
            self::fail("Tampered review accepted.");
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $s = $this->f->f->sis;
        $other = $s->student($s->user());
        try {
            $this->service->commit($other->id, $this->f->assignment, $r["review_token"]);
            self::fail("Cross-student action accepted.");
        } catch (RecordNotFoundException) {
            self::assertTrue(true);
        }
        $s->access->actor = $s->user();
        $this->expectException(AccessDeniedException::class);
        $this->commit($r);
    }
    public function testGrantAndRevocationLateAuditFailuresRollBackAllWrites(): void
    {
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method("record")->willThrowException(new RuntimeException("Fictional failure"));
        $f = $this->f;
        $r = $this->waive();
        $tables = [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_requirement_adjustments",
            "sis_audit",
        ];
        foreach (["grant", "revoke"] as $action) {
            $hashes = [];
            foreach ($tables as $t) {
                $hashes[$t] = $f->f->digest($t);
            }
            try {
                $this->service($audit)->commit($f->student, $f->assignment, $r["review_token"]);
                self::fail("Late failure ignored.");
            } catch (RuntimeException) {
                foreach ($tables as $t) {
                    self::assertSame($hashes[$t], $f->f->digest($t), $t);
                }
            }
            $this->commit($r);
            if ($action === "grant") {
                $r = $this->service->reviewRevoke(
                    $f->student,
                    $f->assignment,
                    new EntityId($r["adjustment"]["adjustment_id"]),
                    "Fictional revoke",
                );
            }
        }
    }
    public function testInvalidTargetsRawExpressionsAndContradictoryCreditMinimumsAreRejected(): void
    {
        $invalid = [
            ["kind" => "sql", "sql" => "SELECT * FROM sis_students"],
            ["kind" => "waiver", "course_id" => EntityId::generate()->value],
            [
                "kind" => "minimum",
                "group_id" => null,
                "minimum" => "min_credits_milli",
                "required_milli" => 120000,
            ],
            [
                "kind" => "minimum",
                "group_id" => null,
                "minimum" => "min_credits_milli",
                "required_milli" => 20000,
            ],
            [
                "kind" => "minimum",
                "group_id" => null,
                "minimum" => "min_credits_milli",
                "required_milli" => -1,
            ],
            [
                "kind" => "minimum",
                "group_id" => null,
                "minimum" => "min_credits_milli",
                "required_milli" => "108000",
            ],
            [
                "kind" => "minimum",
                "group_id" => EntityId::generate()->value,
                "minimum" => "min_credits_milli",
                "required_milli" => 1000,
            ],
            [
                "kind" => "waiver",
                "course_id" => $this->f->f->groupDefinition["courses"][0]["course_id"],
                "group_id" => $this->f->f->group->value,
            ],
        ];
        foreach ($invalid as $d) {
            try {
                $this->review($d);
                self::fail("Invalid exception accepted.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
    public function testCorruptProjectionFailsClosedAndHistoryRemainsBounded(): void
    {
        for ($i = 0; $i < 11; ++$i) {
            $this->commit($this->minimum(108000 - $i));
        }
        $page = $this->service->history($this->f->student, $this->f->assignment);
        self::assertCount(10, $page["items"]);
        $this->commit($this->minimum(100000));
        self::assertCount(
            1,
            $this->service->history($this->f->student, $this->f->assignment, $page["next_cursor"])["items"],
        );
        $this->f->f->sis->sql->execute("DELETE FROM sis_requirement_adjustments WHERE assignment_id = ?", [
            $this->f->assignment->value,
        ]);
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $this->view();
    }

    public function testWaiverCoversEveryGroupInOnePursuitAndDoesNotAffectADualDegree(): void
    {
        $f = $this->f;
        $s = $f->f->sis;
        $definition = $f->f->groupDefinition;
        $definition["name"] = "Major requirements";
        foreach ($definition["rules"] as &$rule) {
            $rule["id"] = EntityId::generate()->value;
        }
        unset($rule);
        $group = EntityId::generate();
        $f->f->groups->create($group, new \Domain\DegreeAudit\ValueObject\CourseGroupDefinition($definition));
        $r = $f->f->groups->review($group, "Fictional second block");
        $published = $f->f->groups->publish($group, $r["review_token"]);
        $d = $f->f->definition;
        $d["groups"][] = ["group_id" => $group->value, "version_id" => $published["operation_id"]];
        $f->f->degrees->save($f->f->degree, 1, new AuditRequirementsV3($d));
        $p = $f->f->publishDegree();
        $f->version = new EntityId($p["operation_id"]);
        $f->assign();
        $review = $this->waive();
        self::assertSame(["General education", "Major requirements"], $review["affected_groups"]);
        $this->commit($review);
        $program = EntityId::generate();
        $s->sql->insert("sis_programs", [
            "id" => $program->value,
            "code" => "BA.DUAL",
            "title" => "Fictional second degree",
        ]);
        $records = new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($s->sql);
        $original = $records->assignment($f->assignment);
        $a = new \Domain\Student\StudentProgram(
            EntityId::generate(),
            $f->student,
            $program,
            $original->entryTermId,
        );
        $records->addAssignment($a);
        $s->catalogEnrollment()->initialize($a, $s->access->actor);
        $row = $f->f->degrees->open($program, $f->f->year);
        $id = new EntityId($row["id"]);
        $f->f->degrees->save($id, 0, new AuditRequirementsV3($d));
        $r = $f->f->degrees->review($id, "Fictional dual degree");
        $p = $f->f->degrees->publish($id, $r["review_token"]);
        $r = $f->service->review(
            $f->student,
            $a->id,
            $id,
            new EntityId($p["operation_id"]),
            new \Domain\DegreeAudit\ValueObject\PursuitStart("2026-01-31"),
            "Fictional second pursuit",
        );
        $f->service->assign($f->student, $a->id, $r["review_token"]);
        $other = $this->service->view($f->student, $a->id);
        self::assertSame([], $other["items"]);
        self::assertSame([], $other["effective_minimums"]["waived_course_ids"]);
        self::assertCount(1, $this->view()["effective_minimums"]["waived_course_ids"]);
    }
    public function testGpaExceptionsUseRetainedThousandthsAndRevocationHonorsRelatedMinimums(): void
    {
        $f = $this->f;
        $d = $f->f->definition;
        $d["min_cumulative_gpa_milli"] = 2500;
        $f->f->degrees->save($f->f->degree, 1, new AuditRequirementsV3($d));
        $p = $f->f->publishDegree();
        $f->version = new EntityId($p["operation_id"]);
        $f->assign();
        $this->commit($this->minimum(2250, null, "min_cumulative_gpa_milli"));
        self::assertSame(2250, $this->view()["effective_minimums"]["degree"]["min_cumulative_gpa_milli"]);
        $resident = $this->minimum(15000, null, "min_resident_milli");
        $this->commit($resident);
        $this->commit($this->minimum(20000));
        $this->expectException(InvalidArgumentException::class);
        $this->service->reviewRevoke(
            $f->student,
            $f->assignment,
            new EntityId($resident["adjustment"]["adjustment_id"]),
            "Fictional invalid restoration",
        );
    }
    public function testCodefyReplayReconstructsActiveApprovalsAndRejectsRevocationWithoutAGrant(): void
    {
        $a = $this->minimum();
        $this->commit($a);
        $b = $this->minimum(100000);
        $this->commit($b);
        $this->commit($this->waive());
        $f = $this->f;
        $stream = new \Domain\Shared\EventSourcing\StreamReference(
            new \Domain\DegreeAudit\ValueObject\RequirementAdjustmentsId($f->assignment->value),
            "student_requirement_adjustments",
        );
        $events = array_map(static fn($s) => $s->event, $f->f->history->page($stream)->events);
        $root = \Domain\DegreeAudit\RequirementAdjustments::reconstituteFromEventStream(
            new \Codefy\Domain\EventSourcing\EventStream($stream->aggregateId, $events),
        );
        self::assertSame(3, $root->playhead());
        self::assertCount(2, $root->active());
        $before = $f->f->digest("sis_history_events");
        $s = $f->f->sis->sql;
        $s->execute("DELETE FROM sis_requirement_adjustments WHERE assignment_id = ?", [
            $f->assignment->value,
        ]);
        $repo = new \Infrastructure\Persistence\Repository\SqlRequirementAdjustmentRepository($s);
        foreach ($root->active() as $p) {
            $repo->grant($f->assignment, $p);
        }
        self::assertCount(2, $this->view()["items"]);
        self::assertSame($before, $f->f->digest("sis_history_events"));
        $bad = \Domain\DegreeAudit\Event\RequirementAdjustmentWasRevoked::occur(
            $stream->aggregateId,
            [
                "adjustment_id" => EntityId::generate()->value,
                "decision_id" => EntityId::generate()->value,
                "student_id" => $f->student->value,
                "program_id" => $f->f->sis->program->value,
            ],
            ["__aggregate_type" => "student_requirement_adjustments"],
        )->withPlayhead(4);
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $root->acceptHistorical($bad);
    }

    public function testHistoryUsesOriginalPublishedCourseLabelsAndProjectionTypesAreStrict(): void
    {
        $f = $this->f;
        $waiver = $this->waive();
        $this->commit($waiver);
        $course = $waiver["adjustment"]["definition"]["course_id"];
        $f->f->sis->sql->update(
            "sis_courses",
            ["code" => "CHANGED.101", "title" => "Changed current catalog"],
            ["id" => $course],
        );
        $history = $this->service->history($f->student, $f->assignment)["items"];
        self::assertStringContainsString("MATH.101", $history[0]["subject_label"]);
        self::assertStringNotContainsString("CHANGED.101", $history[0]["subject_label"]);
        $r = $this->minimum();
        $this->commit($r);
        $p = $r["adjustment"];
        $p["definition"]["required_milli"] = "108000";
        $f->f->sis->sql->update(
            "sis_requirement_adjustments",
            ["projection_json" => json_encode($p, JSON_THROW_ON_ERROR)],
            ["id" => $p["adjustment_id"]],
        );
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $this->view();
    }
    public function testPermissionMigrationIsIndependentProtectedAndDoesNotRestoreRoleGrants(): void
    {
        $s = $this->f->f->sis;
        $p = $s->sql->one("sis_access_permissions", ["name" => RequirementAdjustmentService::PERMISSION]);
        self::assertSame(1, (int) $p["is_system"]);
        self::assertCount(1, $s->sql->rows("sis_access_implications", ["descendant_id" => $p["id"]]));
        $role = $s->sql->one("sis_access_roles", ["name" => "admin"]);
        $s->sql->insert("sis_access_user_roles", [
            "id" => EntityId::generate()->value,
            "role_id" => $role["id"],
            "user_id" => $s->access->actor->value,
        ]);
        $access = new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql);
        self::assertFalse(
            $access->allows($s->access->actor->value, RequirementAdjustmentService::PERMISSION),
        );
        $before = $this->f->f->digest("sis_access_role_permissions");
        $m = new CreateSisRequirementAdjustments("repeat");
        $m->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $s->connection,
                    "test_migrations",
                ),
            ]),
        );
        $m->up();
        self::assertSame($before, $this->f->f->digest("sis_access_role_permissions"));
    }
}
