<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/StudentRequirementsFixture.php";

use Domain\DegreeAudit\Service\DegreeProgressReader;
use Domain\Portal\Service\StudentProgressService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\StudentProgram;
use Infrastructure\Persistence\Repository\SqlCampusRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;

final class PortalStudentProgressTest extends TestCase
{
    private StudentRequirementsFixture $f;
    private StudentProgressService $service;
    private DegreeProgressReader $progress;
    protected function setUp(): void
    {
        $this->f = new StudentRequirementsFixture();
        $s = $this->f->f->sis;
        $this->progress = $this->createMock(DegreeProgressReader::class);
        $this->service = new StudentProgressService(
            new SqlCampusRepository($s->sql),
            new SqlStudentRecordRepository($s->sql),
            $this->progress,
            $s->access,
        );
    }
    public function testProgramChoicesAreOwnedBoundedAndDoNotReplayProgress(): void
    {
        $this->progress->expects(self::never())->method("preview");
        $s = $this->f->f->sis;
        $repo = new SqlStudentRecordRepository($s->sql);
        $first = $repo->assignment($this->f->assignment);
        for ($i = 0; $i < 21; $i++) {
            $program = EntityId::generate();
            $s->sql->insert("sis_programs", [
                "id" => $program->value,
                "code" => "FIX." . $i,
                "title" => "Fictional program " . $i,
            ]);
            $repo->addAssignment(
                new StudentProgram(EntityId::generate(), $first->studentId, $program, $first->entryTermId),
            );
        }
        $page = $this->service->programs($this->f->student);
        self::assertCount(20, $page["items"]);
        self::assertNotNull($page["next_after"]);
        self::assertSame(
            [
                "id",
                "status",
                "program_code",
                "program_title",
                "term_code",
                "catalog_year",
                "catalog_revision",
            ],
            array_keys($page["items"][0]),
        );
        $next = $this->service->programs($this->f->student, new EntityId($page["next_after"]));
        self::assertCount(2, $next["items"]);
        self::assertNull($next["next_after"]);
        self::assertSame(
            [],
            array_intersect(array_column($page["items"], "id"), array_column($next["items"], "id")),
        );
    }
    public function testReviewUsesCanonicalReaderAndCannotSupplyAlternativePoliciesOrBudgets(): void
    {
        $this->progress
            ->expects(self::once())
            ->method("preview")
            ->with($this->f->student, $this->f->assignment)
            ->willReturn(["status" => "needs_selection"]);
        $controller = new \Application\Http\Controller\PortalStudentProgressController($this->service);
        $response = $controller->review($this->f->student->value, $this->f->assignment->value);
        self::assertSame(["status" => "needs_selection"], json_decode((string) $response->getBody(), true));
    }
    public function testStaffAuthorityCannotBypassPortalOwnership(): void
    {
        $this->progress->expects(self::never())->method("preview");
        $s = $this->f->f->sis;
        $other = $s->user();
        $s->admissionsService()->createPerson($other, "Other", "Owner");
        $s->access->actor = $other; // Fixture remains unrestricted staff.
        $this->expectException(AccessDeniedException::class);
        $this->service->review($this->f->student, $this->f->assignment);
    }
    public function testForeignPursuitIsRejectedBeforeReadingAnyAcademicWork(): void
    {
        $this->progress->expects(self::never())->method("preview");
        $s = $this->f->f->sis;
        $repo = new SqlStudentRecordRepository($s->sql);
        $original = $repo->assignment($this->f->assignment);
        $id = EntityId::generate();
        // Foreign target exists in the same fixture database; no student history is read.
        $person = $s->admissionsService()->createPerson($s->user(), "Other", "Learner");
        $application = $s->legacyApplication($person->id, $s->program, $original->entryTermId);
        $s->legacyAdmissionDecision($application->id, true);
        $other = $s->legacyStudent($application->id);
        $id = new EntityId(
            $s->sql->rows("sis_student_programs", ["student_id" => $other->id->value], 1)[0]["id"],
        );
        $this->expectException(RecordNotFoundException::class);
        $this->service->review($this->f->student, $id);
    }
    public function testChangedSignInDuringReviewDiscardsCapturedResult(): void
    {
        $this->progress
            ->expects(self::once())
            ->method("preview")
            ->willReturnCallback(function (): array {
                $this->f->f->sis->access->actor = EntityId::generate();
                return ["status" => "limited"];
            });
        $this->expectException(AccessDeniedException::class);
        $this->service->review($this->f->student, $this->f->assignment);
    }
    public function testOwnershipChangedDuringReviewDiscardsCapturedResult(): void
    {
        $this->progress
            ->expects(self::once())
            ->method("preview")
            ->willReturnCallback(function (): array {
                $s = $this->f->f->sis;
                $student = $s->admissions->student($this->f->student);
                $s->sql->update(
                    "sis_persons",
                    ["owner_user_id" => null],
                    ["id" => $student->personId->value],
                );
                return ["status" => "limited"];
            });
        $this->expectException(RecordNotFoundException::class);
        $this->service->review($this->f->student, $this->f->assignment);
    }
}
