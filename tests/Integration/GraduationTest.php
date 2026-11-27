<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\GraduationFixture;
use SisFixture;
use TranscriptFixture;
use RuntimeException;
use PHPUnit\Framework\TestCase;

final class GraduationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/GraduationFixture.php";
    }

    public function testReviewedEligibilityAndIndependentConferralCloseOnlyTheAwardedPursuit(): void
    {
        $f = new GraduationFixture();
        $review = $f->review();
        self::assertTrue($review["summary"]["eligible"]);
        $f->confirm($review);
        self::assertNull($f->current()["credential"]);
        $conferral = $f->review("confer");
        $f->confirm($conferral);
        self::assertSame(
            "Bachelor of Science",
            $f->current()["credential"]["proposal"]["title"],
        );
        self::assertSame(
            "closed",
            $f->sis->sql->rows(
                "sis_student_programs",
                ["id" => $f->curriculum->assignment->value],
                1,
            )[0]["status"],
        );
        self::assertTrue(
            $f->sis->admissions->student($f->curriculum->student)->active,
        );
        $before = $f->sis->sql->rows("sis_history_events");
        $f->confirm($conferral);
        self::assertSame($before, $f->sis->sql->rows("sis_history_events"));
        self::assertCount(0, $f->service->queue()["items"]);
    }

    public function testIncompleteCurriculumAndUnconfirmedInstitutionRequirementsCannotConfer(): void
    {
        foreach ([false, true] as $complete) {
            $f = new GraduationFixture($complete);
            $review = $f->review("eligibility", [
                "proposal" => $f->proposal,
                "attested" => false,
                "history_acknowledged" => true,
            ]);
            self::assertFalse($review["summary"]["eligible"]);
            $f->confirm($review);
            $before = $f->facts();
            try {
                $f->review("confer");
                self::fail(
                    "An incomplete eligibility decision must not confer.",
                );
            } catch (\Domain\Shared\Exception\ValidationException) {
                self::assertSame($before, $f->facts());
            }
        }
    }

    public function testCorrectionsAndRevocationRetainOriginalsAndNeverSilentlyReopen(): void
    {
        $f = new GraduationFixture();
        $f->confirm($eligibility = $f->review());
        $f->confirm($award = $f->review("confer"));
        $original = $f->current()["credential"];
        $f->confirm(
            $correction = $f->review("correct", [
                "title" => "Bachelor of Science in Computing",
                "honors" => "With distinction",
            ]),
        );
        self::assertSame($original["id"], $f->current()["credential"]["id"]);
        self::assertSame(
            "With distinction",
            $f->current()["credential"]["proposal"]["honors"],
        );
        $f->confirm($revocation = $f->review("revoke"));
        self::assertNull($f->current()["credential"]);
        self::assertTrue($f->current()["ever_awarded"]);
        self::assertSame(
            "closed",
            $f->sis->sql->rows(
                "sis_student_programs",
                ["id" => $f->curriculum->assignment->value],
                1,
            )[0]["status"],
        );
        $history = $f->service->timeline(
            $f->curriculum->student,
            $f->curriculum->assignment,
            "staff",
        );
        self::assertSame(
            ["eligibility", "confer", "correct", "revoke"],
            array_column($history["items"], "action"),
        );
        self::assertSame($original, $history["items"][1]["credential"]);
        self::assertStringNotContainsString(
            "Fictional reviewed graduation evidence",
            json_encode($history),
        );
        $before = $f->facts();
        foreach ([$eligibility, $award, $correction, $revocation] as $review) {
            $f->confirm($review);
        }
        self::assertSame($before, $f->facts());
    }

    public function testRequiredAuditFailureRollsBackAllChanges(): void
    {
        $f = new GraduationFixture();
        $f->confirm($f->review());
        $review = $f->review("confer");
        $audit = new class implements \Domain\Audit\Repository\AuditTrail {
            public function record(
                \Domain\Shared\ValueObject\EntityId $actor,
                string $action,
                \Domain\Shared\ValueObject\EntityId $subject,
            ): void {
                throw new RuntimeException("Fictional required audit failure.");
            }
            public function forSubject(
                \Domain\Shared\ValueObject\EntityId $subject,
            ): array {
                return [];
            }
        };
        $before = $f->facts();
        try {
            $f->confirm($review, $f->service($audit));
            self::fail("Required audit must succeed.");
        } catch (RuntimeException $error) {
            self::assertSame(
                "Fictional required audit failure.",
                $error->getMessage(),
            );
            self::assertSame($before, $f->facts());
        }
    }

    public function testNewerEligibilityInvalidatesUnacceptedReviewsButNotAcceptedReceipts(): void
    {
        $f = new GraduationFixture();
        $f->confirm($eligibility = $f->review());
        $award = $f->review("confer");
        $f->confirm($f->review());
        $before = $f->facts();
        try {
            $f->confirm($award);
            self::fail("The selected eligibility decision changed.");
        } catch (\Domain\Shared\Exception\ConcurrentModificationException) {
            self::assertSame($before, $f->facts());
        }
        $f->confirm($eligibility);
        self::assertSame($before, $f->facts());
    }

    public function testOwnerReadsAndIndependentGraduationDuties(): void
    {
        $f = new GraduationFixture();
        $f->sis->access->staff = false;
        $f->sis->access->grants = ["sis:student:read", "sis:graduation:review"];
        self::assertTrue($f->current()["can_review"]);
        self::assertFalse($f->current()["can_confer"]);
        $f->confirm($f->review());
        try {
            $f->review("confer");
            self::fail("Eligibility duty must not confer.");
        } catch (\Domain\Shared\Exception\AccessDeniedException) {
            self::assertNull($f->current()["credential"]);
        }
        $owner = $f->sis->admissions->person(
            $f->sis->admissions->student($f->curriculum->student)->personId,
        )->ownerUserId;
        $f->sis->access->actor = $owner;
        $f->sis->access->grants = [];
        $read = $f->service->current(
            $f->curriculum->student,
            $f->curriculum->assignment,
            "owner",
        );
        self::assertFalse($read["can_review"]);
        self::assertFalse($read["can_confer"]);
        $f->sis->access->actor = $f->sis->user();
        $this->expectException(
            \Domain\Shared\Exception\AccessDeniedException::class,
        );
        $f->service->current(
            $f->curriculum->student,
            $f->curriculum->assignment,
            "owner",
        );
    }

    public function testMissingSupportProjectionCannotHideAnAward(): void
    {
        $f = new GraduationFixture();
        $f->confirm($f->review());
        $f->confirm($f->review("confer"));
        $f->sis->sql->execute("DELETE FROM sis_graduation_cases WHERE id = ?", [
            $f->curriculum->assignment->value,
        ]);
        $this->expectException(
            \Domain\Shared\EventSourcing\CorruptHistoryException::class,
        );
        $f->sis
            ->graduationReader()
            ->credentials(
                $f->curriculum->student,
                new \Domain\Shared\EventSourcing\HistoryReadBudget(),
            );
    }

    public function testGroupConferralKeepsIndividualAwardsAndOriginalReceipts(): void
    {
        $f = new GraduationFixture();
        $other = $f->anotherCandidate();
        $f->confirm($f->review());
        $other->confirm($other->review());
        self::assertCount(2, $f->service->queue()["items"]);
        $review = $f->service->reviewGroup(
            [
                [
                    "student_id" => $f->curriculum->student->value,
                    "pursuit_id" => $f->curriculum->assignment->value,
                ],
                [
                    "student_id" => $other->curriculum->student->value,
                    "pursuit_id" => $other->curriculum->assignment->value,
                ],
            ],
            "Fictional authorized ceremony selection.",
        );
        self::assertCount(
            2,
            $f->service->confirmGroup($review["reviews"])["items"],
        );
        self::assertNotSame(
            $f->current()["credential"]["id"],
            $other->current()["credential"]["id"],
        );
        $before = $f->facts();
        $f->service->confirmGroup($review["reviews"]);
        self::assertSame($before, $f->facts());
    }

    public function testOneStaleCandidateRollsBackTheWholeGroup(): void
    {
        $f = new GraduationFixture();
        $other = $f->anotherCandidate();
        $f->confirm($f->review());
        $other->confirm($other->review());
        $reviews = [$f->review("confer"), $other->review("confer")];
        $other->confirm($other->review());
        $before = $f->facts();
        try {
            $f->service->confirmGroup($reviews);
            self::fail("One stale candidate must roll back the selection.");
        } catch (\Domain\Shared\Exception\ConcurrentModificationException) {
            self::assertSame($before, $f->facts());
            self::assertNull($f->current()["credential"]);
            self::assertNull($other->current()["credential"]);
        }
    }

    public function testGraduatedWorkCannotReachAnotherPursuitThroughEarlierOrdinaryReuse(): void
    {
        $f = new GraduationFixture();
        $s = $f->sis;
        $student = $f->curriculum->student;
        $target = \Domain\Shared\ValueObject\EntityId::generate();
        $program = \Domain\Shared\ValueObject\EntityId::generate();
        $s->sql->insert("sis_programs", [
            "id" => $program->value,
            "code" => "NEW.GRAD",
            "title" => "Fictional further study",
        ]);
        new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository(
            $s->sql,
        )->addAssignment(
            new \Domain\Student\StudentProgram(
                $target,
                $student,
                $program,
                $s->term,
            ),
        );
        $credit = new \Domain\Shared\ValueObject\EntityId(
            $s->sql->rows(
                "sis_academic_credits",
                ["student_id" => $student->value],
                1,
            )[0]["id"],
        );
        $scopes = $s->workPursuits();
        $review = $scopes->review(
            $student,
            $credit,
            $target,
            "approve_reuse",
            "Fictional earlier reuse approval.",
        );
        $scopes->commit($student, $credit, $review["review_token"]);
        $inputs = $s
            ->inputVerifier()
            ->verify(
                $student,
                new \Domain\Shared\EventSourcing\HistoryReadBudget(),
            );
        self::assertCount(
            1,
            $scopes->forPursuit(
                $inputs,
                $student,
                $target,
                new \Domain\Shared\EventSourcing\HistoryReadBudget(),
            )->items,
        );
        $f->confirm($f->review());
        $f->confirm($f->review("confer"));
        self::assertCount(
            0,
            $scopes->forPursuit(
                $inputs,
                $student,
                $target,
                new \Domain\Shared\EventSourcing\HistoryReadBudget(),
            )->items,
        );
        self::assertTrue(
            $scopes->page($student, assignment: $target)["items"][0][
                "transfer_required"
            ],
        );
        self::assertSame(
            "active",
            $s->sql->rows(
                "sis_student_programs",
                ["id" => $target->value],
                1,
            )[0]["status"],
        );
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
        $select = $s
            ->primaryProgramService()
            ->review(
                $student,
                $target,
                $revision,
                "Fictional continuing pursuit.",
            );
        $s->primaryProgramService()->commit(
            $student,
            $target,
            $select["review_token"],
        );
        self::assertSame(
            $program->value,
            $s->admissions->student($student)->programId->value,
        );
        $revoke = $scopes->review(
            $student,
            $credit,
            $target,
            "revoke_reuse",
            "Remove ineffective earlier reuse.",
        );
        $scopes->commit($student, $credit, $revoke["review_token"]);
        $this->expectException(
            \Domain\Shared\Exception\ValidationException::class,
        );
        $scopes->review(
            $student,
            $credit,
            $target,
            "approve_reuse",
            "Ordinary reuse cannot replace transfer.",
        );
    }

    public function testFinalAuditSourceDriftRollsBackConferralAndTheMutation(): void
    {
        $f = new GraduationFixture();
        $f->confirm($f->review());
        $review = $f->review("confer");
        $audit = new class ($f->sis) implements
            \Domain\Audit\Repository\AuditTrail
        {
            public function __construct(private SisFixture $sis)
            {
            }
            public function record(
                \Domain\Shared\ValueObject\EntityId $actor,
                string $action,
                \Domain\Shared\ValueObject\EntityId $subject,
            ): void {
                $id = $this->sis->sql->rows("sis_academic_credits")[0]["id"];
                $this->sis->sql->update(
                    "sis_academic_credits",
                    ["completed_milli" => 0],
                    ["id" => $id],
                );
            }
            public function forSubject(
                \Domain\Shared\ValueObject\EntityId $subject,
            ): array {
                return [];
            }
        };
        $before = $f->facts();
        try {
            $f->confirm($review, $f->service($audit));
            self::fail("The final academic source changed.");
        } catch (\Domain\Shared\Exception\ValidationException | \Domain\Shared\EventSourcing\CorruptHistoryException) {
            self::assertSame($before, $f->facts());
        }
    }

    public function testDuplicateAndOversizedGroupSelectionsCannotMutate(): void
    {
        $f = new GraduationFixture();
        $candidate = [
            "student_id" => $f->curriculum->student->value,
            "pursuit_id" => $f->curriculum->assignment->value,
        ];
        $before = $f->facts();
        foreach (
            [[], [$candidate, $candidate], array_fill(0, 11, $candidate)] as $selection
        ) {
            try {
                $f->service->reviewGroup($selection, "Fictional ceremony.");
                self::fail("Invalid selection must fail.");
            } catch (\Domain\Shared\Exception\ValidationException) {
                self::assertSame($before, $f->facts());
            }
        }
    }

    public function testFutureOrMissingCompletionEvidenceCannotCreateGraduationEligibility(): void
    {
        $f = new GraduationFixture();
        $f->proposal["awarded_on"] = "2099-01-01";
        $before = $f->facts();
        try {
            $f->review();
            self::fail("Future graduation must not be conferred early.");
        } catch (\Domain\Shared\Exception\ValidationException) {
            self::assertSame($before, $f->facts());
        }
        $f->proposal["awarded_on"] = "2026-07-31";
        $this->expectException(
            \Domain\Shared\Exception\ValidationException::class,
        );
        $f->review();
    }

    public function testTranscriptRetainsAwardsAndOriginalDocumentsAfterCorrectionAndRevocation(): void
    {
        require_once dirname(__DIR__) . "/Support/TranscriptFixture.php";
        $t = new TranscriptFixture();
        $f = new GraduationFixture(true, $t->sis);
        $f->confirm($f->review());
        $f->confirm($f->review("confer"));
        $s = $f->sis;
        $request = \Domain\Shared\ValueObject\EntityId::generate();
        $actor = $s->access->actor;
        $s->access->actor = $s->admissions->person(
            $s->admissions->student($f->curriculum->student)->personId,
        )->ownerUserId;
        $t->campus->service->requestTranscript(
            $request,
            $f->curriculum->student,
            "pickup",
            "",
        );
        $s->access->actor = $actor;
        $renderer = $this->createStub(
            \Domain\Transcript\Service\TranscriptDocumentRenderer::class,
        );
        $renderer
            ->method("html")
            ->willReturnCallback([
                new \Infrastructure\Documents\ProtectedTranscriptRenderer(),
                "html",
            ]);
        $renderer
            ->method("pdf")
            ->willReturn("%PDF-1.4 Fictional graduation transcript");
        $service = $t->service($renderer);
        $review = $service->review($request);
        $id = new \Domain\Shared\ValueObject\EntityId($review["operation_id"]);
        $service->prepare(
            $request,
            $id,
            $review["revision"],
            true,
            $review["review_token"],
        );
        $original = $service->html($request, $id);
        self::assertStringContainsString("Conferred credentials", $original);
        self::assertStringContainsString("Bachelor of Science", $original);
        $stale = $service->review($request);
        $f->confirm(
            $f->review("correct", [
                "title" => "Bachelor of Science",
                "honors" => "With distinction",
            ]),
        );
        try {
            $service->prepare(
                $request,
                new \Domain\Shared\ValueObject\EntityId($stale["operation_id"]),
                $stale["revision"],
                true,
                $stale["review_token"],
            );
            self::fail(
                "A credential correction requires a new transcript review.",
            );
        } catch (\Domain\Shared\Exception\ConcurrentModificationException) {
            self::assertSame($original, $service->html($request, $id));
        }
        $review = $service->review($request);
        $new = new \Domain\Shared\ValueObject\EntityId($review["operation_id"]);
        $service->prepare(
            $request,
            $new,
            $review["revision"],
            true,
            $review["review_token"],
        );
        self::assertStringContainsString(
            "With distinction",
            $service->html($request, $new),
        );
        $f->confirm($f->review("revoke"));
        self::assertSame($original, $service->html($request, $id));
        self::assertStringContainsString(
            "With distinction",
            $service->html($request, $new),
        );
        self::assertCount(
            0,
            $s
                ->graduationReader()
                ->credentials(
                    $f->curriculum->student,
                    new \Domain\Shared\EventSourcing\HistoryReadBudget(),
                ),
        );
    }
    public function testOnlyRevocationPermitsASeparatelyReviewedReopeningAndFreshConferral(): void
    {
        $f = new GraduationFixture();
        $f->confirm($f->review());
        $f->confirm($f->review("confer"));
        $s = $f->sis;
        $pursuit = new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository(
            $s->sql,
        )->assignment($f->curriculum->assignment);
        $revision = hash(
            "sha256",
            json_encode(
                [
                    $pursuit->id->value,
                    $pursuit->studentId->value,
                    $pursuit->programId->value,
                    $pursuit->entryTermId->value,
                    $pursuit->status,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
        try {
            $s->programStatusService()->review(
                $pursuit->studentId,
                $pursuit->id,
                true,
                $revision,
                "Fictional reopening.",
            );
            self::fail("A currently awarded pursuit must not reopen.");
        } catch (\Domain\Shared\Exception\ValidationException) {
            self::assertNotNull($f->current()["credential"]);
        }
        $original = $f->current()["credential"]["id"];
        $f->confirm($f->review("revoke"));
        $review = $s
            ->programStatusService()
            ->review(
                $pursuit->studentId,
                $pursuit->id,
                true,
                $revision,
                "Fictional reopening after revocation.",
            );
        $s->programStatusService()->commit(
            $pursuit->studentId,
            $pursuit->id,
            $review["review_token"],
        );
        $f->confirm($f->review());
        $f->confirm($f->review("confer"));
        self::assertNotSame($original, $f->current()["credential"]["id"]);
    }

    public function testChangedCanonicalPersonCannotReuseAnEarlierCredentialStream(): void
    {
        $f = new GraduationFixture();
        $f->confirm($f->review());
        $other = $f->sis->legacyPerson(
            $f->sis->user(),
            "Fictional",
            "Unrelated person",
        );
        $f->sis->sql->update(
            "sis_students",
            ["person_id" => $other->id->value],
            ["id" => $f->curriculum->student->value],
        );
        $this->expectException(
            \Domain\Shared\EventSourcing\CorruptHistoryException::class,
        );
        $f->current();
    }
}
