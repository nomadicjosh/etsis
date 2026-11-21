<?php

declare(strict_types=1);

namespace Tests\Integration;

use CampusFixture;
use Domain\Person\Service\PersonProfileAccess;
use Domain\Person\Service\ProfileUpdateRequestService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use Infrastructure\Persistence\Repository\SqlPersonProfileRepository;
use Infrastructure\Persistence\Repository\SqlProfileUpdateRequestRepository;
use PHPUnit\Framework\TestCase;

final class ProfileUpdateRequestTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/CampusFixture.php";
    }
    private function service(
        CampusFixture $c,
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): ProfileUpdateRequestService {
        $s = $c->sis;
        return new ProfileUpdateRequestService(
            new SqlProfileUpdateRequestRepository($s->sql, $c->cipher),
            new PersonProfileAccess(
                new SqlPersonAccountRepository(
                    $s->sql,
                    $s->admissions,
                    new RandomInstitutionalIdGenerator(),
                ),
                $s->admissions,
                new SqlAccessRepository($s->sql),
                $s->access,
            ),
            new SqlPersonProfileRepository($s->sql),
            $s->profileLedger(),
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
        );
    }
    private function journal(CampusFixture $c): array
    {
        return array_map($c->sis->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
        ]);
    }
    private function rejects(callable $action, string $exceptionClass): void
    {
        try {
            $action();
        } catch (\Throwable $exception) {
            self::assertInstanceOf($exceptionClass, $exception);
            return;
        }
        self::fail('The rejected request action was accepted.');
    }

    public function testProspectWithoutAStudentCapabilityCanRespondAndUnicodeMessagesStayReadable(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $staff = $s->access->actor;
        $person = $s->registrationService()->staff(
            EntityId::generate(),
            'Fictional',
            'Prospect',
            'profile-request@example.invalid'
        );
        $service = $this->service($c);
        $id = EntityId::generate();
        $message = str_repeat('😀', 1000);
        $service->request($person->id, $id, 'contact', $message);
        $s->access->actor = $person->ownerUserId;
        $s->access->staff = false;
        $s->access->grants = [];
        $service->act(null, $id, false, 'respond', 0, $message);
        self::assertSame($message, $service->listing(null, false)['items'][0]['response']);
        $s->access->actor = $staff;
        $s->access->staff = true;
        $service->act($person->id, $id, true, 'close', 1, $message);
        self::assertSame($message, $service->listing($person->id, true, true)['items'][0]['resolution']);
        self::assertLessThan(32768, strlen($s->sql->rows('sis_profile_update_requests')[0]['sealed_data']));
        self::assertSame([], $s->sql->rows('sis_students'));
    }

    public function testStaffOwnerAndReviewerCompleteFollowUpWithoutAnotherProfileAuthority(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $staff = $s->access->actor;
        $owner = $s->user();
        $person = $s->student($owner)->personId;
        $id = EntityId::generate();
        $service = $this->service($c);
        $before = $this->journal($c);
        $created = $service->request(
            $person,
            $id,
            "contact",
            "Fictional instructions <script>literal</script>",
        );
        self::assertSame(["id" => $id->value, "status" => "requested", "version" => 0], $created);
        self::assertSame($before, $this->journal($c));
        self::assertStringNotContainsString(
            "Fictional instructions",
            json_encode($s->sql->rows("sis_profile_update_requests")),
        );
        $s->access->actor = $owner;
        $s->access->staff = false;
        $s->access->grants = [];
        $page = $service->listing(null, false);
        self::assertSame(
            "Fictional instructions <script>literal</script>",
            $page["items"][0]["instructions"],
        );
        self::assertArrayNotHasKey("owner_user_id", $page["items"][0]);
        self::assertArrayNotHasKey("receipts", $page["items"][0]);
        $c->contacts->save("Updated name", "0001234", "", 0);
        $afterEdit = $this->journal($c);
        $responded = $service->act(null, $id, false, "respond", 0, "Fictional update is saved.");
        self::assertSame("responded", $responded["status"]);
        $s->access->actor = $staff;
        $s->access->staff = true;
        $closed = $service->act(
            $person,
            $id,
            true,
            "close",
            1,
            "Fictional current contact reviewed.",
        );
        self::assertSame("closed", $closed["status"]);
        self::assertSame(
            $created,
            $service->request(
                $person,
                $id,
                "contact",
                "Fictional instructions <script>literal</script>",
            ),
        );
        self::assertSame([], $service->listing($person, true)["items"]);
        self::assertSame("closed", $service->listing($person, true, true)["items"][0]["status"]);
        $s->access->actor = $owner;
        $s->access->staff = false;
        self::assertSame(
            $responded,
            $service->act(null, $id, false, "respond", 0, "Fictional update is saved."),
        );
        self::assertSame("closed", $service->listing(null, false, true)["items"][0]["status"]);
        self::assertSame($afterEdit, $this->journal($c));
    }
    public function testAllSupportedAreasSharePendingLimitsAndOriginalCancellationRetries(): void
    {
        $c = new CampusFixture();
        $person = $c->sis->student($c->sis->user())->personId;
        $service = $this->service($c);
        foreach (["contact", "addresses", "emergency_contacts"] as $area) {
            $id = EntityId::generate();
            $service->request($person, $id, $area, "Fictional update requested.");
            try {
                $service->request($person, EntityId::generate(), $area, "Another request.");
                self::fail("A duplicate active area request was accepted.");
            } catch (ConcurrentModificationException) {
                self::assertCount(
                    1,
                    array_filter(
                        $service->listing($person, true)["items"],
                        static fn(array $r): bool => $r["area"] === $area,
                    ),
                );
            }
            $cancelled = $service->act($person, $id, true, "cancel", 0, "Fictional cancellation.");
            $service->request(
                $person,
                EntityId::generate(),
                $area,
                "Fictional replacement request.",
            );
            self::assertSame(
                $cancelled,
                $service->act($person, $id, true, "cancel", 0, "Fictional cancellation."),
            );
        }
        self::assertCount(3, $service->listing($person, true)["items"]);
        self::assertSame([], $c->sis->sql->rows("sis_history_events"));
    }
    public function testUnrelatedOwnersAndWrongChannelsNeverGainProfileManagementAuthority(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $owner = $s->user();
        $person = $s->student($owner)->personId;
        $id = EntityId::generate();
        $service = $this->service($c);
        $service->request($person, $id, "addresses", "Fictional private instructions.");
        $other = $s->user();
        $s->student($other);
        $s->access->actor = $other;
        $s->access->staff = false;
        $s->access->grants = [];
        self::assertSame([], $service->listing(null, false)["items"]);
        foreach (
            [
                fn() => $service->act(null, $id, false, "respond", 0, "Unrelated"),
                fn() => $service->request($person, EntityId::generate(), "contact", "Unauthorized"),
                fn() => $service->listing($person, true),
            ] as $action
        ) {
            try {
                $action();
                self::fail("An unrelated account accessed a private request.");
            } catch (AccessDeniedException | \Domain\Shared\Exception\RecordNotFoundException) {
                self::assertTrue(true);
            }
        }
        $s->access->actor = $owner;
        $this->expectException(AccessDeniedException::class);
        $service->act(null, $id, false, "close", 0, "Owner cannot approve staff follow-up.");
    }
    public function testFailedAuditAndStaleOrChangedInstructionsRollbackRequestState(): void
    {
        $c = new CampusFixture();
        $person = $c->sis->student($c->sis->user())->personId;
        $id = EntityId::generate();
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit
            ->method("record")
            ->willThrowException(new \RuntimeException("Fictional failed audit."));
        $this->rejects(fn() => $this->service($c, $audit)->request(
            $person,
            $id,
            "contact",
            "Fictional instructions."
        ), \RuntimeException::class);
        self::assertSame([], $c->sis->sql->rows("sis_profile_update_requests"));
        $service = $this->service($c);
        $service->request($person, $id, "contact", "Fictional instructions.");
        $before = $c->sis->sql->rows("sis_profile_update_requests");
        foreach (
            [
                fn() => $service->request($person, $id, "contact", "Different instructions."),
                fn() => $service->act($person, $id, true, "close", 1, "Stale outcome."),
                fn() => $this->service($c, $audit)->act(
                    $person,
                    $id,
                    true,
                    "cancel",
                    0,
                    "Cancelled.",
                ),
            ] as $action
        ) {
            $this->rejects($action, \RuntimeException::class);
            self::assertSame($before, $c->sis->sql->rows("sis_profile_update_requests"));
        }
    }

    public function testFinishedPagingRemainsPrivateAndChangingMetadataOrCiphertextFailsClosed(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $owner = $s->user();
        $person = $s->student($owner)->personId;
        $service = $this->service($c);
        for ($n = 0; $n < 12; $n++) {
            $id = EntityId::generate();
            $service->request($person, $id, "contact", "Fictional request " . $n);
            $service->act($person, $id, true, "cancel", 0, "Fictional superseded request.");
        }
        $first = $service->listing($person, true, true);
        self::assertCount(10, $first["items"]);
        self::assertNotNull($first["next_after"]);
        $last = $service->listing($person, true, true, new EntityId($first["next_after"]));
        self::assertCount(2, $last["items"]);
        self::assertNull($last["next_after"]);
        self::assertCount(
            12,
            array_unique([
                ...array_column($first["items"], "id"),
                ...array_column($last["items"], "id"),
            ]),
        );
        $row = $s->sql->one("sis_profile_update_requests", ["id" => $first["items"][0]["id"]]);
        $s->sql->update("sis_profile_update_requests", ["version" => 0], ["id" => $row["id"]]);
        try {
            $service->listing($person, true, true);
            self::fail("Conflicting metadata was returned.");
        } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
            self::assertTrue(true);
        }
        $s->sql->update(
            "sis_profile_update_requests",
            ["version" => $row["version"], "sealed_data" => "Damaged private note"],
            ["id" => $row["id"]],
        );
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $service->listing($person, true, true);
    }
    public function testOwnerChangesDoNotTransferPrivateRequestsAndOnlySuperStaffManageSuperPeople(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $owner = $s->user();
        $person = $s->student($owner)->personId;
        $service = $this->service($c);
        $id = EntityId::generate();
        $service->request($person, $id, "contact", "Fictional original-account note.");
        $replacement = $s->user();
        $s->sql->update(
            "sis_persons",
            ["owner_user_id" => $replacement->value],
            ["id" => $person->value],
        );
        $staff = $s->access->actor;
        self::assertTrue($service->listing($person, true)["items"][0]["owner_changed"]);
        $s->access->actor = $replacement;
        $s->access->staff = false;
        self::assertSame([], $service->listing(null, false)["items"]);
        try {
            $service->act(null, $id, false, "respond", 0, "Not original owner.");
            self::fail("Request moved to replacement account.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $s->access->actor = $staff;
        $s->access->staff = true;
        self::assertSame(
            "cancelled",
            $service->act($person, $id, true, "cancel", 0, "Account routing changed.")["status"],
        );
        $roles = new SqlAccessRepository($s->sql);
        $roles->assign(
            \Domain\Access\Enum\GrantKind::UserRole,
            $replacement,
            $roles->roleNamed("super")->id,
            true,
        );
        $s->access->staff = false;
        $s->access->grants = ["sis:person:manage"];
        $this->expectException(AccessDeniedException::class);
        $service->listing($person, true);
    }
    public function testPermissionsAreRecheckedAfterRequiredAuditAndTheWholeMutationRollsBack(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $person = $s->student($s->user())->personId;
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method("record")->willReturnCallback(static function () use ($s): void {
            $s->access->staff = false;
            $s->access->grants = [];
        });
        try {
            $this->service($c, $audit)->request(
                $person,
                EntityId::generate(),
                "contact",
                "Fictional instructions.",
            );
            self::fail("Revoked authority was accepted.");
        } catch (AccessDeniedException) {
            self::assertSame([], $s->sql->rows("sis_profile_update_requests"));
        }
    }
    public function testMessagesAndAreasAreBoundedAndNoCurrentProfileFactsAreAdopted(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $person = $s->student($s->user())->personId;
        $service = $this->service($c);
        foreach (
            [
                ["education", "Use education review instead."],
                ["contact", ""],
                ["contact", str_repeat("x", 1001)],
                ["contact", "Private\x00message"],
            ] as [$area, $message]
        ) {
            try {
                $service->request($person, EntityId::generate(), $area, $message);
                self::fail("Invalid request was accepted.");
            } catch (\Domain\Shared\Exception\ValidationException) {
                self::assertSame([], $s->sql->rows("sis_profile_update_requests"));
            }
        }
        self::assertSame([], $s->sql->rows("sis_history_events"));
        self::assertSame([], $s->sql->rows("sis_portal_contacts"));
    }
}
