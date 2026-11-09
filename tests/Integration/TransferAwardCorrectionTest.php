<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/TransferAwardFixture.php";
require_once dirname(__DIR__) . "/Support/TranscriptFixture.php";

use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\ValueObject\AwardChange;
use Infrastructure\Persistence\Repository\SqlTransferRepository;
use PHPUnit\Framework\TestCase;

final class TransferAwardCorrectionTest extends TestCase
{
    private function fixture(): TransferAwardFixture
    {
        $h = new HiatusFixture();
        return new TransferAwardFixture($h->sis, $h->cipher);
    }
    private function post(TransferAwardFixture $t, AwardChange $change): array
    {
        $service = $t->service();
        $review = $service->preview($t->student, $t->award, $change);
        $op = EntityId::generate();
        $saved = $service->confirm($t->student, $t->award, $op, $change, $review["revision"]);
        self::assertSame(
            $saved,
            $service->confirm($t->student, $t->award, $op, $change, $review["revision"]),
        );
        return $saved;
    }
    public function testCorrectionRetainsOriginalHistoryAndDoesNotChangeCatalogOrFinancialEvents(): void
    {
        $t = $this->fixture();
        $f = $t->sis;
        $service = $t->service();
        $original = $service->view($t->student, $t->award);
        $events = $f->financialEvents();
        $this->post(
            $t,
            new AwardChange("amend", 3125, "b", "Fictional corrected evaluation <script>private</script>"),
        );
        $current = $service->view($t->student, $t->award);
        self::assertSame(3125, $current["credit"]["completed_milli"]);
        self::assertSame(9375, $current["credit"]["grade_points_milli"]);
        self::assertSame(1, $current["credit"]["version"]);
        self::assertSame("B", $current["grade_code"]);
        self::assertSame($original["credit"]["course_code"], $current["credit"]["course_code"]);
        self::assertSame($events, $f->financialEvents());
        self::assertSame(
            3000,
            $f->sql->one("sis_transfer_equivalencies", ["id" => $t->equivalency->id->value])["credits_milli"],
        );
        $history = $service->history($t->student, $t->award)["items"];
        self::assertCount(1, $history);
        self::assertSame(3000, $history[0]["before"]["completed_milli"]);
        self::assertSame(3125, $history[0]["after"]["completed_milli"]);
        self::assertSame(1, $history[0]["after"]["version"]);
        foreach (["sis_transfer_award_changes", "sis_audit"] as $table) {
            self::assertStringNotContainsString(
                "Fictional corrected evaluation",
                json_encode($f->sql->rows($table)),
            );
        }
    }
    public function testVoidingAndReinstatingChangeFutureEligibilityButKeepExistingEnrollments(): void
    {
        $t = $this->fixture();
        $f = $t->sis;
        $follow = $f->section(20, 0);
        $f->registration->addPrerequisite($follow->courseId, $t->course);
        self::assertTrue($f->registration->prerequisitesMet($t->student, $follow->courseId));
        $enrollment = $f->service->register($t->student, $follow->id);
        $this->post($t, new AwardChange("void", null, null, "Fictional duplicate evaluation"));
        $voided = $t->service()->view($t->student, $t->award)["credit"];
        self::assertSame("V", $voided["status"]);
        self::assertSame(0, $voided["attempted_milli"]);
        self::assertSame(0, $voided["completed_milli"]);
        self::assertSame(0, $voided["grade_points_milli"]);
        self::assertNull($voided["grade_id"]);
        self::assertFalse($f->registration->prerequisitesMet($t->student, $follow->courseId));
        try {
            $reviewed = new \Infrastructure\Persistence\Repository\SqlTransferCatalogRepository(
                $f->sql,
                $f->gradeCatalog(),
            )->get("equivalencies", $t->equivalency->id);
            $f->transferCredits()->award(
                $t->student,
                $t->equivalency,
                $f->term,
                new DateTimeImmutable("2026-05-01"),
                3,
                $reviewed,
                $f->access->actor,
            );
            self::fail("A voided award was silently resubmitted.");
        } catch (DomainException) {
            self::assertSame("V", $t->service()->view($t->student, $t->award)["credit"]["status"]);
        }
        self::assertTrue($f->registration->enrollment($t->student, $follow->id)->status->isEnrolled());
        $credits = new SqlTransferRepository($f->sql, $f->gradeCatalog())->transcript($t->student);
        self::assertSame(
            "Voided",
            array_values(array_filter($credits, fn(array $r): bool => $r["id"] === $t->credit->value))[0][
                "grade_code"
            ],
        );
        $this->post($t, new AwardChange("reinstate", 3000, "TR", "Fictional reinstatement approved"));
        self::assertTrue($f->registration->prerequisitesMet($t->student, $follow->courseId));
        self::assertSame(
            $enrollment->id->value,
            $f->registration->enrollment($t->student, $follow->id)->id->value,
        );
    }
    public function testOldReviewCannotBeReusedAfterAnotherCorrectionOrAnAbaChange(): void
    {
        $t = $this->fixture();
        $service = $t->service();
        $change = new AwardChange("amend", 4000, "B", "Fictional stale correction");
        $review = $service->preview($t->student, $t->award, $change);
        $this->post($t, new AwardChange("amend", 5000, "B", "Fictional intervening change"));
        $this->post($t, new AwardChange("amend", 3000, "TR", "Fictional restored values"));
        $this->expectException(ConcurrentModificationException::class);
        $service->confirm($t->student, $t->award, EntityId::generate(), $change, $review["revision"]);
    }
    public function testGradeAndIdentityChangesInvalidateTheReviewedProposal(): void
    {
        $t = $this->fixture();
        $service = $t->service();
        $change = new AwardChange("amend", 4000, "B", "Fictional grade policy correction");
        $review = $service->preview($t->student, $t->award, $change);
        $t->sis->sql->update("sis_grades", ["points_milli" => 3500], ["code" => "B"]);
        try {
            $service->confirm($t->student, $t->award, EntityId::generate(), $change, $review["revision"]);
            self::fail("Stale grade accepted.");
        } catch (ConcurrentModificationException) {
            self::assertCount(0, $t->sis->sql->rows("sis_transfer_award_changes"));
        }
        $review = $service->preview($t->student, $t->award, $change);
        $t->sis->sql->update(
            "sis_persons",
            ["given_name" => "Fictional corrected name"],
            ["id" => $review["record"]["person_id"]],
        );
        $this->expectException(ConcurrentModificationException::class);
        $service->confirm($t->student, $t->award, EntityId::generate(), $change, $review["revision"]);
    }
    public function testReceiptIsBoundToActorPayloadAndAwardAndStillRequiresCurrentPermissions(): void
    {
        $t = $this->fixture();
        $f = $t->sis;
        $service = $t->service();
        $change = new AwardChange("amend", 4000, "B", "Fictional durable reason");
        $review = $service->preview($t->student, $t->award, $change);
        $op = EntityId::generate();
        $saved = $service->confirm($t->student, $t->award, $op, $change, $review["revision"]);
        $f->sql->update("sis_grades", ["passes" => 0], ["code" => "B"]);
        self::assertSame(
            $saved,
            $service->confirm($t->student, $t->award, $op, $change, $review["revision"]),
        );
        self::assertSame($saved, $service->receipt($t->student, $t->award, $op));
        $f->access->actor = $f->user();
        try {
            $service->confirm($t->student, $t->award, $op, $change, $review["revision"]);
            self::fail("Another actor reused receipt.");
        } catch (ConcurrentModificationException) {
            self::assertCount(1, $f->sql->rows("sis_transfer_award_changes"));
        }
        $f->access->staff = false;
        $f->access->grants = ["sis:transfer:manage"];
        $this->expectException(AccessDeniedException::class);
        $service->receipt($t->student, $t->award, $op);
    }
    public function testBothRegistrarGrantsAndCorrectStudentNestingAreRequired(): void
    {
        $t = $this->fixture();
        $f = $t->sis;
        $service = $t->service();
        $other = $f->student()->id;
        try {
            $service->view($other, $t->award);
            self::fail("Award crossed student boundary.");
        } catch (RecordNotFoundException) {
            self::assertTrue(true);
        }
        foreach ([["sis:student:read"], ["sis:transfer:manage"], []] as $grants) {
            $f->access->staff = false;
            $f->access->grants = $grants;
            foreach (["view", "history"] as $method) {
                try {
                    $service->$method($t->student, $t->award);
                    self::fail("Insufficient grants exposed transfer history.");
                } catch (AccessDeniedException) {
                    self::assertTrue(true);
                }
            }
        }
    }
    public function testLateAuditFailureRollsBackCreditAndImmutableHistoryTogether(): void
    {
        $t = $this->fixture();
        $change = new AwardChange("amend", 4000, "B", "Fictional interrupted correction");
        $review = $t->service()->preview($t->student, $t->award, $change);
        $op = EntityId::generate();
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willThrowException(new RuntimeException("Fictional audit failure"));
        try {
            $t->service($audit)->confirm($t->student, $t->award, $op, $change, $review["revision"]);
            self::fail("Partial correction committed.");
        } catch (RuntimeException $e) {
            self::assertSame("Fictional audit failure", $e->getMessage());
        }
        self::assertSame(0, $t->service()->view($t->student, $t->award)["credit"]["version"]);
        self::assertNull($t->repository->action($op));
    }
    public function testValidationAndStateTransitionsDoNotAlterTheAward(): void
    {
        $t = $this->fixture();
        $cases1 = [
                ["void", 3000, null, "Fictional"],
                ["amend", 0, "TR", "Fictional"],
                ["amend", 3000, "TR", ""],
                ["amend", 3000, "F", "Fictional"],
                ["amend", 3000, "TR", "No change"],
            ];
        foreach ($cases1 as $args) {
            try {
                $t->service()->preview($t->student, $t->award, new AwardChange(...$args));
                self::fail("Invalid correction accepted.");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(DomainException::class);
        $t->service()->preview(
            $t->student,
            $t->award,
            new AwardChange("reinstate", 3000, "TR", "Cannot reopen active award"),
        );
    }
    public function testHistoryPagesUseAcademicVersionsAndRejectAForeignCursor(): void
    {
        $t = $this->fixture();
        for ($i = 0; $i < 53; ++$i) {
            $this->post($t, new AwardChange("amend", 3001 + $i, "TR", "Fictional correction " . $i));
        }
        $first = $t->service()->history($t->student, $t->award);
        self::assertCount(50, $first["items"]);
        self::assertSame(range(53, 4), array_column($first["items"], "version"));
        $next = $t->service()->history($t->student, $t->award, new EntityId($first["next_after"]));
        self::assertSame([3, 2, 1], array_column($next["items"], "version"));
        self::assertNull($next["next_after"]);
        $this->expectException(RecordNotFoundException::class);
        $t->service()->history($t->student, $t->award, EntityId::generate());
    }

    public function testAmendmentInvalidatesPreparedTranscriptWithoutRewritingTheDocument(): void
    {
        $tx = new TranscriptFixture();
        $request = $tx->request();
        $student = new EntityId($tx->repository->request($request)["student_id"]);
        $t = new TransferAwardFixture($tx->sis, $tx->campus->cipher, $student);
        $renderer = $this->createStub(\Domain\Transcript\Service\TranscriptDocumentRenderer::class);
        $renderer->method("pdf")->willReturn("%PDF-1.4 Fictional transfer snapshot");
        $documents = $tx->service($renderer);
        $review = $documents->review($request);
        $document = new EntityId($review["operation_id"]);
        $documents->prepare($request, $document, $review["revision"], true);
        $original = $documents->pdf($request, $document);
        $this->post($t, new AwardChange("void", null, null, "Fictional void invalidates preparation"));
        self::assertSame($original, $documents->pdf($request, $document));
        $this->expectException(ConcurrentModificationException::class);
        $documents->deliver(
            $request,
            $document,
            EntityId::generate(),
            1,
            $tx->sis->clock->now(),
            "Fictional pickup",
        );
    }
}
