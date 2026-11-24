<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Service\RegistrationUpdatesService;
use Domain\Registration\Waitlist\WaitlistPolicy;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use Infrastructure\Persistence\Repository\SqlRegistrationUpdatesRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class RegistrationUpdatesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function service(
        SisFixture $s,
        ?AuditTrail $audit = null,
    ): RegistrationUpdatesService {
        return new RegistrationUpdatesService(
            new SqlRegistrationUpdatesRepository($s->sql),
            new SqlPersonAccountRepository(
                $s->sql,
                $s->admissions,
                new RandomInstitutionalIdGenerator(),
            ),
            $s->admissions,
            $s->waitlistLedger(),
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
        );
    }

    private function change(
        SisFixture $s,
        EntityId $student,
        EntityId $section,
        string $action,
        ?EntityId $entry,
    ): array {
        $service = $s->waitlists();
        $review = $service->review(
            $student,
            $section,
            $action,
            true,
            $entry,
            $action === "removed" ? "Private fictional removal reason." : null,
        );
        return $service->confirm(
            $student,
            $section,
            new EntityId($review["operation_id"]),
            $action,
            true,
            $review["revision"],
            $review["review_token"],
            $review["reason"],
        );
    }

    private function fixture(?bool $registered = false): array
    {
        $s = new SisFixture();
        $section = $s->section(capacity: 1);
        $filler = $s->student($s->user());
        $student = $s->student();
        $s->service->register($filler->id, $section->id);
        $waitlists = $s->waitlists();
        $policy = $waitlists->reviewPolicy(
            $section->id,
            new WaitlistPolicy(true, 50)->describe(),
            0,
            "Fictional policy.",
        );
        $waitlists->confirmPolicy(
            $section->id,
            new EntityId($policy["operation_id"]),
            $policy["policy"],
            $policy["version"],
            $policy["reason"],
            $policy["review_token"],
        );
        $joined = $this->change(
            $s,
            $student->id,
            $section->id,
            "requested",
            null,
        );
        self::assertSame([], $s->sql->rows("sis_registration_updates"));
        if ($registered) {
            $drop = $s->drops->review($filler->id, $section->id);
            $s->drops->commit(
                $filler->id,
                $section->id,
                new EntityId($drop["enrollment_id"]),
                $drop["version"],
                new EntityId($drop["operation_id"]),
                $drop["revision"],
                $drop["effective_on"],
            );
            $s->service->register($student->id, $section->id);
        } elseif ($registered === false) {
            $this->change(
                $s,
                $student->id,
                $section->id,
                "removed",
                new EntityId($joined["entry_id"]),
            );
        }
        return [$s, $student, $section];
    }

    public function testOutcomeNoticesAreMinimizedAndReadDeleteArePrivateMetadataOnly(): void
    {
        foreach ([false, true] as $registered) {
            [$s, $student, $section] = $this->fixture($registered);
            $service = $this->service($s);
            $events = $s->sql->rows("sis_history_events");
            $notice = $service->page()["items"][0];
            self::assertSame(
                $registered ? "registered" : "removed",
                $notice["status"],
            );
            foreach (
                [
                    "person_id",
                    "student_id",
                    "entry_id",
                    "operation_id",
                    "reason",
                    "fee_minor",
                ] as $private
            ) {
                self::assertArrayNotHasKey($private, $notice);
            }
            self::assertSame(
                ["unread" => false],
                $service->read(
                    new EntityId($notice["id"]),
                    $notice["revision"],
                ),
            );
            $read = $s->sql->rows("sis_registration_updates");
            $service->read(new EntityId($notice["id"]), $notice["revision"]);
            self::assertSame($read, $s->sql->rows("sis_registration_updates"));
            self::assertSame([], $service->page()["items"]);
            self::assertCount(1, $service->page(false)["items"]);
            self::assertSame(
                ["deleted" => true],
                $service->delete(
                    new EntityId($notice["id"]),
                    $notice["revision"],
                ),
            );
            self::assertSame([], $s->sql->rows("sis_registration_updates"));
            self::assertSame([], $service->page(false)["items"]);
            self::assertSame(
                ["deleted" => true],
                $service->delete(
                    new EntityId($notice["id"]),
                    $notice["revision"],
                ),
            );
            self::assertSame($events, $s->sql->rows("sis_history_events"));
            if ($registered) {
                $s->service->register($student->id, $section->id);
                self::assertSame([], $s->sql->rows("sis_registration_updates"));
            }
        }
    }

    public function testEvenPrivilegedOtherAccountsCannotReadOrDeleteAnotherPersonsNotice(): void
    {
        [$s] = $this->fixture();
        $notice = $this->service($s)->page()["items"][0];
        $s->access->actor = $s->user();
        $s->legacyPerson($s->access->actor, "Fictional", "OtherAccount");
        $service = $this->service($s);
        self::assertSame([], $service->page(false)["items"]);
        $service->delete(new EntityId($notice["id"]), $notice["revision"]);
        self::assertCount(1, $s->sql->rows("sis_registration_updates"));
        $this->expectException(AccessDeniedException::class);
        $service->read(new EntityId($notice["id"]), $notice["revision"]);
    }

    public function testReadAndDeletionFailuresRollBackNoticeAndOwnerChanges(): void
    {
        foreach (["read", "delete"] as $action) {
            foreach (["audit", "owner"] as $failure) {
                [$s, $student] = $this->fixture();
                $notice = $this->service($s)->page()["items"][0];
                $before = $s->sql->rows("sis_registration_updates");
                $owner = $s->admissions->person($student->personId)
                    ->ownerUserId;
                $audit = $this->createStub(AuditTrail::class);
                $audit
                    ->method("record")
                    ->willReturnCallback(static function () use (
                        $s,
                        $student,
                        $failure,
                    ): void {
                        if ($failure === "audit") {
                            throw new \RuntimeException(
                                "Fictional audit failure.",
                            );
                        }
                        $s->sql->update(
                            "sis_persons",
                            ["owner_user_id" => null],
                            ["id" => $student->personId->value],
                        );
                    });
                try {
                    $this->service($s, $audit)->$action(
                        new EntityId($notice["id"]),
                        $notice["revision"],
                    );
                    self::fail(
                        "A partial or unauthorized notification mutation committed.",
                    );
                } catch (\RuntimeException | AccessDeniedException) {
                    self::assertSame(
                        $before,
                        $s->sql->rows("sis_registration_updates"),
                    );
                    self::assertSame(
                        $owner->value,
                        $s->admissions->person($student->personId)->ownerUserId
                            ->value,
                    );
                }
            }
        }
    }

    public function testRelinkingAnAccountDoesNotTransferEarlierPrivateUpdates(): void
    {
        [$s, $student] = $this->fixture();
        $newOwner = $s->user();
        $s->sql->update(
            "sis_persons",
            ["owner_user_id" => $newOwner->value],
            ["id" => $student->personId->value],
        );
        $s->access->actor = $newOwner;
        self::assertSame([], $this->service($s)->page(false)["items"]);
    }

    public function testPagesAreBoundedAndDeletionDoesNotNeedTheCursorRowToSurvive(): void
    {
        [$s, $student, $section] = $this->fixture();
        for ($i = 0; $i < 20; ++$i) {
            $joined = $this->change(
                $s,
                $student->id,
                $section->id,
                "requested",
                null,
            );
            $this->change(
                $s,
                $student->id,
                $section->id,
                "removed",
                new EntityId($joined["entry_id"]),
            );
        }
        $service = $this->service($s);
        $page = $service->page();
        self::assertCount(20, $page["items"]);
        $last = $page["items"][19];
        $service->delete(new EntityId($last["id"]), $last["revision"]);
        $next = $service->page(
            true,
            $page["next"]["at"],
            new EntityId($page["next"]["id"]),
        );
        self::assertCount(1, $next["items"]);
        self::assertNull($next["next"]);
    }
    public function testAFailedCoordinatedRegistrationDoesNotLeaveAnOutcomeNotification(): void
    {
        [$s, $student, $section] = $this->fixture(null);
        $s->sql->update(
            "sis_sections",
            ["capacity" => 2],
            ["id" => $section->id->value],
        );
        $audit = $this->createStub(AuditTrail::class);
        $audit
            ->method("record")
            ->willReturnCallback(
                static fn() => throw new \RuntimeException(
                    "Fictional audit failure.",
                ),
            );
        $service = $s->registrationBatches(audit: $audit);
        $selection = new \Domain\Registration\ValueObject\RegistrationBatchSelection(
            $section->id,
            $student->id,
        );
        $review = $service->review($selection);
        $before = $s->sql->rows("sis_history_events");
        try {
            $service->commit(
                new EntityId($review["operation_id"]),
                $selection,
                $review["revision"],
                $review["review_token"],
            );
            self::fail("An unaudited coordinated registration committed.");
        } catch (\RuntimeException) {
            self::assertSame([], $s->sql->rows("sis_registration_updates"));
            self::assertSame($before, $s->sql->rows("sis_history_events"));
        }
    }
}
