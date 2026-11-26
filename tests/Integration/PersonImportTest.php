<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Import\Service\PersonImportService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Import\LeaguePersonCsvMapper;
use Infrastructure\Persistence\Repository\SqlPersonImportRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SisFixture;

final class PersonImportTest extends TestCase
{
    private function fixture(): SisFixture
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        return new SisFixture();
    }

    private function service(
        SisFixture $f,
        ?AuditTrail $audit = null,
    ): PersonImportService {
        $people = $f->registrationService();
        return new PersonImportService(
            new LeaguePersonCsvMapper(),
            new SqlPersonImportRepository($f->sql, $f->admissions),
            $people,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $f->dropCipher,
            $f->clock,
        );
    }

    private function review(PersonImportService $service, int $count = 2): array
    {
        $csv = "Given,Family,Email\n";
        for ($row = 0; $row < $count; $row++) {
            $csv .=
            "Fictional" .
            $row .
            ",Applicant,person" .
            $row .
            "@example.invalid\n";
        }
        return $service->preview(
            $csv,
            ["given_name" => 0, "family_name" => 1, "email" => 2],
            ",",
            "Fictional new-person intake",
        );
    }

    public function testReviewCreatesNothingAndConfirmationCreatesLinkedPrivatePendingAccountsAtomically(): void
    {
        $f = $this->fixture();
        $service = $this->service($f);
        $events = $f->sql->rows("sis_history_events");
        $review = $this->review($service);
        self::assertTrue($review["ready"]);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertSame([], $f->sql->rows("sis_person_imports"));
        self::assertStringNotContainsString(
            "person0@example.invalid",
            $review["review_token"],
        );
        $result = $service->commit($review["review_token"]);
        self::assertSame(2, $result["created"]);
        self::assertCount(2, $f->sql->rows("sis_persons"));
        self::assertCount(2, $f->sql->rows("sis_account_setup"));
        self::assertCount(1, $f->sql->rows("sis_person_imports"));
        self::assertSame([], $f->sql->rows("sis_students"));
        self::assertSame([], $f->sql->rows("sis_employees"));
        foreach ($result["people"] as $row) {
            $person = $f->admissions->person(new EntityId($row["person_id"]));
            self::assertSame($row["account_id"], $person->ownerUserId->value);
            self::assertSame(
                $row["institutional_id"],
                $person->institutionalId->value,
            );
            self::assertTrue($person->directoryRestricted);
            self::assertFalse(
                new \Infrastructure\Persistence\Repository\SqlAccessRepository(
                    $f->sql,
                )->isSuper($row["account_id"]),
            );
        }
        $stored = json_encode(
            $f->sql->rows("sis_person_imports"),
            JSON_THROW_ON_ERROR,
        );
        self::assertStringNotContainsString("Fictional", $stored);
        self::assertStringNotContainsString("@example.invalid", $stored);
    }

    public function testAcceptedRetryReturnsTheOriginalResultAfterExpiryAndLaterProfileOrDeliveryChanges(): void
    {
        require_once dirname(__DIR__) . '/Support/PersonManagementFixture.php';
        $managed = new \PersonManagementFixture();
        $f = $managed->sis;
        $service = $this->service($f);
        $review = $this->review($service);
        $receipt = $service->commit($review["review_token"]);
        $person = $receipt["people"][0]["person_id"];
        $id = new EntityId($person);
        $current = $managed->profiles->view($id);
        $managed->profiles->save(
            $id,
            'Later current label',
            'Applicant',
            true,
            $current['revision'],
            'Fictional later attributed correction'
        );
        $f->sql->update(
            "sis_account_setup",
            ["state" => "delivered"],
            ["person_id" => $person],
        );
        $f->clock->time = $f->clock->now()->modify("+2 days");
        $before = $f->sql->rows("sis_history_events");
        self::assertSame($receipt, $service->commit($review["review_token"]));
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        self::assertCount(2, $f->sql->rows("sis_persons"));
        self::assertFalse($this->review($service)["ready"]);
    }

    public function testExistingAndCaseInsensitiveDuplicateEmailsRequireCorrectionWithoutAutomaticLinking(): void
    {
        $f = $this->fixture();
        $service = $this->service($f);
        $f->registrationService()->staff(
            EntityId::generate(),
            "Existing",
            "Person",
            "person0@example.invalid",
        );
        $before = $f->sql->rows("sis_history_events");
        $review = $this->review($service);
        self::assertFalse($review["ready"]);
        self::assertNull($review["review_token"]);
        self::assertNotEmpty($review["rows"][0]["issues"]);
        $csv =
        "First,Last,Email\nA,Person,NEW@example.invalid\nB,Person,new@example.invalid\n";
        $duplicate = $service->preview(
            $csv,
            ["given_name" => 0, "family_name" => 1, "email" => 2],
            ",",
            "Fictional intake",
        );
        self::assertFalse($duplicate["ready"]);
        self::assertNotEmpty($duplicate["rows"][0]["issues"]);
        self::assertNotEmpty($duplicate["rows"][1]["issues"]);
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        self::assertCount(1, $f->sql->rows("sis_persons"));
    }

    public function testAConflictOnALaterRowRollsBackEarlierPeopleAccountsInvitationsAndHistory(): void
    {
        $f = $this->fixture();
        $service = $this->service($f);
        $review = $this->review($service);
        $f->registrationService()->staff(
            EntityId::generate(),
            "Concurrent",
            "Person",
            "person1@example.invalid",
        );
        $tables = [
            "users",
            "sis_persons",
            "sis_person_identifiers",
            "sis_account_setup",
            "sis_person_imports",
            "sis_history_streams", "sis_history_events",
            "sis_history_operations",
            "sis_audit",
        ];
        $before = array_map($f->sql->rows(...), $tables);
        try {
            $service->commit($review["review_token"]);
            self::fail("A conflicting later row allowed a partial import.");
        } catch (ValidationException) {
            self::assertSame($before, array_map($f->sql->rows(...), $tables));
        }
    }

    public function testExpiredUnacceptedReviewAndForeignActorOrMissingDutyCannotCreatePeople(): void
    {
        $f = $this->fixture();
        $service = $this->service($f);
        $review = $this->review($service);
        $f->clock->time = $f->clock->now()->modify("+20 minutes");
        try {
            $service->commit($review["review_token"]);
            self::fail("An expired unaccepted review was confirmed.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows("sis_persons"));
        }
        $f->access->actor = $f->user();
        try {
            $service->commit($review["review_token"]);
            self::fail("Another actor used the review.");
        } catch (AccessDeniedException) {
            self::assertSame([], $f->sql->rows("sis_person_imports"));
        }
        $f->access->staff = false;
        foreach (["sis:person:manage", "sis:import:manage"] as $duty) {
            $f->access->grants = [$duty];
            try {
                $this->review($service);
                self::fail("One duty implied the other duty.");
            } catch (AccessDeniedException) {
                self::assertSame([], $f->sql->rows("sis_persons"));
            }
        }
    }

    public function testFinalAuditSourceAuthorityAndExpiryFailuresRollbackTheEntireImport(): void
    {
        $iterationValues = ["audit", "source", "authority", "expiry", "receipt"];
        foreach ($iterationValues as $failure) {
            $f = $this->fixture();
            $audit = $this->createStub(AuditTrail::class);
            $audit
                ->method("record")
                ->willReturnCallback(function () use ($f, $failure): void {
                    match ($failure) {
                        "audit" => throw new RuntimeException(
                            "Fictional required audit failure",
                        ),
                        "source" => $f->sql->update(
                            "sis_persons",
                            ["given_name" => "Changed"],
                            [
                                "id" => $f->sql->rows("sis_persons", [], 1)[0][
                                    "id"
                                ],
                            ],
                        ),
                        "authority" => ($f->access->staff = false),
                        "expiry" => ($f->clock->time = $f->clock
                            ->now()
                            ->modify("+21 minutes")),
                        "receipt" => $f->sql->update(
                            "sis_person_imports",
                            ["proof_hash" => str_repeat("0", 64)],
                            [
                                "id" => $f->sql->rows(
                                    "sis_person_imports",
                                    [],
                                    1,
                                )[0]["id"],
                            ],
                        ),
                    };
                });
            $service = $this->service($f, $audit);
            $review = $this->review($service);
            $tables = [
                "users",
                "sis_persons",
                "sis_person_identifiers",
                "sis_account_setup",
                "sis_person_imports",
                "sis_history_streams", "sis_history_events",
                "sis_history_operations",
                "sis_audit",
            ];
            $before = array_map($f->sql->rows(...), $tables);
            try {
                $service->commit($review["review_token"]);
                self::fail("A failed final check committed an import.");
            } catch (RuntimeException | AccessDeniedException | ConcurrentModificationException) {
                self::assertSame(
                    $before,
                    array_map($f->sql->rows(...), $tables),
                );
            }
        }
    }

    public function testTenPeopleStayWithinTheBoundAndNoExtraStudentCapabilitiesAreCreated(): void
    {
        $f = $this->fixture();
        $service = $this->service($f);
        $review = $this->review($service, 10);
        $result = $service->commit($review["review_token"]);
        self::assertSame(10, $result["created"]);
        self::assertCount(10, $f->sql->rows("sis_persons"));
        self::assertCount(10, $f->sql->rows("sis_account_setup"));
        self::assertSame([], $f->sql->rows("sis_students"));
        self::assertSame([], $f->sql->rows("sis_employees"));
    }
}
