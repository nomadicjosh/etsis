<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Catalog\ValueObject\ComponentSelectionRules;
use Domain\Catalog\ValueObject\CurriculumComponentKind;
use Domain\Catalog\ValueObject\ProgramSpecializationSnapshot;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use StudentSpecializationFixture;

final class CurriculumComponentTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/StudentSpecializationFixture.php";
    }
    private function typed(): StudentSpecializationFixture
    {
        $f = new StudentSpecializationFixture();
        foreach ($f->choices as $i => $choice) {
            $f->choiceService()->save(
                $f->sis->program,
                new EntityId($choice["id"]),
                1,
                $choice["code"],
                $choice["title"],
                $choice["description"],
                true,
                $i === 0 ? CurriculumComponentKind::Major : CurriculumComponentKind::Minor,
            );
        }
        $f->policies()->saveProgram(
            $f->sis->program,
            1,
            0,
            2,
            kindLimits: [
                "major" => ["minimum" => 1, "maximum" => 1],
                "minor" => ["minimum" => 0, "maximum" => 1],
            ],
        );
        $publication = $f->publish();
        $cohorts = $f->sis->studentCatalogs();
        $review = $cohorts->review(
            $f->student,
            $f->assignment,
            new EntityId($publication["operation_id"]),
            $cohorts->view($f->student, $f->assignment)["version"],
            "Fictional typed curriculum cohort.",
        );
        $cohorts->commit(
            $f->student,
            $f->assignment,
            new EntityId($publication["operation_id"]),
            new EntityId($review["operation_id"]),
            $review["version"],
            "Fictional typed curriculum cohort.",
            $review["review_token"],
        );
        return $f;
    }
    public function testRequiredMajorAndRetainedTypesSurviveCatalogChanges(): void
    {
        $f = $this->typed();
        $before = $f->sis->sql->rows("sis_history_events");
        try {
            $f->review([$f->choices[1]["id"], $f->choices[2]["id"]]);
            self::fail("Two minors replaced the required major.");
        } catch (ValidationException) {
            self::assertSame($before, $f->sis->sql->rows("sis_history_events"));
        }
        $receipt = $f->select([$f->choices[0]["id"], $f->choices[1]["id"]]);
        $view = $f->service->view($f->student, $f->assignment);
        self::assertSame(
            ["major", "minor"],
            array_column($view["selection"]["specializations"], "kind"),
        );
        $choice = $f->choices[0];
        $f->choiceService()->save(
            $f->sis->program,
            new EntityId($choice["id"]),
            2,
            $choice["code"],
            "Changed draft only",
            null,
            true,
            CurriculumComponentKind::Track,
        );
        self::assertSame(
            $view["selection"],
            $f->service->view($f->student, $f->assignment)["selection"],
        );
        self::assertSame([], $f->sis->sql->rows("sis_academic_credits"));
        self::assertSame([], $f->sis->sql->rows("sis_student_accounts"));
    }
    public function testOldUntypedPublicationRoundTripsWithoutAssigningModernRolesOrTypeLimits(): void
    {
        $f = new StudentSpecializationFixture();
        $snapshot = $f->service->view($f->student, $f->assignment)["program"]["specializations"];
        unset($snapshot["kind_limits"]);
        foreach ($snapshot["offered"] as &$row) {
            unset($row["kind"]);
        }
        unset($row);
        $old = new ProgramSpecializationSnapshot($snapshot)->data;
        self::assertArrayNotHasKey("kind_limits", $old);
        foreach ($old["offered"] as $row) {
            self::assertArrayNotHasKey("kind", $row);
        }
        self::assertSame($old, new ProgramSpecializationSnapshot($old)->data);
    }
    public function testImpossibleTypeLimitsBlockPublicationAndMissingAuthoringTypesAreNeverInferred(): void
    {
        $f = new StudentSpecializationFixture();
        $f->policies()->saveProgram(
            $f->sis->program,
            1,
            0,
            2,
            kindLimits: ["major" => ["minimum" => 1, "maximum" => 1]],
        );
        $before = $f->sis->sql->rows("sis_history_events");
        try {
            $f->publish();
            self::fail("A program with no major offered was published.");
        } catch (ValidationException) {
            self::assertSame($before, $f->sis->sql->rows("sis_history_events"));
        }
        $f->policies()->saveProgram($f->sis->program, 2, 0, 2);
        $f->sis->sql->update(
            "sis_program_specializations",
            ["kind" => null],
            ["id" => $f->choices[0]["id"]],
        );
        $this->expectException(ValidationException::class);
        $f->publish();
    }
    public function testMalformedUnknownAndUnboundedRulesAreRejected(): void
    {
        foreach (
            [
                ["other" => ["minimum" => 0, "maximum" => 1]],
                ["major" => ["minimum" => 2, "maximum" => 1]],
                ["minor" => ["minimum" => "0", "maximum" => 1]],
                ["major" => ["minimum" => 0, "maximum" => 101]],
                ["minor" => ["minimum" => 0, "maximum" => 1, "extra" => true]],
            ] as $bad
        ) {
            try {
                new ComponentSelectionRules($bad);
                self::fail("Malformed rules accepted.");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }
    public function testChangedTypeStalesAnUnacceptedPublicationButPreservesAnAcceptedPublication(): void
    {
        $f = new StudentSpecializationFixture();
        $review = $f->program->service->review(
            $f->sis->program,
            new EntityId($f->year["id"]),
            "Fictional reviewed component types.",
        );
        $choice = $f->choices[0];
        $f->choiceService()->save(
            $f->sis->program,
            new EntityId($choice["id"]),
            1,
            $choice["code"],
            $choice["title"],
            null,
            true,
            CurriculumComponentKind::Major,
        );
        $events = $f->sis->sql->rows("sis_history_events");
        try {
            $f->program->service->publish($f->sis->program, $review["review_token"]);
            self::fail("A changed type was published without review.");
        } catch (ConcurrentModificationException) {
            self::assertSame($events, $f->sis->sql->rows("sis_history_events"));
        }
        self::assertSame(
            "specialization",
            $f->service->view($f->student, $f->assignment)["program"]["specializations"][
                "offered"
            ][0]["kind"],
        );
    }
    public function testFinalAuthorityLossRollsBackTypedAuthoringAndItsRequiredAudit(): void
    {
        $f = new StudentSpecializationFixture();
        $s = $f->sis;
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit
            ->expects(self::once())
            ->method("record")
            ->willReturnCallback(static function (
                EntityId $actor,
                string $action,
                EntityId $subject,
            ) use ($s): void {
                $s->audit->record($actor, $action, $subject);
                $s->access->staff = false;
            });
        $service = new \Domain\Catalog\Service\ProgramSpecializationService(
            new \Infrastructure\Persistence\Repository\SqlProgramSpecializationRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($s->sql),
            $s->access,
            $s->transactions,
            $audit,
            new \Infrastructure\Persistence\Repository\SqlClassificationRepository($s->sql),
        );
        $before = [$s->sql->rows("sis_program_specializations"), $s->sql->rows("sis_audit")];
        try {
            $service->save(
                $s->program,
                null,
                null,
                "NEW.MAJOR",
                "Fictional major",
                null,
                true,
                CurriculumComponentKind::Major,
            );
            self::fail("Typed authoring survived lost authority.");
        } catch (AccessDeniedException) {
            self::assertSame($before, [
                $s->sql->rows("sis_program_specializations"),
                $s->sql->rows("sis_audit"),
            ]);
        }
    }
}
