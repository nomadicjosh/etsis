<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Catalog\ValueObject\CurriculumComponentKind;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use StudentSpecializationFixture;

final class ComponentMajorWorkflowTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/StudentSpecializationFixture.php";
    }
    private function fixture(): StudentSpecializationFixture
    {
        return new StudentSpecializationFixture(
            configure: static function (StudentSpecializationFixture $f): void {
                foreach ($f->choices as $i => $c) {
                    $f->choices[$i] = $f
                        ->choiceService()
                        ->save(
                            $f->sis->program,
                            new EntityId($c["id"]),
                            $c["revision"],
                            $c["code"],
                            $c["title"],
                            $c["description"],
                            true,
                            $i === 1
                                ? CurriculumComponentKind::Concentration
                                : CurriculumComponentKind::Major,
                            parentMajor: $i === 1 ? new EntityId($f->choices[0]["id"]) : null,
                        );
                }
                $f->policies()->saveProgram(
                    $f->sis->program,
                    1,
                    1,
                    2,
                    kindLimits: [
                        "major" => ["minimum" => 1, "maximum" => 1],
                        "concentration" => ["minimum" => 0, "maximum" => 1],
                    ],
                );
            },
        );
    }
    private function select(StudentSpecializationFixture $f, array $indexes): array
    {
        $view = $f->service->view($f->student, $f->assignment);
        $r = $f->service->review(
            $f->student,
            $f->assignment,
            $view["revision"],
            array_map(fn(int $i): string => $f->choices[$i]["id"], $indexes),
            "Fictional reviewed linked curriculum.",
        );
        return [$r, $f->service->commit($f->student, $f->assignment, $r["review_token"])];
    }
    public function testAuthoringAndFilteredMajorPickerUseExplicitSameProgramReferences(): void
    {
        $f = $this->fixture();
        $c = $f->choices[1];
        self::assertSame($f->choices[0]["id"], $c["parent_major"]["id"]);
        $page = $f->choiceService()->page($f->sis->program, kind: CurriculumComponentKind::Major);
        self::assertCount(2, $page["items"]);
        self::assertNotContains($c["id"], array_column($page["items"], "id"));
        $version = $f->program->service->versions($f->sis->program)["items"][0];
        $published = $version["specializations"]["offered"];
        self::assertEquals(
            $c["parent_major"],
            array_column($published, null, "id")[$c["id"]]["parent_major"],
        );
    }
    public function testWrongMajorAndParentRemovalAreRejectedAndOriginalExpiredRetriesPreserveLaterSelection(): void
    {
        $f = $this->fixture();
        foreach ([[1], [1, 2]] as $indexes) {
            $before = $f->sis->sql->rows("sis_history_events");
            try {
                $this->select($f, $indexes);
                self::fail("A concentration was selected without its required major.");
            } catch (ValidationException) {
                self::assertSame($before, $f->sis->sql->rows("sis_history_events"));
            }
        }
        [$r, $original] = $this->select($f, [0, 1]);
        $selection = $f->service->view($f->student, $f->assignment)["selection"];
        self::assertCount(2, $selection["specializations"]);
        self::assertSame("ART", $selection["specializations"][1]["parent_major"]["code"]);
        $this->select($f, [2]);
        $f->sis->clock->time = $f->sis->clock->time->modify("+21 minutes");
        $before = $f->sis->sql->rows("sis_history_events");
        self::assertSame(
            $original,
            $f->service->commit($f->student, $f->assignment, $r["review_token"]),
        );
        self::assertSame($before, $f->sis->sql->rows("sis_history_events"));
        self::assertSame(
            [$f->choices[2]["id"]],
            array_column(
                $f->service->view($f->student, $f->assignment)["selection"]["specializations"],
                "id",
            ),
        );
    }
    public function testChangingTheParentStalesUnacceptedPublicationAndRetirementDoesNotRewriteStudentChoices(): void
    {
        $f = $this->fixture();
        $this->select($f, [0, 1]);
        $r = $f->program->service->review(
            $f->sis->program,
            new EntityId($f->year["id"]),
            "Fictional new linked publication.",
        );
        $c = $f->choices[0];
        $renamed = $f
            ->choiceService()
            ->save(
                $f->sis->program,
                new EntityId($c["id"]),
                2,
                $c["code"],
                "New current major title",
                null,
                true,
                CurriculumComponentKind::Major,
            );
        $before = $f->sis->sql->rows("sis_history_events");
        try {
            $f->program->service->publish($f->sis->program, $r["review_token"]);
            self::fail("A changed parent was published without review.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $f->sis->sql->rows("sis_history_events"));
        }
        $f->choiceService()->save(
            $f->sis->program,
            new EntityId($c["id"]),
            $renamed["revision"],
            $c["code"],
            $renamed["title"],
            null,
            false,
            CurriculumComponentKind::Major,
        );
        $recorded = $f->service->view($f->student, $f->assignment)["selection"]["specializations"];
        self::assertSame($c["title"], $recorded[1]["parent_major"]["title"]);
        $this->expectException(ValidationException::class);
        $f->publish();
    }
    public function testCurrentReparentingAndPublicationDoNotMoveAnExistingStudentsConcentration(): void
    {
        $f = $this->fixture();
        $this->select($f, [0, 1]);
        $c = $f->choices[1];
        $f->choiceService()->save(
            $f->sis->program,
            new EntityId($c["id"]),
            $c["revision"],
            $c["code"],
            $c["title"],
            null,
            true,
            CurriculumComponentKind::Concentration,
            parentMajor: new EntityId($f->choices[2]["id"]),
        );
        $f->publish();
        $selected = $f->service->view($f->student, $f->assignment)["selection"]["specializations"];
        $retained = array_column($selected, null, "id")[$c["id"]];
        self::assertSame($f->choices[0]["id"], $retained["parent_major"]["id"]);
        self::assertSame($f->choices[0]["title"], $retained["parent_major"]["title"]);
    }

    public function testSelfLinksWrongParentKindsAndForeignProgramParentsCannotBeSaved(): void
    {
        $f = $this->fixture();
        $c = $f->choices[1];
        $s = $f->sis;
        foreach (
            [
                [$c["id"], CurriculumComponentKind::Concentration],
                [$f->choices[0]["id"], CurriculumComponentKind::Minor],
            ] as [$parent, $kind]
        ) {
            try {
                $f->choiceService()->save(
                    $s->program,
                    new EntityId($c["id"]),
                    2,
                    $c["code"],
                    $c["title"],
                    null,
                    true,
                    $kind,
                    parentMajor: new EntityId($parent),
                );
                self::fail("Invalid required-major scope passed.");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $other = EntityId::generate();
        $s->sql->insert("sis_programs", [
            "id" => $other->value,
            "code" => "OTHER",
            "title" => "Fictional other program",
        ]);
        $foreign = $f
            ->choiceService()
            ->save(
                $other,
                null,
                null,
                "FOREIGN",
                "Foreign major",
                null,
                true,
                CurriculumComponentKind::Major,
            );
        $this->expectException(\Domain\Shared\Exception\RecordNotFoundException::class);
        $f->choiceService()->save(
            $s->program,
            new EntityId($c["id"]),
            2,
            $c["code"],
            $c["title"],
            null,
            true,
            CurriculumComponentKind::Concentration,
            parentMajor: new EntityId($foreign["id"]),
        );
    }
    public function testFinalParentSourceChangeRollsBackComponentAndAudit(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $c = $f->choices[1];
        $before = [$s->sql->rows("sis_program_specializations"), $s->sql->rows("sis_audit")];
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit
            ->expects(self::once())
            ->method("record")
            ->willReturnCallback(static function (
                EntityId $actor,
                string $action,
                EntityId $subject,
            ) use (
                $s,
                $f,
            ): void {
                $s->audit->record($actor, $action, $subject);
                $s->sql->update(
                    "sis_program_specializations",
                    ["title" => "Fictional late major edit"],
                    ["id" => $f->choices[0]["id"]],
                );
            });
        $service = new \Domain\Catalog\Service\ProgramSpecializationService(
            new \Infrastructure\Persistence\Repository\SqlProgramSpecializationRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($s->sql),
            $s->access,
            $s->transactions,
            $audit,
            new \Infrastructure\Persistence\Repository\SqlClassificationRepository($s->sql),
        );
        try {
            $service->save(
                $s->program,
                new EntityId($c["id"]),
                2,
                $c["code"],
                "Edited child",
                null,
                true,
                CurriculumComponentKind::Concentration,
                parentMajor: new EntityId($f->choices[0]["id"]),
            );
            self::fail("A final parent change left a component save.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, [
                $s->sql->rows("sis_program_specializations"),
                $s->sql->rows("sis_audit"),
            ]);
        }
    }
    public function testEarlierMissingRelationshipsRoundTripAndCountOnlyPublicationConflictsAreBlocked(): void
    {
        $f = $this->fixture();
        $review = $f->program->service->review(
            $f->sis->program,
            new EntityId($f->year["id"]),
            "Fictional snapshot inspection.",
        );
        $snapshot = $review["specializations"];
        foreach ($snapshot["offered"] as &$choice) {
            unset($choice["parent_major"]);
        }
        unset($choice);
        $old = new \Domain\Catalog\ValueObject\ProgramSpecializationSnapshot($snapshot)->data;
        self::assertArrayNotHasKey("parent_major", $old["offered"][1]);
        $f->policies()->saveProgram(
            $f->sis->program,
            2,
            0,
            1,
            kindLimits: [
                "major" => ["minimum" => 1, "maximum" => 1],
                "concentration" => ["minimum" => 1, "maximum" => 1],
            ],
        );
        $this->expectException(ValidationException::class);
        $f->publish();
    }
}
