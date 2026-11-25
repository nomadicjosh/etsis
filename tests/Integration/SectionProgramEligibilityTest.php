<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Registration\Service\SectionProgramPolicyService;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlSectionProgramPolicyRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\OfferingCreditFixture;

final class SectionProgramEligibilityTest extends TestCase
{
    private OfferingCreditFixture $f;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . "/Support/OfferingCreditFixture.php";
        $this->f = new OfferingCreditFixture();
    }

    private function service(?\Domain\Audit\Repository\AuditTrail $audit = null): SectionProgramPolicyService
    {
        $s = $this->f->sis;
        return new SectionProgramPolicyService(
            new SqlSectionProgramPolicyRepository($s->sql),
            new SqlCatalogRevisionRepository($s->sql),
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
        );
    }

    private function save(array $ids): void
    {
        $service = $this->service();
        $service->save($this->f->section->id, $ids, $service->current($this->f->section->id)["revision"]);
    }

    private function anotherProgram(): EntityId
    {
        $id = EntityId::generate();
        $this->f->sis->sql->insert("sis_programs", [
            "id" => $id->value,
            "code" => "OTHER-" . substr($id->value, -6),
            "title" => "Fictional other program",
        ]);
        return $id;
    }

    private function facts(): array
    {
        return array_map($this->f->sis->sql->rows(...), [
            "sis_enrollments",
            "sis_academic_credits",
            "sis_student_accounts",
            "sis_account_balances",
            "sis_account_entries",
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_registration_batches",
        ]);
    }

    public function testWorkingPolicyIsAuditedAndMustBePublishedBeforeRegistration(): void
    {
        $f = $this->f;
        $student = $f->sis->student();
        $before = $this->facts();
        $this->save([$student->programId->value]);
        self::assertSame($before, $this->facts());
        self::assertSame(
            [$student->programId->value],
            $this->service()->current($f->section->id)["program_ids"],
        );
        $this->expectException(ValidationException::class);
        $f->sis->courseRegistrationService()->register($student->id, $f->section->id, true);
    }

    public function testPublishedPolicyAllowsItsProgramAndOriginalAcceptanceSurvivesLaterDraftChanges(): void
    {
        $f = $this->f;
        $student = $f->sis->student();
        $this->save([$student->programId->value]);
        $f->bind();
        $version = $f->publish();
        $f->select($version);
        $registered = $f->sis->courseRegistrationService()->register($student->id, $f->section->id, true);
        $this->save([$this->anotherProgram()->value]);
        $before = $this->facts();
        self::assertSame(
            $registered->id->value,
            $f->sis->courseRegistrationService()->register($student->id, $f->section->id, true)->id->value,
        );
        self::assertSame($before, $this->facts());
        $stored = $f->sis->sql->one("sis_section_versions", ["id" => $version->value]);
        self::assertSame([$student->programId->value], json_decode($stored["eligible_program_ids"], true));
    }

    public function testUnlistedProgramIsBlockedInSharedReadinessAndIndividualRegistrationWithoutEffects(): void
    {
        $f = $this->f;
        $student = $f->sis->student();
        $this->save([$this->anotherProgram()->value]);
        $f->bind();
        $f->select($f->publish());
        $review = $f->sis
            ->registrationBatches()
            ->review(new RegistrationBatchSelection($f->section->id, $student->id));
        $check = array_values(
            array_filter(
                $review["students"][0]["readiness"],
                static fn(array $r): bool => $r["code"] === "program_eligibility",
            ),
        )[0];
        self::assertSame("blocked", $check["status"]);
        self::assertStringNotContainsString(
            $student->programId->value,
            json_encode($check, JSON_THROW_ON_ERROR),
        );
        $before = $this->facts();
        try {
            $f->sis->courseRegistrationService()->register($student->id, $f->section->id, true);
            self::fail("An unlisted program registered.");
        } catch (ValidationException) {
            self::assertSame($before, $this->facts());
        }
    }

    public function testChangedDraftRequiresFreshPublicationAndCannotSilentlyEnforceAnUnpublishedRule(): void
    {
        $f = $this->f;
        $student = $f->sis->student();
        $f->bind();
        $f->select($f->publish());
        $this->save([$student->programId->value]);
        $before = $this->facts();
        try {
            $f->sis->courseRegistrationService()->register($student->id, $f->section->id, true);
            self::fail("An unpublished restriction was accepted.");
        } catch (\DomainException) {
            self::assertSame($before, $this->facts());
        }
        $f->amend($f->publish());
        self::assertSame(
            $student->id->value,
            $f->sis->courseRegistrationService()->register($student->id, $f->section->id, true)->studentId
                ->value,
        );
    }

    public function testStaleRevisionsAndInvalidListsCannotReplaceASavedPolicy(): void
    {
        $f = $this->f;
        $service = $this->service();
        $revision = $service->current($f->section->id)["revision"];
        $program = $this->anotherProgram();
        $service->save($f->section->id, [$program->value], $revision);
        try {
            $service->save($f->section->id, [], $revision);
            self::fail("A stale policy overwrote the current one.");
        } catch (ConcurrentModificationException) {
            self::assertSame([$program->value], $service->current($f->section->id)["program_ids"]);
        }
        foreach ([[$program->value, $program->value], array_fill(0, 21, $program->value)] as $ids) {
            try {
                $this->save($ids);
                self::fail("An invalid selection was saved.");
            } catch (ValidationException) {
                self::assertSame([$program->value], $service->current($f->section->id)["program_ids"]);
            }
        }
        $this->save([$program->value]);
        $this->save([]);
        self::assertSame([], $service->current($f->section->id)["program_ids"]);
    }

    public function testAuditFailureRollsBackTheWholePolicyAndRetainsOriginalMembershipIds(): void
    {
        $program = $this->anotherProgram();
        $this->save([$program->value]);
        $before = $this->f->sis->sql->rows("sis_section_program_eligibility");
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method("record")->willThrowException(new \RuntimeException("Fictional audit failure."));
        $service = $this->service($audit);
        try {
            $service->save($this->f->section->id, [], $service->current($this->f->section->id)["revision"]);
            self::fail("An unaudited policy was saved.");
        } catch (\RuntimeException) {
            self::assertSame($before, $this->f->sis->sql->rows("sis_section_program_eligibility"));
        }
    }

    public function testPendingPublicationReviewBecomesStaleWhenProgramMembershipChanges(): void
    {
        $f = $this->f;
        $f->bind();
        $term = $f->sis->sql->one("sis_terms", ["id" => $f->sis->term->value]);
        $review = $f->publications->review(
            $f->section->id,
            $term["starts_on"],
            $term["ends_on"],
            "Fictional review.",
        );
        $this->save([$this->anotherProgram()->value]);
        $before = $this->facts();
        try {
            $f->publications->publish($f->section->id, $review["review_token"]);
            self::fail("A stale policy was published.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->facts());
        }
    }
    public function testLostAuthorityAtTheFinalAuditRestoresOriginalMembership(): void
    {
        $s = $this->f->sis;
        $this->save([$s->program->value]);
        $before = $s->sql->rows("sis_section_program_eligibility");
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit
            ->expects(self::once())
            ->method("record")
            ->willReturnCallback(static function () use ($s): void {
                $s->access->staff = false;
            });
        $service = $this->service($audit);
        try {
            $service->save($this->f->section->id, [], $service->current($this->f->section->id)["revision"]);
            self::fail("A saved policy survived lost authority.");
        } catch (\Domain\Shared\Exception\AccessDeniedException) {
            self::assertSame($before, $s->sql->rows("sis_section_program_eligibility"));
        }
    }

    public function testPostAuditMembershipDamageRollsBackRatherThanClaimingASavedPolicy(): void
    {
        $s = $this->f->sis;
        $this->save([$s->program->value]);
        $before = $s->sql->rows("sis_section_program_eligibility");
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit
            ->expects(self::once())
            ->method("record")
            ->willReturnCallback(static function () use ($s): void {
                $s->sql->execute("DELETE FROM sis_section_program_eligibility");
            });
        $service = $this->service($audit);
        try {
            $service->save(
                $this->f->section->id,
                [$s->program->value],
                $service->current($this->f->section->id)["revision"],
            );
            self::fail("A damaged saved policy passed its final check.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows("sis_section_program_eligibility"));
        }
    }
}
