<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Office\Service\OfficeScope;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Restriction;
use Infrastructure\Persistence\Repository\SqlOfficeRepository;
use Infrastructure\Persistence\Repository\SqlRestrictionRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SisFixture;

final class HoldFollowUpTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function hold(SisFixture $f): Restriction
    {
        $hold = new Restriction(
            EntityId::generate(),
            $f->student()->personId,
            "FICTIONAL.HOLD",
            99,
            new DateRange(new DateTimeImmutable("2026-08-01")),
            true,
        );
        $f->restrictions()->create($hold, "Fictional hold reason.", $f->office);
        return $hold;
    }

    private function colleague(
        SisFixture $f,
        ?EntityId $office = null,
    ): EntityId {
        $user = $f->user();
        $f->sql->update(
            "users",
            ["first_name" => "Jamie", "last_name" => "Example"],
            ["user_id" => $user->value],
        );
        $permission = $f->sql->one("sis_access_permissions", [
            "name" => OfficeScope::HOLD_MANAGE,
        ]);
        $f->sql->insert("sis_access_user_permissions", [
            "id" => EntityId::generate()->value,
            "user_id" => $user->value,
            "permission_id" => $permission["id"],
        ]);
        new SqlOfficeRepository($f->sql)->saveDelegation(
            [
                "id" => EntityId::generate()->value,
                "office_id" => ($office ?? $f->office)->value,
                "user_id" => $user->value,
                "starts_on" => "2026-08-01",
                "ends_on" => "2026-08-10",
                "active" => 1,
                "revision" => 0,
            ],
            true,
        );
        return $user;
    }

    public function testFollowUpIsPrivateAuditedCrudWithoutChangingHoldsNotesOrTheJournal(): void
    {
        $f = new SisFixture();
        $hold = $this->hold($f);
        $user = $this->colleague($f);
        $service = $f->restrictions();
        $events = $f->sql->rows("sis_history_events");
        $notes = $f->sql->rows("sis_restriction_notes");
        $facts = $f->sql->rows("sis_restrictions");
        $grants = $f->sql->rows("sis_access_user_permissions");
        self::assertSame(
            0,
            (int) $service->followUp($hold->id)["work_revision"],
        );
        $service->saveFollowUp(
            $hold->id,
            $f->office,
            0,
            $user,
            new DateTimeImmutable("2026-08-10"),
        );
        $service->saveFollowUp(
            $hold->id,
            $f->office,
            0,
            $user,
            new DateTimeImmutable("2026-08-10"),
        );
        $work = $service->followUp($hold->id);
        self::assertSame("Jamie Example", $work["assignee_name"]);
        self::assertTrue($work["assignee_eligible"]);
        self::assertSame(1, (int) $work["work_revision"]);
        self::assertCount(
            1,
            $f->sql->rows("sis_audit", [
                "action" => "restriction.follow_up_saved",
            ]),
        );
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertSame($notes, $f->sql->rows("sis_restriction_notes"));
        self::assertSame($facts, $f->sql->rows("sis_restrictions"));
        self::assertSame($grants, $f->sql->rows("sis_access_user_permissions"));
        self::assertSame(
            "Jamie Example",
            $service->view($hold->id)["follow_up"]["assignee_name"],
        );
        $f->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $service->followUp($hold->id);
    }

    public function testAssigneesNeedDatedOfficeDelegationAndTheirOwnEnabledHoldPermission(): void
    {
        $cases = ['no_grant', 'no_delegation', 'future', 'expired', 'revoked', 'disabled', 'retired'];
        foreach ($cases as $case) {
            $f = new SisFixture();
            $hold = $this->hold($f);
            $user = $this->colleague($f);
            $delegation = new SqlOfficeRepository($f->sql)->delegation(
                $f->office,
                $user,
            );
            match ($case) {
                "no_grant" => $f->sql->execute(
                    "DELETE FROM sis_access_user_permissions WHERE user_id = ?",
                    [$user->value],
                ),
                "no_delegation" => $f->sql->execute(
                    "DELETE FROM sis_office_delegations WHERE id = ?",
                    [$delegation["id"]],
                ),
                "future" => $f->sql->update(
                    "sis_office_delegations",
                    ["starts_on" => "2026-08-11", "ends_on" => null],
                    ["id" => $delegation["id"]],
                ),
                "expired" => $f->sql->update(
                    "sis_office_delegations",
                    ["ends_on" => "2026-08-09"],
                    ["id" => $delegation["id"]],
                ),
                "revoked" => $f->sql->update(
                    "sis_office_delegations",
                    ["active" => 0],
                    ["id" => $delegation["id"]],
                ),
                "disabled" => $f->sql->update(
                    "users",
                    ["sign_in_enabled" => 0],
                    ["user_id" => $user->value],
                ),
                "retired" => $f->sql->update(
                    "sis_offices",
                    ["active" => 0],
                    ["id" => $f->office->value],
                ),
            };
            try {
                $f->restrictions()->saveFollowUp(
                    $hold->id,
                    $f->office,
                    0,
                    $user,
                    null,
                );
                self::fail("An ineligible colleague was assigned: " . $case);
            } catch (ValidationException) {
                self::assertNull(
                    $f->restrictions()->followUp($hold->id)["assigned_user_id"],
                );
            }
            self::assertSame(
                [],
                $f->restrictions()->followUpPeople($hold->id)["items"],
            );
        }
    }

    public function testStaleSavesAndOldRetriesCannotOverwriteLaterWorkAndExplicitClearsAreSafe(): void
    {
        $f = new SisFixture();
        $hold = $this->hold($f);
        $user = $this->colleague($f);
        $service = $f->restrictions();
        $service->saveFollowUp(
            $hold->id,
            $f->office,
            0,
            $user,
            new DateTimeImmutable("2026-08-12"),
        );
        $service->saveFollowUp($hold->id, $f->office, 1, null, null);
        try {
            $service->saveFollowUp(
                $hold->id,
                $f->office,
                0,
                $user,
                new DateTimeImmutable("2026-08-12"),
            );
            self::fail("An earlier retry restored an old assignment.");
        } catch (ConcurrentModificationException) {
            self::assertNull($service->followUp($hold->id)["assigned_user_id"]);
            self::assertNull($service->followUp($hold->id)["follow_up_on"]);
        }
        $service->saveFollowUp($hold->id, $f->office, 1, null, null);
        self::assertCount(
            2,
            $f->sql->rows("sis_audit", [
                "action" => "restriction.follow_up_saved",
            ]),
        );
        $service->saveFollowUp($hold->id, $f->office, 2, null, null);
        self::assertSame(
            2,
            (int) $service->followUp($hold->id)["work_revision"],
        );
    }

    public function testHandoffsRequireFreshWorkReviewAndClearIndividualWorkWithoutChangingOriginalFacts(): void
    {
        $f = new SisFixture();
        $hold = $this->hold($f);
        $user = $this->colleague($f);
        $target = EntityId::generate();
        new SqlOfficeRepository($f->sql)->saveOffice(
            [
                "id" => $target->value,
                "code" => "LIB",
                "name" => "Fictional Library",
                "active" => 1,
                "revision" => 0,
            ],
            true,
        );
        $service = $f->restrictions();
        $review = $service->reviewHandoff(
            $hold->id,
            $target,
            "Fictional handoff.",
        );
        $service->saveFollowUp(
            $hold->id,
            $f->office,
            0,
            $user,
            new DateTimeImmutable("2026-08-10"),
        );
        try {
            $service->confirmHandoff($hold->id, $review["review_token"], true);
            self::fail(
                "Changed office work was silently cleared from an older review.",
            );
        } catch (ConcurrentModificationException) {
            self::assertSame(
                $user->value,
                $service->followUp($hold->id)["assigned_user_id"],
            );
        }
        $review = $service->reviewHandoff(
            $hold->id,
            $target,
            "Fictional reviewed handoff.",
        );
        self::assertSame(
            "Jamie Example",
            $review["follow_up"]["assignee_name"],
        );
        $facts = $f->sql->rows("sis_restrictions");
        $service->confirmHandoff($hold->id, $review["review_token"], true);
        self::assertSame($facts, $f->sql->rows("sis_restrictions"));
        self::assertNull($service->followUp($hold->id)["assigned_user_id"]);
        self::assertNull($service->followUp($hold->id)["follow_up_on"]);
        $back = $service->reviewHandoff(
            $hold->id,
            $f->office,
            "Fictional return handoff.",
        );
        $service->confirmHandoff($hold->id, $back["review_token"], true);
        $this->expectException(ConcurrentModificationException::class);
        $service->saveFollowUp(
            $hold->id,
            $f->office,
            0,
            $user,
            new DateTimeImmutable("2026-08-10"),
        );
    }

    public function testAuditFailurePermissionLossAndDelegationExpiryRollBackWorkAtomically(): void
    {
        foreach (["audit", "actor", "delegate"] as $case) {
            $f = new SisFixture();
            $hold = $this->hold($f);
            $user = $this->colleague($f);
            $audit = $this->createMock(AuditTrail::class);
            $audit
                ->expects(self::once())
                ->method("record")
                ->willReturnCallback(static function () use ($f, $case): void {
                    if ($case === "audit") {
                        throw new RuntimeException("Fictional audit failure.");
                    }
                    if ($case === "actor") {
                        $f->access->staff = false;
                    } else {
                        $f->clock->time = $f->clock->time->modify("+1 day");
                    }
                });
            $events = $f->sql->rows("sis_history_events");
            try {
                $f->restrictions($audit)->saveFollowUp(
                    $hold->id,
                    $f->office,
                    0,
                    $user,
                    new DateTimeImmutable("2026-08-12"),
                );
                self::fail("Interrupted follow-up partially committed.");
            } catch (RuntimeException | AccessDeniedException) {
                $work = new SqlOfficeRepository($f->sql)->followUp($hold->id);
                self::assertNull($work["assigned_user_id"]);
                self::assertSame(0, (int) $work["work_revision"]);
                self::assertSame($events, $f->sql->rows("sis_history_events"));
            }
        }
    }

    public function testAssignmentDoesNotOverrideRevocationOrCrossOfficeScope(): void
    {
        $f = new SisFixture();
        $hold = $this->hold($f);
        $user = $this->colleague($f);
        $service = $f->restrictions();
        $service->saveFollowUp($hold->id, $f->office, 0, $user, null);
        $f->sql->update(
            "sis_office_delegations",
            ["active" => 0],
            ["user_id" => $user->value],
        );
        self::assertFalse($service->followUp($hold->id)["assignee_eligible"]);
        $f->access->actor = $user;
        $f->access->staff = false;
        $f->access->grants = [OfficeScope::HOLD_MANAGE];
        $this->expectException(AccessDeniedException::class);
        $service->followUp($hold->id);
    }

    public function testColleagueSearchIsBoundedMinimalAndEscapesLiteralSearchCharacters(): void
    {
        $f = new SisFixture();
        $hold = $this->hold($f);
        for ($i = 0; $i < 27; ++$i) {
            $this->colleague($f);
        }
        $service = $f->restrictions();
        $page = $service->followUpPeople($hold->id);
        self::assertCount(25, $page["items"]);
        self::assertNotNull($page["next_after"]);
        self::assertSame(["id", "name"], array_keys($page["items"][0]));
        $next = $service->followUpPeople(
            $hold->id,
            "",
            new EntityId($page["next_after"]),
        );
        self::assertCount(2, $next["items"]);
        self::assertSame(
            [],
            array_intersect(
                array_column($page["items"], "id"),
                array_column($next["items"], "id"),
            ),
        );
        self::assertCount(25, $service->followUpPeople($hold->id, 'Jamie Example')['items']);
        self::assertSame([], $service->followUpPeople($hold->id, "%")["items"]);
        self::assertSame([], $service->followUpPeople($hold->id, "_")["items"]);
    }

    public function testAssignmentAndDueFiltersRunBeforeThePageLimitAndPreserveOfficePrivacy(): void
    {
        $f = new SisFixture();
        $person = $f->student()->personId;
        $user = $this->colleague($f);
        $office = new SqlOfficeRepository($f->sql);
        $records = new SqlRestrictionRepository($f->sql);
        $first = null;
        for ($i = 0; $i < 55; ++$i) {
            $hold = new Restriction(
                $i === 0
                    ? new EntityId(str_repeat("0", 25) . "1")
                    : EntityId::generate(),
                $person,
                "FICTIONAL",
                99,
                new DateRange(new DateTimeImmutable("2026-08-01")),
                true,
            );
            $records->add($hold);
            $office->bind($hold->id, $f->office);
            if ($first === null) {
                $first = $hold->id;
                $office->saveFollowUp(
                    $hold->id,
                    $f->office,
                    $user,
                    "2026-08-10",
                    0,
                );
            }
        }
        $f->access->actor = $user;
        $f->access->staff = false;
        $f->access->grants = [OfficeScope::HOLD_MANAGE];
        $service = $f->restrictions();
        $mine = $service->queue(assignment: "mine", due: true);
        self::assertCount(1, $mine["items"]);
        self::assertSame($first->value, $mine["items"][0]["id"]);
        self::assertNull($mine["next_after"]);
        self::assertSame("Jamie Example", $mine["items"][0]["assignee_name"]);
        $none = $service->queue(assignment: "unassigned", due: true);
        self::assertSame([], $none["items"]);
        self::assertCount(
            50,
            $service->queue(assignment: "unassigned")["items"],
        );
        $f->clock->time = $f->clock->time->modify("-1 day");
        self::assertSame(
            [],
            $service->queue(assignment: "mine", due: true)["items"],
        );
        $f->sql->update(
            "sis_office_delegations",
            ["active" => 0],
            ["user_id" => $user->value],
        );
        self::assertSame([], $service->queue()["items"]);
    }
}
