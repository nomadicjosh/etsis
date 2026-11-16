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
use Domain\Student\Service\RestrictionService;
use EventStoreFixture;
use Infrastructure\Persistence\Repository\SqlOfficeRepository;
use Infrastructure\Persistence\Repository\SqlRestrictionRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SisFixture;

final class HoldHandoffTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function service(
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

    private function office(SisFixture $f, string $code = "LIB"): EntityId
    {
        $id = EntityId::generate();
        new SqlOfficeRepository($f->sql)->saveOffice(
            [
                "id" => $id->value,
                "code" => $code,
                "name" => "Fictional " . $code,
                "active" => 1,
                "revision" => 0,
            ],
            true,
        );
        return $id;
    }

    private function hold(SisFixture $f): Restriction
    {
        $hold = new Restriction(
            EntityId::generate(),
            $f->student()->personId,
            "FICTIONAL.HOLD",
            99,
            new DateRange(
                new DateTimeImmutable("2026-08-01"),
                new DateTimeImmutable("2026-08-20"),
            ),
            true,
        );
        $this->service($f)->create(
            $hold,
            "Fictional placement reason.",
            $f->office,
        );
        return $hold;
    }

    private function delegate(
        SisFixture $f,
        EntityId $office,
        EntityId $user,
    ): void {
        new SqlOfficeRepository($f->sql)->saveDelegation(
            [
                "id" => EntityId::generate()->value,
                "office_id" => $office->value,
                "user_id" => $user->value,
                "starts_on" => "2026-08-01",
                "ends_on" => "2026-08-20",
                "active" => 1,
                "revision" => 0,
            ],
            true,
        );
    }

    public function testReviewWritesNothingAndHandoffPreservesTheHoldAndEarlierEvidence(): void
    {
        $f = new SisFixture();
        $hold = $this->hold($f);
        $target = $this->office($f);
        $service = $this->service($f);
        $original = $f->sql->rows("sis_restrictions");
        $events = $f->sql->rows("sis_history_events");
        $audit = $f->sql->rows("sis_audit");
        $notes = $f->sql->rows("sis_restriction_notes");
        $review = $service->reviewHandoff(
            $hold->id,
            $target,
            "Fictional handoff reason.",
        );
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertSame($audit, $f->sql->rows("sis_audit"));
        self::assertSame($notes, $f->sql->rows("sis_restriction_notes"));
        self::assertStringNotContainsString(
            "Fictional handoff reason.",
            $review["review_token"],
        );
        self::assertArrayNotHasKey("from_authority", $review);
        $result = $service->confirmHandoff(
            $hold->id,
            $review["review_token"],
            true,
        );
        self::assertSame($target->value, $result["to_office_id"]);
        self::assertSame(
            $target->value,
            $service->view($hold->id)["office_id"],
        );
        self::assertSame($original, $f->sql->rows("sis_restrictions"));
        foreach ($events as $event) {
            self::assertSame(
                $event,
                $f->sql->one("sis_history_events", ["id" => $event["id"]]),
            );
        }
        self::assertCount(
            count($events) + 1,
            $f->sql->rows("sis_history_events"),
        );
        $history = $service->history($hold->id)["items"];
        self::assertSame(
            ["placed", "office_changed"],
            array_column($history, "action"),
        );
        self::assertSame("Fictional Registrar", $history[1]["from_office"]);
        self::assertSame("Fictional LIB", $history[1]["to_office"]);
        self::assertNull($history[1]["facts"]);
        self::assertStringNotContainsString(
            "Fictional handoff reason.",
            json_encode($history, JSON_THROW_ON_ERROR),
        );
        self::assertSame(
            $hold->id->value,
            $f->registration->blockingRestriction(
                $hold->personId,
                $f->clock->now(),
                true,
            )->id->value,
        );
        self::assertSame(
            $hold->id->value,
            $f->registration->blockingRestriction(
                $hold->personId,
                $f->clock->now(),
                false,
            )->id->value,
        );
        self::assertCount(2, $service->notes($hold->id)["items"]);
    }

    public function testAcceptedRetriesAfterAnotherHandoffAndExpiryReturnTheirOriginalResultWithoutRewinding(): void
    {
        $f = new SisFixture();
        $hold = $this->hold($f);
        $first = $this->office($f);
        $later = $this->office($f, "FIN");
        $service = $this->service($f);
        $a = $service->reviewHandoff(
            $hold->id,
            $first,
            "Fictional first handoff.",
        );
        $original = $service->confirmHandoff(
            $hold->id,
            $a["review_token"],
            true,
        );
        $b = $service->reviewHandoff(
            $hold->id,
            $later,
            "Fictional later handoff.",
        );
        $service->confirmHandoff($hold->id, $b["review_token"], true);
        $before = $f->sql->rows("sis_history_events");
        $notes = $f->sql->rows("sis_restriction_notes");
        $f->clock->time = $f->clock->time->modify("+1 hour");
        self::assertSame(
            $original,
            $service->confirmHandoff($hold->id, $a["review_token"], true),
        );
        self::assertSame($later->value, $service->view($hold->id)["office_id"]);
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        self::assertSame($notes, $f->sql->rows("sis_restriction_notes"));
        $root = \Domain\Student\PersonRestrictions::root(
            new \Domain\Student\ValueObject\PersonRestrictionsId(
                $hold->personId->value,
            ),
        );
        $stream = EventStoreFixture::framework(
            $f->connection->queryBuilder(),
        )->getAggregateHistoryFor($root->aggregateId());
        foreach ($stream as $event) {
            $root->acceptHistorical($event);
        }
        self::assertSame($later->value, $root->offices()[$hold->id->value]);
        self::assertSame($before, $f->sql->rows("sis_history_events"));
    }

    public function testAnUnacceptedExpiredReviewCannotMoveARecord(): void
    {
        $f = new SisFixture();
        $hold = $this->hold($f);
        $review = $this->service($f)->reviewHandoff(
            $hold->id,
            $this->office($f),
            "Fictional reason.",
        );
        $f->clock->time = $f->clock->time->modify("+21 minutes");
        try {
            $this->service($f)->confirmHandoff(
                $hold->id,
                $review["review_token"],
                true,
            );
            self::fail("An expired review changed ownership.");
        } catch (ConcurrentModificationException) {
            self::assertSame(
                $f->office->value,
                $this->service($f)->view($hold->id)["office_id"],
            );
        }
    }

    public function testAcknowledgementIsRequiredAndReviewsAreBoundToTheirActorAndRecord(): void
    {
        $f = new SisFixture();
        $hold = $this->hold($f);
        $other = $this->hold($f);
        $service = $this->service($f);
        $review = $service->reviewHandoff(
            $hold->id,
            $this->office($f),
            "Fictional reason.",
        );
        foreach ([$hold->id, $other->id] as $id) {
            try {
                $service->confirmHandoff(
                    $id,
                    $review["review_token"],
                    $id->value !== $hold->id->value,
                );
                self::fail("Acknowledgement or record binding was bypassed.");
            } catch (ValidationException) {
                self::assertSame(
                    $f->office->value,
                    $service->view($hold->id)["office_id"],
                );
            }
        }
        $f->access->actor = $f->user();
        $this->expectException(AccessDeniedException::class);
        $service->confirmHandoff($hold->id, $review["review_token"], true);
    }

    public function testBothTheSeparatePermissionAndSourceAndReceivingDelegationsAreRequired(): void
    {
        $f = new SisFixture();
        $hold = $this->hold($f);
        $target = $this->office($f);
        $user = $f->user();
        $this->delegate($f, $f->office, $user);
        $f->access->actor = $user;
        $f->access->staff = false;
        $f->access->grants = [OfficeScope::HOLD_MANAGE];
        try {
            $this->service($f)->reviewHandoff(
                $hold->id,
                $target,
                "Fictional reason.",
            );
            self::fail("An ordinary hold delegate could hand off records.");
        } catch (AccessDeniedException) {
            $f->access->grants[] = OfficeScope::HANDOFF;
        }
        try {
            $this->service($f)->reviewHandoff(
                $hold->id,
                $target,
                "Fictional reason.",
            );
            self::fail("The receiving office scope was not checked.");
        } catch (AccessDeniedException) {
            $this->delegate($f, $target, $user);
        }
        $review = $this->service($f)->reviewHandoff(
            $hold->id,
            $target,
            "Fictional authorized handoff.",
        );
        $this->service($f)->confirmHandoff(
            $hold->id,
            $review["review_token"],
            true,
        );
        self::assertSame(
            $target->value,
            $this->service($f)->view($hold->id)["office_id"],
        );
        $offices = new SqlOfficeRepository($f->sql);
        $delegation = $offices->delegation($target, $user);
        $offices->saveDelegation(array_replace($delegation, [
            'starts_on' => '2026-08-02', 'ends_on' => '2026-08-19', 'revision' => 1,
        ]), false);
        $before = $f->sql->rows('sis_history_events');
        $f->restrictionLedger()->verify($hold->personId);
        $stream = EventStoreFixture::framework($f->connection->queryBuilder())
            ->getAggregateHistoryFor(new \Domain\Student\ValueObject\PersonRestrictionsId($hold->personId->value));
        $proof = null;
        foreach ($stream as $event) {
            if ($event instanceof \Domain\Student\Event\RestrictionOfficeWasChanged) {
                $proof = $event->payload()['to_authority'];
            }
        }
        self::assertNotNull($proof);
        self::assertSame('2026-08-01', $proof['starts_on']);
        self::assertSame('2026-08-20', $proof['ends_on']);
        self::assertSame(0, $proof['delegation_revision']);
        self::assertSame($before, $f->sql->rows('sis_history_events'));
        $f->access->grants = [OfficeScope::HOLD_MANAGE];
        $this->expectException(AccessDeniedException::class);
        $this->service($f)->confirmHandoff(
            $hold->id,
            $review["review_token"],
            true,
        );
    }

    public function testChangedNotesInvalidateTheReviewEvenWhenTheirIdsWereGeneratedEarlier(): void
    {
        $f = new SisFixture();
        $hold = $this->hold($f);
        $service = $this->service($f);
        $review = $service->reviewHandoff(
            $hold->id,
            $this->office($f),
            "Fictional reason.",
        );
        $service->appendNote(
            $hold->id,
            new EntityId("00000000000000000000000001"),
            "Fictional later note with an older ID.",
        );
        try {
            $service->confirmHandoff($hold->id, $review["review_token"], true);
            self::fail(
                "A new private note did not require a fresh access review.",
            );
        } catch (ConcurrentModificationException) {
            self::assertSame(
                $f->office->value,
                $service->view($hold->id)["office_id"],
            );
            self::assertCount(2, $service->notes($hold->id)["items"]);
        }
    }

    public function testOfficeEditsAndRetirementRequireAFreshReview(): void
    {
        foreach (["name", "retired"] as $change) {
            $f = new SisFixture();
            $hold = $this->hold($f);
            $target = $this->office($f);
            $review = $this->service($f)->reviewHandoff(
                $hold->id,
                $target,
                "Fictional reason.",
            );
            $f->sql->update(
                "sis_offices",
                $change === "name"
                    ? ["name" => "Changed label"]
                    : ["active" => 0],
                ["id" => $target->value],
            );
            try {
                $this->service($f)->confirmHandoff(
                    $hold->id,
                    $review["review_token"],
                    true,
                );
                self::fail("Changed office context was accepted.");
            } catch (ConcurrentModificationException | ValidationException) {
                self::assertSame(
                    $f->office->value,
                    $this->service($f)->view($hold->id)["office_id"],
                );
            }
        }
    }

    public function testAuditFailureAndAuthorityLossRollBackTheJournalRoutingNoteAndReceipt(): void
    {
        foreach (["audit", "permission", "expiry"] as $failure) {
            $f = new SisFixture();
            $hold = $this->hold($f);
            $review = $this->service($f)->reviewHandoff(
                $hold->id,
                $this->office($f),
                "Fictional reason.",
            );
            $before = $f->sql->rows("sis_history_events");
            $audit = $this->createMock(AuditTrail::class);
            $audit
                ->expects(self::once())
                ->method("record")
                ->willReturnCallback(static function () use (
                    $f,
                    $failure,
                ): void {
                    if ($failure === "audit") {
                        throw new RuntimeException("Fictional audit failure.");
                    }
                    if ($failure === "permission") {
                        $f->access->staff = false;
                        $f->access->grants = [
                            OfficeScope::HOLD_MANAGE,
                            OfficeScope::ADMIN,
                        ];
                    } else {
                        $f->clock->time = $f->clock->time->modify(
                            "+21 minutes",
                        );
                    }
                });
            try {
                $this->service($f, $audit)->confirmHandoff(
                    $hold->id,
                    $review["review_token"],
                    true,
                );
                self::fail("A failed handoff partially committed.");
            } catch (RuntimeException | AccessDeniedException) {
                self::assertSame($before, $f->sql->rows("sis_history_events"));
                self::assertSame(
                    $f->office->value,
                    new SqlOfficeRepository($f->sql)->owner($hold->id)["id"],
                );
                self::assertCount(1, $f->sql->rows("sis_restriction_notes"));
                self::assertSame(
                    [],
                    $f->sql->rows("sis_history_operations", [
                        "id" => $review["operation_id"],
                    ]),
                );
            }
        }
    }

    public function testTamperedTicketsAndSameOfficeChoicesDoNotModifyRouting(): void
    {
        $f = new SisFixture();
        $hold = $this->hold($f);
        try {
            $this->service($f)->reviewHandoff(
                $hold->id,
                $f->office,
                "Fictional reason.",
            );
            self::fail("A same-office handoff was offered.");
        } catch (ValidationException $error) {
            self::assertSame("to_office_id", $error->errors[0]["field"]);
        }
        $review = $this->service($f)->reviewHandoff(
            $hold->id,
            $this->office($f),
            "Fictional reason.",
        );
        $this->expectException(ValidationException::class);
        $this->service($f)->confirmHandoff(
            $hold->id,
            substr($review["review_token"], 0, -1) . "z",
            true,
        );
    }

    public function testUnicodeReasonsFitTheExistingBoundedRequestBody(): void
    {
        $f = new SisFixture();
        $hold = $this->hold($f);
        $review = $this->service($f)->reviewHandoff(
            $hold->id,
            $this->office($f),
            str_repeat("😀", 10000),
        );
        self::assertLessThanOrEqual(100000, strlen($review["review_token"]));
        $this->service($f)->confirmHandoff(
            $hold->id,
            $review["review_token"],
            true,
        );
        self::assertCount(2, $this->service($f)->notes($hold->id)["items"]);
    }
    public function testOnlyTheReceivingOfficeRetainsCaseAccessAfterTheApprovedHandoff(): void
    {
        $f = new SisFixture();
        $hold = $this->hold($f);
        $target = $this->office($f);
        $sourceUser = $f->user();
        $targetUser = $f->user();
        $this->delegate($f, $f->office, $sourceUser);
        $this->delegate($f, $target, $targetUser);
        $review = $this->service($f)->reviewHandoff(
            $hold->id,
            $target,
            "Fictional explicit case access approval.",
        );
        $this->service($f)->confirmHandoff(
            $hold->id,
            $review["review_token"],
            true,
        );
        $f->access->staff = false;
        $f->access->grants = [OfficeScope::HOLD_MANAGE];
        $f->access->actor = $sourceUser;
        self::assertSame([], $this->service($f)->queue()["items"]);
        try {
            $this->service($f)->notes($hold->id);
            self::fail(
                "Source-only delegation still exposed the handed-off case.",
            );
        } catch (AccessDeniedException) {
            $f->access->actor = $targetUser;
        }
        self::assertSame(
            [$hold->id->value],
            array_column($this->service($f)->queue()["items"], "id"),
        );
        self::assertCount(2, $this->service($f)->notes($hold->id)["items"]);
        self::assertSame(
            ["placed", "office_changed"],
            array_column(
                $this->service($f)->history($hold->id)["items"],
                "action",
            ),
        );
    }

    public function testDelegationExpiryAtMidnightRollsBackBeforeTheReviewItselfExpires(): void
    {
        foreach (["source", "target"] as $expiring) {
            $f = new SisFixture();
            $hold = $this->hold($f);
            $target = $this->office($f);
            $user = $f->user();
            $this->delegate($f, $f->office, $user);
            $this->delegate($f, $target, $user);
            $f->sql->update(
                "sis_office_delegations",
                ["ends_on" => "2026-08-10"],
                [
                    "office_id" =>
                        $expiring === "source"
                            ? $f->office->value
                            : $target->value,
                    "user_id" => $user->value,
                ],
            );
            $f->clock->time = new DateTimeImmutable(
                "2026-08-10T23:59:59-07:00",
            );
            $f->access->actor = $user;
            $f->access->staff = false;
            $f->access->grants = [
                OfficeScope::HOLD_MANAGE,
                OfficeScope::HANDOFF,
            ];
            $review = $this->service($f)->reviewHandoff(
                $hold->id,
                $target,
                "Fictional midnight review.",
            );
            $events = $f->sql->rows("sis_history_events");
            $audit = $this->createMock(AuditTrail::class);
            $audit
                ->expects(self::once())
                ->method("record")
                ->willReturnCallback(static function () use ($f): void {
                    $f->clock->time = $f->clock->time->modify("+3 seconds");
                });
            try {
                $this->service($f, $audit)->confirmHandoff(
                    $hold->id,
                    $review["review_token"],
                    true,
                );
                self::fail("A dated delegation expired before completion.");
            } catch (AccessDeniedException) {
                self::assertSame($events, $f->sql->rows("sis_history_events"));
                self::assertSame(
                    $f->office->value,
                    new SqlOfficeRepository($f->sql)->owner($hold->id)["id"],
                );
            }
        }
    }

    public function testProtectedHandoffPermissionDoesNotImplyOrdinaryHoldAccess(): void
    {
        $f = new SisFixture();
        $permission = $f->sql->one("sis_access_permissions", [
            "name" => OfficeScope::HANDOFF,
        ]);
        self::assertSame(1, (int) $permission["is_system"]);
        $user = $f->user();
        $f->sql->insert("sis_access_user_permissions", [
            "id" => EntityId::generate()->value,
            "user_id" => $user->value,
            "permission_id" => $permission["id"],
        ]);
        $permissions = new \Infrastructure\Persistence\Repository\SqlAccessRepository(
            $f->sql,
        );
        self::assertTrue(
            $permissions->allows($user->value, OfficeScope::HANDOFF),
        );
        self::assertFalse(
            $permissions->allows($user->value, OfficeScope::HOLD_MANAGE),
        );
        $before = $f->sql->rows("sis_access_permissions");
        $migration = new \AddSisHoldHandoffPermission("handoff-repeat");
        $migration->setObjectMap(
            new \ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $f->connection,
                    "fixture",
                ),
            ]),
        );
        $migration->up();
        self::assertSame($before, $f->sql->rows("sis_access_permissions"));
    }
}
