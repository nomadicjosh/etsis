<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/StudentRequirementsFixture.php";
use Domain\DegreeAudit\Service\RequirementAdjustmentService;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
final class CourseSubstitutionTest extends TestCase
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
    private function definition(int $required = 0, int $replacement = 1): array
    {
        $courses = $this->f->f->groupDefinition["courses"];
        return [
            "kind" => "substitution",
            "course_id" => $courses[$required]["course_id"],
            "replacement_course_id" => $courses[$replacement]["course_id"],
            "replacement_version_id" => $courses[$replacement]["version_id"],
        ];
    }
    private function review(array $d): array
    {
        return $this->service->reviewGrant(
            $this->f->student,
            $this->f->assignment,
            $d,
            "Fictional substitution approval",
        );
    }
    private function commit(array $r): array
    {
        return $this->service->commit($this->f->student, $this->f->assignment, $r["review_token"]);
    }
    private function view(): array
    {
        return $this->service->view($this->f->student, $this->f->assignment);
    }
    public function testSubstitutionCanBeApprovedBeforeEitherCourseIsTakenAndDoesNotCreateCredit(): void
    {
        $d = $this->definition();
        $before = $this->f->f->digest("sis_history_events");
        $review = $this->review($d);
        self::assertSame($before, $this->f->f->digest("sis_history_events"));
        self::assertSame(["General education"], $review["affected_groups"]);
        self::assertSame(
            "ENG.203",
            $review["substitution_details"][$review["adjustment"]["adjustment_id"]]["code"],
        );
        $this->commit($review);
        $view = $this->view();
        self::assertSame(
            ["course_id" => $d["replacement_course_id"], "version_id" => $d["replacement_version_id"]],
            $view["effective_minimums"]["substitutions"][$d["course_id"]],
        );
        self::assertSame([], $this->f->f->sis->sql->rows("sis_academic_credits"));
        self::assertSame([], $this->f->f->sis->sql->rows("sis_student_accounts"));
        self::assertSame(120000, $view["effective_minimums"]["degree"]["min_credits_milli"]);
    }
    public function testReplacementLabelsAndCreditsUseChosenPublicationAfterCatalogChanges(): void
    {
        $d = $this->definition();
        $r = $this->review($d);
        $this->commit($r);
        $s = $this->f->f->sis;
        $s->sql->update(
            "sis_courses",
            ["code" => "ENG.NEW", "title" => "Revised English", "credits_milli" => 4000],
            ["id" => $d["replacement_course_id"]],
        );
        $pub = $this->f->f->catalog->publications();
        $review = $pub->review(new EntityId($d["replacement_course_id"]), "Fictional later course");
        $pub->publish(new EntityId($d["replacement_course_id"]), $review["review_token"]);
        $v = $this->view()["substitution_details"][$r["adjustment"]["adjustment_id"]];
        self::assertSame("ENG.203", $v["code"]);
        self::assertSame(3000, $v["credits_milli"]);
        $history = $this->service->history($this->f->student, $this->f->assignment)["items"];
        self::assertStringContainsString("MATH.101", $history[0]["subject_label"]);
        self::assertStringContainsString("ENG.203", $history[0]["replacement_label"]);
    }
    public function testSelfUnpublishedForeignPublicationAndUnexpectedFieldsAreRejected(): void
    {
        $cases = [];
        $d = $this->definition(0, 0);
        $cases[] = $d;
        $d = $this->definition();
        $d["replacement_version_id"] = $this->f->f->groupVersion->value;
        $cases[] = $d;
        $d = $this->definition();
        $d["replacement_version_id"] = $this->f->f->groupDefinition["courses"][2]["version_id"];
        $cases[] = $d;
        $d = $this->definition();
        $d["course_id"] = EntityId::generate()->value;
        $cases[] = $d;
        $d = $this->definition();
        $d["sql"] = "SELECT 1";
        $cases[] = $d;
        foreach ($cases as $d) {
            try {
                $this->review($d);
                self::fail("Invalid substitution accepted.");
            } catch (InvalidArgumentException | RecordNotFoundException) {
                self::assertSame([], $this->view()["items"]);
            }
        }
    }
    public function testReplacementAndRevocationKeepHistoryAndOriginalRetryReceipt(): void
    {
        $a = $this->review($this->definition());
        $receipt = $this->commit($a);
        $b = $this->review($this->definition(0, 2));
        self::assertSame($a["adjustment"]["adjustment_id"], $b["previous"]["adjustment_id"]);
        $this->commit($b);
        self::assertCount(1, $this->view()["items"]);
        $r = $this->service->reviewRevoke(
            $this->f->student,
            $this->f->assignment,
            new EntityId($b["adjustment"]["adjustment_id"]),
            "Fictional revoke",
        );
        $this->commit($r);
        self::assertSame([], $this->view()["effective_minimums"]["substitutions"]);
        $before = $this->f->f->digest("sis_audit");
        self::assertSame($receipt, $this->commit($a));
        self::assertSame($before, $this->f->f->digest("sis_audit"));
        self::assertCount(3, $this->service->history($this->f->student, $this->f->assignment)["items"]);
    }
    public function testOverlappingWaiversAndIndirectOrCircularSubstitutionsRequireReview(): void
    {
        $this->commit($this->review($this->definition(0, 1)));
        $foreachItems = [
                ["kind" => "waiver", "course_id" => $this->definition()["course_id"]],
                ["kind" => "waiver", "course_id" => $this->definition()["replacement_course_id"]],
                $this->definition(1, 2),
                $this->definition(1, 0),
            ];
        foreach ($foreachItems as $d) {
            try {
                $this->review($d);
                self::fail("Ambiguous substitution accepted.");
            } catch (InvalidArgumentException) {
                self::assertCount(1, $this->view()["items"]);
            }
        }
    }
    public function testSubstitutionDoesNotCarryToAnotherRequirementVersionAndOldConflictRemainsReadableAndRevocable(): void
    {
        $original = $this->f->version;
        $sub = $this->review($this->definition());
        $this->commit($sub);
        $f = $this->f;
        $d = $f->f->definition;
        $d["name"] = "New requirement year review";
        $f->f->degrees->save($f->f->degree, 1, new AuditRequirementsV3($d));
        $p = $f->f->publishDegree();
        $f->version = new EntityId($p["operation_id"]);
        $f->assign();
        self::assertSame([], $this->view()["effective_minimums"]["substitutions"]);
        self::assertFalse($this->view()["items"][0]["applicable"]);
        $this->commit($this->review(["kind" => "waiver", "course_id" => $this->definition()["course_id"]]));
        $f->version = $original;
        $f->assign();
        $view = $this->view();
        self::assertNotEmpty($view["effective_minimums"]["substitution_conflicts"]);
        self::assertSame([], $view["effective_minimums"]["substitutions"]);
        $r = $this->service->reviewRevoke(
            $f->student,
            $f->assignment,
            new EntityId($sub["adjustment"]["adjustment_id"]),
            "Fictional conflict resolution",
        );
        $this->commit($r);
        self::assertSame([], $this->view()["effective_minimums"]["substitution_conflicts"]);
    }
    public function testSelectorsRequireOfficeAndStudentReadAndDoNotGrantCatalogMutation(): void
    {
        $a = $this->f->f->sis->access;
        $a->staff = false;
        $a->grants = ["sis:student:read"];
        try {
            $this->service->replacementCourses($this->f->student, $this->f->assignment);
            self::fail("Read-only user browsed replacement source.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $a->grants[] = "sis:degree-audit:adjust";
        $choices = $this->service->replacementCourses($this->f->student, $this->f->assignment, "ENG.203");
        self::assertCount(1, $choices["items"]);
        self::assertSame(["id", "code", "title"], array_keys($choices["items"][0]));
        $d = $this->definition();
        $versions = $this->service->replacementVersions(
            $this->f->student,
            $this->f->assignment,
            new EntityId($d["replacement_course_id"]),
        );
        self::assertSame($d["replacement_version_id"], $versions["items"][0]["version_id"]);
        $this->commit($this->review($d));
        $this->expectException(AccessDeniedException::class);
        $this->f->f->catalog
            ->publications()
            ->review(new EntityId($d["replacement_course_id"]), "Forbidden catalog write");
    }
    public function testSourceSearchUsesLiteralWildcardsAndBoundedKeysetPages(): void
    {
        $s = $this->f->f->sis;
        for ($i = 0; $i < 25; ++$i) {
            $s->registration->addCourse(
                new \Domain\Catalog\Course(
                    EntityId::generate(),
                    "TEST." . $i,
                    "Fictional %_ replacement " . $i,
                    3000,
                ),
            );
        }
        $first = $this->service->replacementCourses($this->f->student, $this->f->assignment, "%_");
        self::assertCount(20, $first["items"]);
        $next = $this->service->replacementCourses(
            $this->f->student,
            $this->f->assignment,
            "%_",
            new EntityId($first["next_after"]),
        );
        self::assertCount(5, $next["items"]);
        self::assertSame(
            [],
            array_intersect(array_column($first["items"], "id"), array_column($next["items"], "id")),
        );
        self::assertSame(
            [],
            $this->service->replacementVersions(
                $this->f->student,
                $this->f->assignment,
                new EntityId($first["items"][0]["id"]),
            )["items"],
        );
    }
    public function testOldSchemaAndReceiptStayValidWhileNewSubstitutionsRequireSchemaTwo(): void
    {
        $waiver = $this->review([
            "kind" => "waiver",
            "course_id" => $this->f->f->groupDefinition["courses"][4]["course_id"],
        ]);
        $receipt = $this->commit($waiver);
        $rows = $this->f->f->sis->sql->rows("sis_history_events");
        $r = $this->review($this->definition());
        $this->commit($r);
        self::assertSame(
            $rows,
            array_slice($this->f->f->sis->sql->rows("sis_history_events"), 0, count($rows)),
        );
        self::assertSame($receipt, $this->commit($waiver));
        $stream = new \Domain\Shared\EventSourcing\StreamReference(
            new \Domain\DegreeAudit\ValueObject\RequirementAdjustmentsId($this->f->assignment->value),
            "student_requirement_adjustments",
        );
        $events = $this->f->f->history->page($stream)->events;
        self::assertSame(
            [1, 2],
            array_map(static fn($e) => $e->event->metaParam("schema_version", 1), $events),
        );
        $bad = \Domain\DegreeAudit\Event\RequirementAdjustmentWasGranted::occur(
            $stream->aggregateId,
            $r["adjustment"],
            ["__aggregate_type" => "student_requirement_adjustments"],
        )->withPlayhead(3);
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        new \Infrastructure\EventSourcing\HistoricalSchemaRegistry()->describe($bad);
    }
    public function testSubstitutionAppendAndProjectionRollBackOnLateAuditFailure(): void
    {
        $r = $this->review($this->definition());
        $tables = [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_requirement_adjustments",
            "sis_audit",
        ];
        $before = [];
        foreach ($tables as $t) {
            $before[$t] = $this->f->f->digest($t);
        }
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method("record")->willThrowException(new RuntimeException("Fictional failure"));
        try {
            $this->service($audit)->commit($this->f->student, $this->f->assignment, $r["review_token"]);
            self::fail("Audit failure ignored.");
        } catch (RuntimeException) {
            foreach ($tables as $t) {
                self::assertSame($before[$t], $this->f->f->digest($t));
            }
        }
        self::assertSame(1, $this->commit($r)["last_version"]);
    }
}
