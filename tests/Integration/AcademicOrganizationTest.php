<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Catalog\Enum\ClassificationKind;
use Domain\Catalog\ValueObject\CurriculumComponentKind;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use StudentSpecializationFixture;

final class AcademicOrganizationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/StudentSpecializationFixture.php";
    }
    private function organization(StudentSpecializationFixture $f): array
    {
        $school = $f->program->classifications->save(
            ClassificationKind::School,
            null,
            "ARTS",
            "Fictional Arts <school>",
            true,
            null,
        );
        $department = $f->program->classifications->save(
            ClassificationKind::Department,
            null,
            "ENG",
            "Fictional English <department>",
            true,
            null,
            new EntityId($school["id"]),
        );
        return [$school, $department];
    }
    private function own(
        StudentSpecializationFixture $f,
        array $department,
        int $revision = 1,
    ): array {
        $c = $f->choices[0];
        return $f
            ->choiceService()
            ->save(
                $f->sis->program,
                new EntityId($c["id"]),
                $revision,
                $c["code"],
                $c["title"],
                $c["description"],
                true,
                CurriculumComponentKind::Major,
                new EntityId($department["id"]),
            );
    }
    public function testDepartmentSchoolAndComponentOwnershipRemainSeparateFromTheBroadProgram(): void
    {
        $f = new StudentSpecializationFixture();
        [$school, $department] = $this->organization($f);
        $c = $this->own($f, $department);
        self::assertSame($school["id"], $department["school"]["id"]);
        self::assertSame($department["id"], $c["department"]["id"]);
        self::assertSame($school["id"], $c["department"]["school"]["id"]);
        self::assertNull(
            $f->program->service->draft($f->sis->program)["definition"]["classifications"][
                "department"
            ],
        );
        $page = $f->choiceService()->page($f->sis->program);
        self::assertSame($department["id"], $page["items"][0]["department"]["id"]);
        self::assertArrayNotHasKey("description", $page["items"][0]);
        $rows = $f->program->classifications->page(ClassificationKind::Department)["items"];
        self::assertSame(
            "Fictional Arts <school>",
            array_column($rows, null, "id")[$department["id"]]["school"]["title"],
        );
    }
    public function testRenamingOrMovingTheDepartmentStalesReviewAndRetainsAcceptedPublications(): void
    {
        $f = new StudentSpecializationFixture();
        [$school, $department] = $this->organization($f);
        $this->own($f, $department);
        $accepted = $f->publish();
        $review = $f->program->service->review(
            $f->sis->program,
            new EntityId($f->year["id"]),
            "Fictional next organization review.",
        );
        $f->program->classifications->save(
            ClassificationKind::School,
            new EntityId($school["id"]),
            "ARTS",
            "Renamed fictional college",
            true,
            $school["revision"],
        );
        $before = $f->sis->sql->rows("sis_history_events");
        try {
            $f->program->service->publish($f->sis->program, $review["review_token"]);
            self::fail("An unreviewed owner label was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $f->sis->sql->rows("sis_history_events"));
        }
        $history = $f->program->service->versions($f->sis->program);
        $latest = array_column($history["items"], null, "version_id")[$accepted["operation_id"]][
        "specializations"
        ]["offered"][0]["department"];
        self::assertSame("Fictional Arts <school>", $latest["school"]["title"]);
        self::assertSame($department["id"], $latest["id"]);
        self::assertSame($before, $f->sis->sql->rows("sis_history_events"));
        $current = $f->program->classifications->page(ClassificationKind::Department)["items"][0];
        $f->program->classifications->save(
            ClassificationKind::Department,
            new EntityId($department["id"]),
            "ENG",
            "Renamed fictional department",
            true,
            $current["revision"],
            null,
        );
        $next = $f->program->service->review(
            $f->sis->program,
            new EntityId($f->year["id"]),
            "Fictional deliberate owner publication.",
        );
        self::assertNull($next["specializations"]["offered"][0]["department"]["school"]);
        self::assertSame(
            "Renamed fictional department",
            $next["specializations"]["offered"][0]["department"]["title"],
        );
    }
    public function testWrongCategoryAndNewRetiredReferencesAreRejectedButExistingRetiredOwnersCanRemain(): void
    {
        $f = new StudentSpecializationFixture();
        [$school, $department] = $this->organization($f);
        $before = $f->sis->sql->rows("sis_program_specializations");
        try {
            $this->own($f, $school);
            self::fail("A school was used as a department.");
        } catch (ValidationException) {
            self::assertSame($before, $f->sis->sql->rows("sis_program_specializations"));
        }
        $this->own($f, $department);
        $f->program->classifications->save(
            ClassificationKind::Department,
            new EntityId($department["id"]),
            "ENG",
            $department["title"],
            false,
            $department["revision"],
            new EntityId($school["id"]),
        );
        self::assertSame($department["id"], $this->own($f, $department, 2)["department_id"]);
        $c = $f->choices[1];
        $this->expectException(ValidationException::class);
        $f->choiceService()->save(
            $f->sis->program,
            new EntityId($c["id"]),
            1,
            $c["code"],
            $c["title"],
            null,
            true,
            CurriculumComponentKind::Minor,
            new EntityId($department["id"]),
        );
    }
    public function testSchoolChangesParticipateInDepartmentOptimisticConcurrency(): void
    {
        $f = new StudentSpecializationFixture();
        [$school, $department] = $this->organization($f);
        $updated = $f->program->classifications->save(
            ClassificationKind::Department,
            new EntityId($department["id"]),
            "ENG",
            $department["title"],
            true,
            $department["revision"],
            null,
        );
        self::assertNull($updated["school"]);
        self::assertNotSame($department["revision"], $updated["revision"]);
        $this->expectException(ConcurrentModificationException::class);
        $f->program->classifications->save(
            ClassificationKind::Department,
            new EntityId($department["id"]),
            "ENG",
            "Stale department edit",
            true,
            $department["revision"],
            new EntityId($school["id"]),
        );
    }
    public function testEarlierOwnershipAbsenceRoundTripsWithoutGuessingCurrentDepartments(): void
    {
        $f = new StudentSpecializationFixture();
        [$school, $department] = $this->organization($f);
        $this->own($f, $department);
        $review = $f->program->service->review(
            $f->sis->program,
            new EntityId($f->year["id"]),
            "Fictional snapshot shape check.",
        );
        $snapshot = $review["specializations"];
        foreach ($snapshot["offered"] as &$choice) {
            unset($choice["department"]);
        }
        unset($choice);
        $old = new \Domain\Catalog\ValueObject\ProgramSpecializationSnapshot($snapshot)->data;
        self::assertArrayNotHasKey("department", $old["offered"][0]);
        $snapshot["offered"][0]["department"] = [
            "id" => $department["id"],
            "code" => "ENG",
            "title" => "Fictional",
            "school" => ["id" => $school["id"]],
        ];
        $this->expectException(\InvalidArgumentException::class);
        new \Domain\Catalog\ValueObject\ProgramSpecializationSnapshot($snapshot);
    }
    public function testRequiredAuditAndFinalReferenceFailuresRollBackOrganizationWrites(): void
    {
        foreach (["audit", "reference", "authority"] as $case) {
            $f = new StudentSpecializationFixture();
            [$school, $department] = $this->organization($f);
            $s = $f->sis;
            $before = [
                $s->sql->rows("sis_catalog_classifications"),
                $s->sql->rows("sis_program_specializations"),
                $s->sql->rows("sis_audit"),
            ];
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
                    $school,
                    $case
): void {
                    $s->audit->record($actor, $action, $subject);
                    if ($case === "audit") {
                        throw new \RuntimeException("Fictional required audit failure.");
                    }
                    if ($case === "reference") {
                        $s->sql->update(
                            "sis_catalog_classifications",
                            ["title" => "Fictional late source change"],
                            ["id" => $school["id"]],
                        );
                    }
                    if ($case === "authority") {
                        $s->access->staff = false;
                    }
                });
            $service = new \Domain\Catalog\Service\ClassificationService(
                new \Infrastructure\Persistence\Repository\SqlClassificationRepository($s->sql),
                new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($s->sql),
                $s->access,
                $s->transactions,
                $audit,
            );
            try {
                $service->save(
                    ClassificationKind::Department,
                    new EntityId($department["id"]),
                    "ENG",
                    "Fictional edited department",
                    true,
                    $department["revision"],
                    new EntityId($school["id"]),
                );
                self::fail("A final failure left organization changes.");
            } catch (\RuntimeException) {
                self::assertSame($before, [
                    $s->sql->rows("sis_catalog_classifications"),
                    $s->sql->rows("sis_program_specializations"),
                    $s->sql->rows("sis_audit"),
                ]);
            }
        }
    }
    public function testMissingKnownSchoolCannotBecomeAnUnassignedPublication(): void
    {
        $f = new StudentSpecializationFixture();
        [$school, $department] = $this->organization($f);
        $this->own($f, $department);
        $f->sis->sql->execute('PRAGMA foreign_keys = OFF');
        $f->sis->sql->update(
            'sis_catalog_classifications',
            ['school_id' => EntityId::generate()->value],
            ['id' => $department['id']],
        );
        $before = $f->sis->sql->rows('sis_history_events');
        try {
            $f->publish();
            self::fail('A missing known school became an unassigned published owner.');
        } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
            self::assertSame($before, $f->sis->sql->rows('sis_history_events'));
        }
    }

    public function testLateOwnershipChangeRollsBackPublicationAndItsRequiredAudit(): void
    {
        $f = new StudentSpecializationFixture();
        [$school, $department] = $this->organization($f);
        $this->own($f, $department);
        $review = $f->program->service->review(
            $f->sis->program,
            new EntityId($f->year['id']),
            'Fictional final organization check.',
        );
        $s = $f->sis;
        $tables = ['sis_history_events', 'sis_history_streams', 'sis_history_operations',
            'sis_catalog_classifications', 'sis_program_catalogs', 'sis_program_catalog_details',
            'sis_program_specialization_contexts', 'sis_audit'];
        $before = array_map($s->sql->rows(...), $tables);
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit->expects(self::once())->method('record')->willReturnCallback(
            static function (EntityId $actor, string $action, EntityId $subject) use ($s, $school): void {
                $s->audit->record($actor, $action, $subject);
                $s->sql->update(
                    'sis_catalog_classifications',
                    ['title' => 'Fictional late college edit'],
                    ['id' => $school['id']],
                );
            },
        );
        try {
            $f->program->publisher(audit: $audit)->publish($s->program, $review['review_token']);
            self::fail('A final ownership change left a published curriculum.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, array_map($s->sql->rows(...), $tables));
        }
    }
}
