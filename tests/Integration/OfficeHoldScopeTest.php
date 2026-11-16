<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Office\Service\OfficeScope;
use Domain\Office\Service\OfficeService;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Restriction;
use Domain\Student\Service\RestrictionService;
use EventStoreFixture;
use Infrastructure\Persistence\Repository\SqlOfficeRepository;
use Infrastructure\Persistence\Repository\SqlRestrictionRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SisFixture;

final class OfficeHoldScopeTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function offices(
        SisFixture $f,
        ?AuditTrail $audit = null,
    ): OfficeService {
        return new OfficeService(
            new SqlOfficeRepository($f->sql),
            $f->officeScope(),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $f->clock,
        );
    }

    private function holds(
        SisFixture $f,
        ?AuditTrail $audit = null,
    ): RestrictionService {
        return new RestrictionService(
            new SqlRestrictionRepository($f->sql),
            $f->admissions,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $f->dropCipher,
            $f->clock,
            $f->restrictionLedger(),
            $f->officeScope(),
            new SqlOfficeRepository($f->sql),
        );
    }

    private function hold(EntityId $person): Restriction
    {
        return new Restriction(
            EntityId::generate(),
            $person,
            "FICTIONAL",
            99,
            new DateRange(new DateTimeImmutable("2026-08-01")),
            true,
        );
    }

    private function otherOffice(SisFixture $f): EntityId
    {
        $id = EntityId::generate();
        $this->offices($f)->save($id, "LIB", "Fictional Library", true, -1);
        return $id;
    }

    private function delegate(
        SisFixture $f,
        EntityId $office,
        EntityId $user,
        ?string $end = null,
    ): void {
        $this->offices($f)->delegate(
            $office,
            $user,
            new DateTimeImmutable("2026-08-01"),
            true,
            -1,
            $end === null ? null : new DateTimeImmutable($end),
        );
    }

    private function becomeDelegate(SisFixture $f, EntityId $user): void
    {
        $f->access->actor = $user;
        $f->access->staff = false;
        $f->access->grants = [OfficeScope::HOLD_MANAGE];
    }

    public function testDelegationAndPermissionAreBothRequiredAndOtherOfficePrivatePathsAreDenied(): void
    {
        $f = new SisFixture();
        $user = $f->user();
        $person = $f->student()->personId;
        $other = $this->otherOffice($f);
        $own = $this->hold($person);
        $foreign = $this->hold($person);
        $service = $this->holds($f);
        $service->create($own, "Fictional registrar reason.", $f->office);
        $service->create($foreign, "Fictional library reason.", $other);
        $this->delegate($f, $f->office, $user);
        $foreignRevision = $service->view($foreign->id)["revision"];
        $this->becomeDelegate($f, $user);
        self::assertSame(
            [$own->id->value],
            array_column($service->page($person)["items"], "id"),
        );
        self::assertSame(
            [$own->id->value],
            array_column($service->queue()["items"], "id"),
        );
        self::assertCount(1, $service->notes($own->id)["items"]);
        $actionCases = [
            fn() => $service->view($foreign->id),
            fn() => $service->history($foreign->id),
            fn() => $service->notes($foreign->id),
            fn() => $service->appendNote(
                $foreign->id,
                EntityId::generate(),
                "Not permitted.",
            ),
            fn() => $service->end(
                $foreign->id,
                new DateTimeImmutable("2026-08-10"),
                $foreignRevision,
                EntityId::generate(),
                "Not permitted.",
            ),
            fn() => $service->create(
                $this->hold($person),
                "Not permitted.",
                $other,
            ),
        ];
        foreach ($actionCases as $action) {
            try {
                $action();
                self::fail(
                    "Another office exposed or changed private hold data.",
                );
            } catch (AccessDeniedException) {
                self::assertSame(
                    $foreignRevision,
                    hash(
                        "sha256",
                        json_encode($foreign->facts(), JSON_THROW_ON_ERROR),
                    ),
                );
            }
        }
        $f->access->grants = [];
        $this->expectException(AccessDeniedException::class);
        $service->view($own->id);
    }

    public function testDatedDelegationIncludesTheWholeEndDayAndRetirementDoesNotRemoveHoldEffects(): void
    {
        $f = new SisFixture();
        $user = $f->user();
        $student = $f->student();
        $hold = $this->hold($student->personId);
        $service = $this->holds($f);
        $service->create($hold, "Fictional reason.");
        $this->delegate($f, $f->office, $user, "2026-08-10");
        $this->becomeDelegate($f, $user);
        $f->clock->time = new DateTimeImmutable("2026-08-10T23:59:59-07:00");
        self::assertSame($hold->id->value, $service->view($hold->id)["id"]);
        $f->clock->time = new DateTimeImmutable("2026-08-11T00:00:00-07:00");
        self::assertSame([], $service->queue()["items"]);
        try {
            $service->view($hold->id);
            self::fail("An expired delegation remained usable.");
        } catch (AccessDeniedException) {
            self::assertSame(
                $hold->id->value,
                $f->registration->blockingRestriction(
                    $student->personId,
                    $f->clock->now(),
                    true,
                )->id->value,
            );
        }
        $f->access->staff = true;
        $this->offices($f)->save(
            $f->office,
            "REG",
            "Retired registrar",
            false,
            0,
        );
        self::assertSame(
            $hold->id->value,
            $f->registration->blockingRestriction(
                $student->personId,
                $f->clock->now(),
                false,
            )->id->value,
        );
    }

    public function testMissingAndFutureDelegationsDoNotFollowFromTheHoldPermission(): void
    {
        $f = new SisFixture();
        $user = $f->user();
        $this->offices($f)->delegate(
            $f->office,
            $user,
            new DateTimeImmutable("2026-08-11"),
            true,
            -1,
        );
        $this->becomeDelegate($f, $user);
        self::assertSame([], $this->offices($f)->choices()["items"]);
        $this->expectException(AccessDeniedException::class);
        $f->officeScope()->office($f->office);
    }

    public function testOfficeFilteringHappensBeforeTheHoldPageLimit(): void
    {
        $f = new SisFixture();
        $person = $f->student()->personId;
        $other = $this->otherOffice($f);
        $user = $f->user();
        $own = $this->hold($person);
        $this->holds($f)->create($own, "Fictional own hold.", $f->office);
        // Earlier rows are fixture state, not manufactured placement history.
        // Establish them before the next accepted observation in a different person stream.
        $otherPerson = $f->student()->personId;
        for ($i = 0; $i < 51; ++$i) {
            $foreign = $this->hold($otherPerson);
            new SqlRestrictionRepository($f->sql)->add($foreign);
            new SqlOfficeRepository($f->sql)->bind($foreign->id, $other);
        }
        $this->delegate($f, $f->office, $user);
        $this->becomeDelegate($f, $user);
        $queue = $this->holds($f)->queue();
        self::assertSame(
            [$own->id->value],
            array_column($queue["items"], "id"),
        );
        self::assertNull($queue["next_after"]);
    }

    public function testOfficeAndDelegationConfigurationIsAuditedCrudAndStaleChangesCannotRestoreAccess(): void
    {
        $f = new SisFixture();
        $user = $f->user();
        $before = $f->sql->rows("sis_history_events");
        $this->delegate($f, $f->office, $user);
        $service = $this->offices($f);
        $service->delegate(
            $f->office,
            $user,
            new DateTimeImmutable("2026-08-01"),
            false,
            0,
        );
        try {
            $service->delegate(
                $f->office,
                $user,
                new DateTimeImmutable("2026-08-01"),
                true,
                0,
            );
            self::fail("A stale delegation restored revoked access.");
        } catch (ConcurrentModificationException) {
            self::assertSame(
                0,
                (int) new SqlOfficeRepository($f->sql)->delegation(
                    $f->office,
                    $user,
                )["active"],
            );
        }
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        self::assertCount(
            2,
            $f->sql->rows("sis_audit", ["action" => "office.delegation_saved"]),
        );
        $this->becomeDelegate($f, $user);
        self::assertSame([], $f->officeScope()->visibleIds());
    }

    public function testOfficeRoutingTamperingIsRejectedAgainstRetainedAuthority(): void
    {
        $f = new SisFixture();
        $hold = $this->hold($f->student()->personId);
        $other = $this->otherOffice($f);
        $service = $this->holds($f);
        $service->create($hold, "Fictional protected decision.", $f->office);
        $f->sql->update(
            "sis_restriction_offices",
            ["office_id" => $other->value],
            ["id" => $hold->id->value],
        );
        $this->expectException(CorruptHistoryException::class);
        $service->view($hold->id);
    }

    public function testADelegationEndingDuringConfirmationRollsBackTheEntireDecision(): void
    {
        $f = new SisFixture();
        $user = $f->user();
        $hold = $this->hold($f->student()->personId);
        $this->delegate($f, $f->office, $user, "2026-08-10");
        $this->becomeDelegate($f, $user);
        $before = $f->sql->rows("sis_history_events");
        $audit = $this->createMock(AuditTrail::class);
        $audit
            ->expects(self::once())
            ->method("record")
            ->willReturnCallback(static function () use ($f): void {
                $f->clock->time = new DateTimeImmutable(
                    "2026-08-11T00:00:00-07:00",
                );
            });
        try {
            $this->holds($f, $audit)->create($hold, "Fictional reason.");
            self::fail("An expired delegation committed a hold.");
        } catch (AccessDeniedException) {
            self::assertSame([], $f->sql->rows("sis_restrictions"));
            self::assertSame([], $f->sql->rows("sis_restriction_offices"));
            self::assertSame([], $f->sql->rows("sis_restriction_notes"));
            self::assertSame($before, $f->sql->rows("sis_history_events"));
        }
    }

    public function testEarlierUnassignedHoldsNeedAdministrativeRoutingWithoutInventedHistoricalEvents(): void
    {
        $f = new SisFixture();
        $hold = $this->hold($f->student()->personId);
        new SqlRestrictionRepository($f->sql)->add($hold);
        $service = $this->holds($f);
        $before = $f->sql->rows("sis_history_events");
        $view = $service->view($hold->id);
        self::assertNull($view["office_id"]);
        try {
            $service->end(
                $hold->id,
                new DateTimeImmutable("2026-08-10"),
                $view["revision"],
                EntityId::generate(),
                "Fictional resolution.",
            );
            self::fail(
                "An unassigned earlier hold was ended without office routing.",
            );
        } catch (ValidationException $error) {
            self::assertSame("office_id", $error->errors[0]["field"]);
        }
        $service->bind(
            $hold->id,
            $f->office,
            EntityId::generate(),
            $view["revision"],
            "Fictional current routing.",
        );
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        $service->end(
            $hold->id,
            new DateTimeImmutable("2026-08-10"),
            $view["revision"],
            EntityId::generate(),
            "Fictional resolution.",
        );
        self::assertSame(
            ["observed", "ended"],
            array_column($service->history($hold->id)["items"], "action"),
        );
    }

    public function testHoldHistoryRetainsOfficeAndDelegationEvidenceAfterOfficeEdits(): void
    {
        $f = new SisFixture();
        $user = $f->user();
        $hold = $this->hold($f->student()->personId);
        $this->delegate($f, $f->office, $user);
        $this->becomeDelegate($f, $user);
        $this->holds($f)->create($hold, "Fictional delegated decision.");
        $f->access->staff = true;
        $this->offices($f)->save($f->office, "REG", "Renamed office", true, 0);
        $events = EventStoreFixture::framework(
            $f->connection->queryBuilder(),
        )->getAggregateHistoryFor(
            new \Domain\Student\ValueObject\PersonRestrictionsId(
                $hold->personId->value,
            ),
        );
        $found = null;
        foreach ($events as $event) {
            if ($event->payload()["action"] === "placed") {
                $found = $event->payload()["authority"];
            }
        }
        self::assertSame("Fictional Registrar", $found["office_name"]);
        self::assertSame("delegated", $found["mode"]);
        self::assertSame(0, $found["delegation_revision"]);
        self::assertSame($f->office->value, $found["office_id"]);
    }

    public function testOfficeDelegationsDoNotGrantOtherRecordCapabilitiesAndDisabledAccountsCannotAct(): void
    {
        $f = new SisFixture();
        $user = $f->user();
        $this->delegate($f, $f->office, $user);
        $this->becomeDelegate($f, $user);
        $permissionCases = [
            "sis:registration:manage",
            "sis:student:read",
            "sis:finance:read",
            OfficeScope::ADMIN,
        ];
        foreach ($permissionCases as $permission) {
            try {
                $f->access->requirePermission($permission);
                self::fail("Delegation granted an unrelated permission.");
            } catch (AccessDeniedException) {
                self::assertSame(
                    [OfficeScope::HOLD_MANAGE],
                    $f->access->grants,
                );
            }
        }
        $f->sql->update(
            "users",
            ["sign_in_enabled" => 0],
            ["user_id" => $user->value],
        );
        $this->expectException(AccessDeniedException::class);
        $f->officeScope()->actor();
    }
    public function testDelegationAuditFailureRollsBackScopeWithoutChangingRolesOrHistory(): void
    {
        $f = new SisFixture();
        $before = $f->sql->rows('sis_history_events');
        $roles = $f->sql->rows('sis_access_user_roles');
        $user = $f->user();
        $audit = $this->createMock(AuditTrail::class);
        $audit->expects(self::once())
            ->method('record')
            ->willThrowException(new RuntimeException('Fictional audit failure.'));
        try {
            $this->offices($f, $audit)->delegate($f->office, $user, new DateTimeImmutable('2026-08-01'), true, -1);
            self::fail('An unaudited delegation committed.');
        } catch (RuntimeException) {
            self::assertSame([], $f->sql->rows('sis_office_delegations'));
            self::assertSame($before, $f->sql->rows('sis_history_events'));
            self::assertSame($roles, $f->sql->rows('sis_access_user_roles'));
        }
    }

    public function testAccessAdministratorsCanConfigureOfficesWithoutHoldingStudentWorkPermissions(): void
    {
        $f = new SisFixture();
        $f->access->staff = false;
        $f->access->grants = [OfficeScope::ADMIN];
        self::assertTrue($this->offices($f)->choices()['can_administer']);
        $this->expectException(AccessDeniedException::class);
        $this->holds($f)->queue();
    }
}
