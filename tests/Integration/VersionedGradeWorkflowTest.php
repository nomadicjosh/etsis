<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";
require_once dirname(__DIR__) . "/Support/TransferAwardFixture.php";
use Domain\Gradebook\Service\GradebookService;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlGradebookRepository;
use Infrastructure\Persistence\Repository\SqlWorkspaceRepository;
use PHPUnit\Framework\TestCase;
final class VersionedGradeWorkflowTest extends TestCase
{
    private function rows(int $points = 4000): array
    {
        return [
            [
                "code" => "A+",
                "min_score_milli" => 97000,
                "max_score_milli" => 100000,
                "points_milli" => $points,
                "passes" => true,
                "counts_gpa" => true,
            ],
            [
                "code" => "W",
                "min_score_milli" => null,
                "max_score_milli" => null,
                "points_milli" => 0,
                "passes" => false,
                "counts_gpa" => false,
            ],
        ];
    }
    private function book(SisFixture $f): GradebookService
    {
        return new GradebookService(
            new SqlGradebookRepository($f->sql),
            $f->registration,
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->academicCredits(),
        );
    }
    public function testPublicationCreatesImmutableRowsForIdenticalCodesAcrossProgramsAndVersions(): void
    {
        $f = new SisFixture();
        $a = $f->gradeScale($this->rows());
        $b = $f->gradeScale($this->rows(4300));
        $c = $f->gradeScale($this->rows(5000), $f->program);
        $rows = $f->sql->rows("sis_grades", ["code" => "A+"]);
        self::assertCount(3, $rows);
        self::assertCount(3, array_unique(array_column($rows, "id")));
        foreach ($rows as $row) {
            self::assertSame(
                PublishedGradeId::forVersion(new EntityId($row["scale_version_id"]), "A+")->value,
                $row["id"],
            );
        }
        $student = $f->student();
        self::assertSame(5000, $f->gradeCatalog()->byCode("A+", $student->id)["points_milli"]);
        $directory = new SqlWorkspaceRepository($f->sql, $f->gradeCatalog())->records(
            "grades",
            null,
            "A+",
            null,
        );
        self::assertSame([$b["operation_id"]], array_column($directory["items"], "scale_version_id"));
        self::assertSame(
            4000,
            (int) $f->sql->one("sis_grades", ["scale_version_id" => $a["operation_id"], "code" => "A+"])[
                "points_milli"
            ],
        );
        self::assertArrayNotHasKey(
            "definition_json",
            $f->sql->one("sis_grading_scales", ["program_id" => $f->program->value]),
        );
    }
    public function testFinalGradeUsesProgramScaleAndKeepsThatPolicyAfterLaterChanges(): void
    {
        $f = new SisFixture();
        $global = $f->gradeScale($this->rows());
        $first = $f->gradeScale($this->rows(5000), $f->program);
        $student = $f->student();
        $section = $f->section();
        $enrollment = $f->service->register($student->id, $section->id);
        $book = $this->book($f);
        $wrong = PublishedGradeId::forVersion(new EntityId($global["operation_id"]), "A+");
        $right = PublishedGradeId::forVersion(new EntityId($first["operation_id"]), "A+");
        try {
            $book->finalGrade($student->id, $section->id, $wrong, 0);
            self::fail("A different program scale was accepted.");
        } catch (ValidationException) {
            self::assertNull(
                $f->sql->one("sis_academic_credits", ["source_id" => $enrollment->id->value])["grade_id"],
            );
        }
        $book->finalGrade($student->id, $section->id, $right, 0);
        $before = $f->sql->one("sis_academic_credits", ["source_id" => $enrollment->id->value]);
        self::assertSame(15000, (int) $before["grade_points_milli"]);
        $next = $f->gradeScale($this->rows(6000), $f->program);
        self::assertSame(
            $before,
            $f->sql->one("sis_academic_credits", ["source_id" => $enrollment->id->value]),
        );
        self::assertSame(
            $first["operation_id"],
            $book->gradeChoices($student->id, $section->id)["items"][0]["scale_version_id"],
        );
        $this->expectException(ValidationException::class);
        $book->finalGrade(
            $student->id,
            $section->id,
            PublishedGradeId::forVersion(new EntityId($next["operation_id"]), "A+"),
            1,
        );
    }
    public function testDifferentDualDegreeScalesRequireExplicitStudentPursuit(): void
    {
        $f = new SisFixture();
        $f->gradeScale($this->rows());
        $f->gradeScale($this->rows(5000), $f->program);
        $student = $f->student();
        $other = EntityId::generate();
        $assignment = EntityId::generate();
        $f->sql->insert("sis_programs", [
            "id" => $other->value,
            "code" => "OTHER",
            "title" => "Other degree",
        ]);
        $f->sql->insert("sis_student_programs", [
            "id" => $assignment->value,
            "student_id" => $student->id->value,
            "program_id" => $other->value,
            "entry_term_id" => $f->term->value,
            "status" => "active",
        ]);
        $section = $f->section();
        $f->service->register($student->id, $section->id);
        $book = $this->book($f);
        self::assertTrue($book->gradeChoices($student->id, $section->id)["needs_assignment"]);
        $choices = $book->gradeChoices($student->id, $section->id, $assignment);
        self::assertFalse($choices["needs_assignment"]);
        self::assertSame(4000, $choices["items"][0]["points_milli"]);
        $book->finalGrade(
            $student->id,
            $section->id,
            new EntityId($choices["items"][0]["id"]),
            0,
            $assignment,
        );
        self::assertSame(
            12000,
            (int) $f->sql->rows("sis_academic_credits", ["student_id" => $student->id->value])[0][
                "grade_points_milli"
            ],
        );
    }
    public function testTamperedDefinitionCannotDriveAnAcademicWriteOrLookup(): void
    {
        $f = new SisFixture();
        $v = $f->gradeScale($this->rows());
        $student = $f->student();
        $section = $f->section();
        $f->service->register($student->id, $section->id);
        $id = PublishedGradeId::forVersion(new EntityId($v["operation_id"]), "A+");
        $f->sql->update("sis_grades", ["points_milli" => 5000], ["id" => $id->value]);
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $this->book($f)->finalGrade($student->id, $section->id, $id, 0);
    }
    public function testUnpublishedOlderRowsDoNotBecomeInventedGlobalPolicy(): void
    {
        $f = new SisFixture();
        $grade = new \Domain\Gradebook\GradeDefinition(EntityId::generate(), "A+", 5000, true, true);
        $f->legacyGrade($grade);
        self::assertSame("default", $f->gradingScales()->resolve()["source"]);
        self::assertSame(4000, $f->gradingScales()->resolve()["maximum_milli"]);
        self::assertSame($grade->id->value, $f->gradeCatalog()->byCode("A+")["id"]);
        $f->gradeScale($this->rows());
        self::assertNotSame($grade->id->value, $f->gradeCatalog()->byCode("A+")["id"]);
        self::assertSame(5000, (int) $f->sql->one("sis_grades", ["id" => $grade->id->value])["points_milli"]);
    }
    public function testNewTransferAwardsUseProgramGradesAndCorrectionsKeepTheirRetainedScale(): void
    {
        $f = new SisFixture();
        $t = new TransferAwardFixture($f, $f->dropCipher);
        $global = [
            [
                "code" => "TR",
                "min_score_milli" => null,
                "max_score_milli" => null,
                "points_milli" => 0,
                "passes" => true,
                "counts_gpa" => false,
            ],
            [
                "code" => "B",
                "min_score_milli" => null,
                "max_score_milli" => null,
                "points_milli" => 3000,
                "passes" => true,
                "counts_gpa" => true,
            ],
        ];
        $f->gradeScale($global);
        $program = $global;
        $program[0]["points_milli"] = 2000;
        $program[0]["counts_gpa"] = true;
        $program[1]["points_milli"] = 4000;
        $original = $f->gradeScale($program, $f->program);
        $student = $f->student();
        $award = EntityId::generate();
        $reviewed = new \Infrastructure\Persistence\Repository\SqlTransferCatalogRepository(
            $f->sql,
            $f->gradeCatalog(),
        )->get("equivalencies", $t->equivalency->id);
        $credit = $f
            ->transferCredits()
            ->award(
                $student->id,
                $t->equivalency,
                $f->term,
                new DateTimeImmutable("2026-05-02"),
                3,
                $reviewed,
                $f->access->actor,
            );
        $award = new EntityId($f->sql->one("sis_academic_credits", ["id" => $credit->value])["source_id"]);
        self::assertSame(
            6000,
            (int) $f->sql->one("sis_academic_credits", ["id" => $credit->value])["grade_points_milli"],
        );
        $program[1]["points_milli"] = 5000;
        $f->gradeScale($program, $f->program);
        $change = new \Domain\Transfer\ValueObject\AwardChange(
            "amend",
            3000,
            "B",
            "Fictional retained grading-policy correction",
        );
        $review = $t->service()->preview($student->id, $award, $change);
        $t->service()->confirm($student->id, $award, EntityId::generate(), $change, $review["revision"]);
        $after = $f->sql->one("sis_academic_credits", ["id" => $credit->value]);
        self::assertSame(12000, (int) $after["grade_points_milli"]);
        self::assertSame(
            $original["operation_id"],
            $f->sql->one("sis_grades", ["id" => $after["grade_id"]])["scale_version_id"],
        );
    }
    public function testTermDropGradeCodesResolveAgainstTheAffectedStudentsProgram(): void
    {
        $f = new SisFixture();
        $global = [
            [
                "code" => "D",
                "min_score_milli" => null,
                "max_score_milli" => null,
                "points_milli" => 1000,
                "passes" => true,
                "counts_gpa" => true,
            ],
        ];
        $f->gradeScale($global);
        $program = $global;
        $program[0]["points_milli"] = 500;
        $published = $f->gradeScale($program, $f->program);
        $student = $f->student();
        $f->dropPolicies->save(
            $f->term,
            -1,
            new \Domain\Registration\ValueObject\DropWindows([["through" => null, "grade_code" => "D"]]),
        );
        $policy = $f->dropPolicies->outcome($f->term, "2026-08-20", $student->id);
        self::assertSame(500, $policy["points_milli"]);
        self::assertSame(
            PublishedGradeId::forVersion(new EntityId($published["operation_id"]), "D")->value,
            $policy["grade_id"],
        );
        self::assertFalse($policy["delete_records"]);
    }

    public function testGradeChoicesRequireSectionTeachingAuthorityRatherThanStudentOwnership(): void
    {
        $f = new SisFixture();
        $f->gradeScale($this->rows());
        $owner = $f->access->actor;
        $student = $f->student();
        $faculty = $f->user();
        $section = $f->section(20, 0, $faculty);
        $f->service->register($student->id, $section->id);
        $f->access->actor = $faculty;
        $f->access->staff = false;
        self::assertCount(2, $this->book($f)->gradeChoices($student->id, $section->id)["items"]);
        $f->access->actor = $owner;
        $this->expectException(\Domain\Shared\Exception\AccessDeniedException::class);
        $this->book($f)->gradeChoices($student->id, $section->id);
    }
    public function testRecordedProgramGradingPolicyRemainsAvailableWhenThePursuitCloses(): void
    {
        $f = new SisFixture();
        $f->gradeScale($this->rows());
        $f->gradeScale($this->rows(5000), $f->program);
        $student = $f->student();
        $section = $f->section();
        $f->service->register($student->id, $section->id);
        $f->sql->update(
            "sis_student_programs",
            ["status" => "closed"],
            ["student_id" => $student->id->value],
        );
        $choices = $this->book($f)->gradeChoices($student->id, $section->id);
        self::assertSame(5000, $choices["items"][0]["points_milli"]);
    }
}
