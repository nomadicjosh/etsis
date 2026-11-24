<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Enum\ClassificationKind;
use Domain\Catalog\ValueObject\ProgramClassifications;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlProgramDefinitionRepository;
use PHPUnit\Framework\TestCase;
use ProgramPublicationFixture;

final class ProgramDepartmentOwnershipTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/ProgramPublicationFixture.php";
        require_once dirname(__DIR__) . "/Support/StudentSpecializationRequirementsFixture.php";
        require_once dirname(__DIR__) . "/Support/DegreeProgressFixture.php";
    }

    public function testAssignedProgramOwnershipKeepsItsOwnCollegeAfterCurrentReferencesChange(): void
    {
        $owners = [];
        $f = new \StudentSpecializationRequirementsFixture(
            configureProgram: static function (ProgramPublicationFixture $p) use (&$owners): void {
                foreach (["ARTS", "LANG"] as $code) {
                    $school = $p->classifications->save(
                        \Domain\Catalog\Enum\ClassificationKind::School,
                        null,
                        $code,
                        "Fictional " . $code . " college",
                        true,
                        null,
                    );
                    $owners[] = $p->classifications->save(
                        \Domain\Catalog\Enum\ClassificationKind::Department,
                        null,
                        $code,
                        "Fictional " . $code . " department",
                        true,
                        null,
                        new EntityId($school["id"]),
                    );
                }
                $draft = $p->service->draft($p->sis->program);
                $p->service->save(
                    $p->sis->program,
                    "ARTS-UG",
                    "Fictional joint program",
                    null,
                    new EntityId($owners[0]["id"]),
                    null,
                    null,
                    $draft["revision"],
                    additionalDepartments: [new EntityId($owners[1]["id"])],
                );
            },
        );
        $s = $f->r->sis;
        $progress = \DegreeProgressFixture::service(
            $s,
            $f->r->history,
            $f->requirements(),
            $f->r->catalog->fixture->cipher,
            specializations: $f->service,
        );
        $preview = static fn(): array => $progress->specialization(
            $f->student,
            $f->assignment,
            new EntityId($f->choices["DATA"]["id"]),
        );
        $f->assign();
        $first = $preview();
        self::assertSame(
            $owners[0]["school"],
            $first["context"]["program_ownership"]["department"]["school"],
        );
        self::assertEquals(
            $owners[1]["school"],
            $first["context"]["program_ownership"]["additional_departments"][0]["school"],
        );
        $school = $owners[1]["school"];
        $reference = $f->program->classifications->page(
            \Domain\Catalog\Enum\ClassificationKind::School,
            $school["code"],
        )["items"][0];
        $f->program->classifications->save(
            \Domain\Catalog\Enum\ClassificationKind::School,
            new EntityId($school["id"]),
            $school["code"],
            "Fictional renamed current college",
            true,
            $reference["revision"],
        );
        self::assertSame(
            $first["context"]["program_ownership"],
            $preview()["context"]["program_ownership"],
        );
        $f->r->saveDegree();
        $version = new EntityId($f->r->publishDegree()["operation_id"]);
        $requirements = $f->requirements();
        $review = $requirements->review(
            $f->student,
            $f->assignment,
            $f->r->degree,
            $version,
            new \Domain\DegreeAudit\ValueObject\PursuitStart("2026-01-31"),
            "Fictional retained program ownership.",
        );
        $requirements->assign($f->student, $f->assignment, $review["review_token"]);
        $combined = $progress->combined($f->student, $f->assignment);
        self::assertSame("review", $combined["status"]);
        foreach ($combined["components"] as $component) {
            if (($component["progress"]["status"] ?? null) === "review") {
                self::assertSame(
                    $first["context"]["program_ownership"],
                    $component["progress"]["context"]["program_ownership"],
                );
            }
        }
        $student = new \Domain\Portal\ValueObject\StudentDegreeProgress($combined)->describe();
        $safe = $student["components"][1]["progress"]["context"]["program_ownership"];
        self::assertSame($school["title"], $safe["additional_departments"][0]["school"]["title"]);
        self::assertArrayNotHasKey("id", $safe["additional_departments"][0]);
        self::assertArrayNotHasKey("id", $safe["additional_departments"][0]["school"]);
    }

    private function fixture(): array
    {
        $p = new ProgramPublicationFixture();
        $owners = [];
        foreach (["ENG", "LING", "HIST"] as $code) {
            $school = $p->classifications->save(
                ClassificationKind::School,
                null,
                "COL." . $code,
                "Fictional " . $code . " college",
                true,
                null,
            );
            $owners[] = $p->classifications->save(
                ClassificationKind::Department,
                null,
                $code,
                "Fictional " . $code . " department",
                true,
                null,
                new EntityId($school["id"]),
            );
        }
        return [$p, $owners];
    }

    private function save(
        ProgramPublicationFixture $p,
        ?array $primary,
        array $owners,
        ?AuditTrail $audit = null,
    ): array {
        $draft = $p->service->draft($p->sis->program);
        return $p
            ->publisher(audit: $audit)
            ->save(
                $p->sis->program,
                "ARTS-UG",
                "Fictional Arts program",
                "Fictional joint program.",
                $primary === null ? null : new EntityId($primary["id"]),
                null,
                null,
                $draft["revision"],
                additionalDepartments: array_map(
                    static fn(array $owner): EntityId => new EntityId($owner["id"]),
                    $owners,
                ),
            );
    }

    private function publish(ProgramPublicationFixture $p, array $year): array
    {
        $review = $p->service->review(
            $p->sis->program,
            new EntityId($year["id"]),
            "Fictional joint program review.",
        );
        return $p->service->publish($p->sis->program, $review["review_token"]);
    }

    public function testJointOwnersKeepTheirOwnCollegesWithoutAssigningTheProgramsCollegeOrComponents(): void
    {
        [$p, $owners] = $this->fixture();
        $events = $p->sis->sql->rows("sis_history_events");
        $draft = $this->save($p, $owners[0], [$owners[2], $owners[1]]);
        $facts = $draft["definition"]["classifications"];
        self::assertNull($facts["school"]);
        self::assertSame($owners[0]["school"], $facts["department_school"]);
        self::assertEquals(
            [$owners[1]["school"], $owners[2]["school"]],
            array_column($facts["additional_departments"], "school"),
        );
        self::assertSame([], $p->sis->sql->rows("sis_component_departments"));
        self::assertSame($events, $p->sis->sql->rows("sis_history_events"));
        $memberships = $p->sis->sql->rows("sis_program_departments");
        $this->save($p, $owners[0], [$owners[1], $owners[2]]);
        self::assertSame($memberships, $p->sis->sql->rows("sis_program_departments"));
    }

    public function testAcceptedPublicationsAndRetriesKeepOwnersAfterCurrentCollegeChanges(): void
    {
        [$p, $owners] = $this->fixture();
        $year = $p->calendar->year(2024);
        $this->save($p, $owners[0], [$owners[1]]);
        $review = $p->service->review(
            $p->sis->program,
            new EntityId($year["id"]),
            "Fictional retained ownership.",
        );
        $accepted = $p->service->publish($p->sis->program, $review["review_token"]);
        $pending = $p->service->review(
            $p->sis->program,
            new EntityId($year["id"]),
            "Fictional pending owner review.",
        );
        $retained = new SqlProgramDefinitionRepository($p->sis->sql)->retained(
            new EntityId($accepted["operation_id"]),
        );
        $school = $owners[1]["school"];
        $references = $p->classifications->page(ClassificationKind::School, $school["code"]);
        $reference = $references["items"][0];
        $p->classifications->save(
            ClassificationKind::School,
            new EntityId($school["id"]),
            $school["code"],
            "Fictional renamed college",
            true,
            $reference["revision"],
        );
        try {
            $p->service->publish($p->sis->program, $pending["review_token"]);
            self::fail("An unaccepted review ignored the changed joint college.");
        } catch (ConcurrentModificationException) {
            self::assertSame(
                $retained,
                new SqlProgramDefinitionRepository($p->sis->sql)->retained(
                    new EntityId($accepted["operation_id"]),
                ),
            );
        }
        self::assertSame(
            $accepted,
            $p->service->publish($p->sis->program, $review["review_token"]),
        );
        $this->save($p, $owners[0], []);
        $new = $this->publish($p, $year);
        self::assertSame(
            [],
            new SqlProgramDefinitionRepository($p->sis->sql)->retained(
                new EntityId($new["operation_id"]),
            )["definition"]["classifications"]["additional_departments"],
        );
        self::assertCount(1, $retained["definition"]["classifications"]["additional_departments"]);
    }

    public function testMissingKnownOwnershipCannotDowngradeAnAssignedCatalogToUnknown(): void
    {
        [$p, $owners] = $this->fixture();
        $year = $p->calendar->year(2024);
        $this->save($p, $owners[0], [$owners[1]]);
        $publication = $this->publish($p, $year);
        $p->sis->sql->execute("DELETE FROM sis_program_catalog_ownership WHERE id = ?", [
            $publication["operation_id"],
        ]);
        $this->expectException(CorruptHistoryException::class);
        $p->sis
            ->programEntryContext()
            ->proposal($p->sis->program, new EntityId($year["terms"][0]["id"]));
    }

    public function testMalformedRetainedOrDraftOwnershipIsRejectedAndEarlierAbsenceRoundTrips(): void
    {
        [$p, $owners] = $this->fixture();
        $draft = $this->save($p, $owners[0], [$owners[1]]);
        $old = $draft["definition"]["classifications"];
        unset($old["department_school"], $old["additional_departments"]);
        self::assertSame($old, ProgramClassifications::fromArray($old)->describe());
        $before = $p->sis->sql->rows("sis_program_departments");
        foreach (
            [
                [$owners[1], $owners[1]],
                [$owners[0]],
                [["id" => $owners[1]["school"]["id"]]],
                array_fill(0, 6, $owners[1]),
            ] as $invalid
        ) {
            try {
                $this->save($p, $owners[0], $invalid);
                self::fail("Invalid program ownership was accepted.");
            } catch (ValidationException) {
                self::assertSame($before, $p->sis->sql->rows("sis_program_departments"));
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        ProgramClassifications::fromArray($old + ["additional_departments" => []]);
    }

    public function testRetiredExistingOwnersCanMoveToPrimaryButCannotBeReassignedAfterRemoval(): void
    {
        [$p, $owners] = $this->fixture();
        $this->save($p, $owners[0], [$owners[1]]);
        $owner = $owners[1];
        $p->classifications->save(
            ClassificationKind::Department,
            new EntityId($owner["id"]),
            $owner["code"],
            $owner["title"],
            false,
            $owner["revision"],
            new EntityId($owner["school"]["id"]),
        );
        $promoted = $this->save($p, $owner, [$owners[0]]);
        self::assertSame(
            $owner["id"],
            $promoted["definition"]["classifications"]["department"]["id"],
        );
        $this->save($p, $owners[0], []);
        $this->expectException(ValidationException::class);
        $this->save($p, $owners[0], [$owner]);
    }

    public function testAuditIdentityAndSourceChangesRollBackTheWholeOwnershipDraft(): void
    {
        foreach (["audit", "identity", "source"] as $failure) {
            [$p, $owners] = $this->fixture();
            $s = $p->sis;
            $this->save($p, $owners[0], [$owners[1]]);
            $tables = [
                "sis_program_departments",
                "sis_program_details",
                "sis_programs",
                "sis_catalog_classifications",
                "sis_audit",
                "sis_history_events",
            ];
            $before = array_map($s->sql->rows(...), $tables);
            $audit = $this->createStub(AuditTrail::class);
            $audit
                ->method("record")
                ->willReturnCallback(static function () use ($s, $owners, $failure): void {
                    match ($failure) {
                        "audit" => throw new \RuntimeException("Fictional audit failure."),
                        "identity" => ($s->access->actor = EntityId::generate()),
                        "source" => $s->sql->update(
                            "sis_catalog_classifications",
                            ["title" => "Fictional late change"],
                            ["id" => $owners[2]["school"]["id"]],
                        ),
                    };
                });
            try {
                $this->save($p, $owners[0], [$owners[2]], $audit);
                self::fail("An incomplete program ownership change committed.");
            } catch (\RuntimeException | \DomainException) {
                self::assertSame($before, array_map($s->sql->rows(...), $tables));
            }
        }
    }
}
