<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Catalog\Enum\ClassificationKind;
use Domain\Catalog\Service\ProgramSpecializationService;
use Domain\Catalog\ValueObject\CurriculumComponentKind;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use StudentSpecializationFixture;

final class JointCurriculumOwnershipTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) .
        "/Support/StudentSpecializationFixture.php";
    }

    private function fixture(): array
    {
        $owners = [];
        $f = new StudentSpecializationFixture(
            configure: static function (StudentSpecializationFixture $f) use (
                &$owners,
            ): void {
                foreach (["ENG", "LING", "HIST"] as $code) {
                    $school = $f->program->classifications->save(
                        ClassificationKind::School,
                        null,
                        "COL." . $code,
                        "Fictional " . $code . " college",
                        true,
                        null,
                    );
                    $owners[] = $f->program->classifications->save(
                        ClassificationKind::Department,
                        null,
                        $code,
                        "Fictional " . $code . " department",
                        true,
                        null,
                        new EntityId($school["id"]),
                    );
                }
                $c = $f->choices[0];
                $f->choices[0] = $f
                    ->choiceService()
                    ->save(
                        $f->sis->program,
                        new EntityId($c["id"]),
                        $c["revision"],
                        $c["code"],
                        $c["title"],
                        $c["description"],
                        true,
                        CurriculumComponentKind::Major,
                        new EntityId($owners[0]["id"]),
                        additionalDepartments: [
                            new EntityId($owners[1]["id"]),
                            new EntityId($owners[2]["id"]),
                        ],
                    );
            },
        );
        return [$f, $owners];
    }

    private function edit(
        StudentSpecializationFixture $f,
        array $ids,
        ?EntityId $primary = null,
        ?ProgramSpecializationService $service = null,
    ): array {
        $c = $f
            ->choiceService()
            ->get($f->sis->program, new EntityId($f->choices[0]["id"]));
        return ($service ?? $f->choiceService())->save(
            $f->sis->program,
            new EntityId($c["id"]),
            $c["revision"],
            $c["code"],
            $c["title"],
            $c["description"],
            true,
            CurriculumComponentKind::Major,
            $primary ?? new EntityId($c["department_id"]),
            additionalDepartments: $ids,
        );
    }

    public function testJointOwnersRetainTheirOwnCollegesWithoutAssigningTheBroadProgramsDepartment(): void
    {
        [$f, $owners] = $this->fixture();
        $c = $f->choices[0];
        self::assertCount(2, $c["additional_departments"]);
        $definitions = array_column($c["additional_departments"], null, "id");
        self::assertSame(
            $owners[1]["school"]["id"],
            $definitions[$owners[1]["id"]]["school"]["id"],
        );
        self::assertSame(
            $owners[2]["school"]["id"],
            $definitions[$owners[2]["id"]]["school"]["id"],
        );
        self::assertNull(
            $f->program->service->draft($f->sis->program)["definition"][
                "classifications"
            ]["department"],
        );
        self::assertCount(
            2,
            $f->choiceService()->page($f->sis->program)["items"][0][
                "additional_departments"
            ],
        );
        $reader = new \Domain\Catalog\Service\PublishedProgramReader(
            $f->history,
            new \Infrastructure\Persistence\Repository\SqlProgramDefinitionRepository(
                $f->sis->sql,
            ),
        );
        $retained = $reader->version(
            $f->sis->program,
            new EntityId($f->year["id"]),
            new EntityId($f->publication["operation_id"]),
            new \Domain\Shared\EventSourcing\HistoryReadBudget(),
        );
        self::assertEquals(
            $c["additional_departments"],
            $retained["specializations"]["offered"][0][
                "additional_departments"
            ],
        );
    }

    public function testJointOwnerChangesStaleReviewsAndPreserveEarlierCohortDefinitionsAndReceipts(): void
    {
        [$f, $owners] = $this->fixture();
        $program = $f->sis->program;
        $review = $f->program->service->review(
            $program,
            new EntityId($f->year["id"]),
            "Fictional joint-owner approval.",
        );
        $accepted = $f->program->service->publish(
            $program,
            $review["review_token"],
        );
        $pending = $f->program->service->review(
            $program,
            new EntityId($f->year["id"]),
            "Fictional next review.",
        );
        $owner = $owners[1];
        $f->program->classifications->save(
            ClassificationKind::Department,
            new EntityId($owner["id"]),
            $owner["code"],
            "Fictional renamed joint department",
            true,
            $owner["revision"],
            null,
        );
        $events = $f->sis->sql->rows("sis_history_events");
        try {
            $f->program->service->publish($program, $pending["review_token"]);
            self::fail("A stale joint-owner review was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($events, $f->sis->sql->rows("sis_history_events"));
        }
        self::assertSame(
            $accepted,
            $f->program->service->publish($program, $review["review_token"]),
        );
        $reader = new \Domain\Catalog\Service\PublishedProgramReader(
            $f->history,
            new \Infrastructure\Persistence\Repository\SqlProgramDefinitionRepository(
                $f->sis->sql,
            ),
        );
        $original = $reader->version(
            $program,
            new EntityId($f->year["id"]),
            new EntityId($f->publication["operation_id"]),
            new \Domain\Shared\EventSourcing\HistoryReadBudget(),
        );
        $saved = array_column(
            $original["specializations"]["offered"][0][
                "additional_departments"
            ],
            null,
            "id",
        );
        self::assertSame($owner["title"], $saved[$owner["id"]]["title"]);
        self::assertEquals($owner["school"], $saved[$owner["id"]]["school"]);
        self::assertSame($events, $f->sis->sql->rows("sis_history_events"));
    }

    public function testClearingJointOwnersStalesOnlyUnacceptedPublicationReviews(): void
    {
        [$f] = $this->fixture();
        $r = $f->program->service->review(
            $f->sis->program,
            new EntityId($f->year["id"]),
            "Fictional owner-list review.",
        );
        $saved = $this->edit($f, []);
        self::assertSame([], $saved["additional_departments"]);
        $this->expectException(ConcurrentModificationException::class);
        $f->program->service->publish($f->sis->program, $r["review_token"]);
    }

    public function testRetainedOwnershipRejectsPartialDuplicateAndUnboundedDefinitions(): void
    {
        [$f, $owners] = $this->fixture();
        $snapshot = $f->program->service->review(
            $f->sis->program,
            new EntityId($f->year["id"]),
            "Fictional ownership grammar review.",
        )["specializations"];
        $row = $snapshot["offered"][0];
        foreach (
            [
                null,
                ["named" => $row["additional_departments"][0]],
                [
                    $row["additional_departments"][0],
                    $row["additional_departments"][0],
                ],
                [$row["department"]],
                array_fill(0, 6, $row["additional_departments"][0]),
                [
                    [
                        "id" => $owners[1]["id"],
                        "code" => "LING",
                        "title" => "Incomplete school reference",
                    ],
                ],
            ] as $invalid
        ) {
            $changed = $snapshot;
            $changed["offered"][0]["additional_departments"] = $invalid;
            try {
                new \Domain\Catalog\ValueObject\ProgramSpecializationSnapshot(
                    $changed,
                );
                self::fail(
                    "An incomplete or ambiguous ownership publication was accepted.",
                );
            } catch (\InvalidArgumentException | ValidationException) {
                self::assertSame($row, $snapshot["offered"][0]);
            }
        }
        $earlier = $snapshot;
        unset($earlier["offered"][0]["additional_departments"]);
        $retained = new \Domain\Catalog\ValueObject\ProgramSpecializationSnapshot(
            $earlier,
        );
        self::assertArrayNotHasKey(
            "additional_departments",
            $retained->data["offered"][0],
        );
    }

    public function testDuplicatesPrimaryRepetitionWrongCategoriesAndOverLimitChoicesAreRejectedAtomically(): void
    {
        [$f, $owners] = $this->fixture();
        $id = new EntityId($owners[1]["id"]);
        $before = [
            $f->sis->sql->rows("sis_component_departments"),
            $f->sis->sql->rows("sis_audit"),
        ];
        foreach (
            [
                [$id, $id],
                [new EntityId($owners[0]["id"])],
                [new EntityId($owners[1]["school"]["id"])],
                array_fill(0, 6, $id),
            ] as $ids
        ) {
            try {
                $this->edit($f, $ids);
                self::fail("Invalid joint ownership was accepted.");
            } catch (ValidationException) {
                self::assertSame($before, [
                    $f->sis->sql->rows("sis_component_departments"),
                    $f->sis->sql->rows("sis_audit"),
                ]);
            }
        }
    }

    public function testAnExistingRetiredJointOwnerCanRemainButCannotBeNewlyAssigned(): void
    {
        [$f, $owners] = $this->fixture();
        $owner = $owners[1];
        $f->program->classifications->save(
            ClassificationKind::Department,
            new EntityId($owner["id"]),
            $owner["code"],
            $owner["title"],
            false,
            $owner["revision"],
            new EntityId($owner["school"]["id"]),
        );
        self::assertCount(
            1,
            $this->edit($f, [new EntityId($owner["id"])])[
                "additional_departments"
            ],
        );
        $this->edit($f, []);
        $this->expectException(ValidationException::class);
        $this->edit($f, [new EntityId($owner["id"])]);
    }

    public function testAuditAuthorityAndSourceFailuresRollBackDraftMembershipAndRequiredAudit(): void
    {
        foreach (["audit", "authority", "source"] as $failure) {
            [$f, $owners] = $this->fixture();
            $s = $f->sis;
            $tables = [
                "sis_component_departments",
                "sis_program_specializations",
                "sis_catalog_classifications",
                "sis_audit",
                "sis_history_events",
            ];
            $before = array_map($s->sql->rows(...), $tables);
            $audit = $this->createStub(
                \Domain\Audit\Repository\AuditTrail::class,
            );
            $audit
                ->method("record")
                ->willReturnCallback(static function () use (
                    $s,
                    $owners,
                    $failure,
                ): void {
                    match ($failure) {
                        "audit" => throw new \RuntimeException(
                            "Fictional audit failure.",
                        ),
                        "authority" => ($s->access->staff = false),
                        "source" => $s->sql->update(
                            "sis_catalog_classifications",
                            ["title" => "Fictional late school change"],
                            ["id" => $owners[1]["school"]["id"]],
                        ),
                    };
                });
            $service = new ProgramSpecializationService(
                new \Infrastructure\Persistence\Repository\SqlProgramSpecializationRepository(
                    $s->sql,
                ),
                new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository(
                    $s->sql,
                ),
                $s->access,
                $s->transactions,
                $audit,
                new \Infrastructure\Persistence\Repository\SqlClassificationRepository(
                    $s->sql,
                ),
            );
            try {
                $this->edit(
                    $f,
                    [new EntityId($owners[1]["id"])],
                    service: $service,
                );
                self::fail("An incomplete ownership change committed.");
            } catch (\RuntimeException | \DomainException) {
                self::assertSame(
                    $before,
                    array_map($s->sql->rows(...), $tables),
                );
            }
        }
    }
}
