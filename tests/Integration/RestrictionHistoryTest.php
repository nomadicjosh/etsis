<?php

declare(strict_types=1);

namespace Tests\Integration;

use AddSisRestrictionPersonIndex;
use ArrayObject;
use DateTimeImmutable;
use EventStoreFixture;
use RuntimeException;
use SisFixture;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Restriction;
use Domain\Student\Service\RestrictionService;
use Infrastructure\Persistence\Repository\SqlRestrictionRepository;
use PHPUnit\Framework\TestCase;

final class RestrictionHistoryTest extends TestCase
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
            new \Infrastructure\Persistence\Repository\SqlOfficeRepository($f->sql),
        );
    }

    private function hold(EntityId $person): Restriction
    {
        return new Restriction(
            EntityId::generate(),
            $person,
            "FICTIONAL.HOLD",
            99,
            new DateRange(
                new DateTimeImmutable("2026-08-01"),
                new DateTimeImmutable("2026-08-20"),
            ),
            true,
        );
    }

    /** @return list<array<string,mixed>> */
    private function events(SisFixture $f): array
    {
        return $f->sql->fetch(
            "SELECT e.* FROM sis_history_events e JOIN sis_history_streams s ON s.id = e.stream_id" .
                " WHERE s.stream_type = ? ORDER BY e.stream_id, e.position",
            ["person_restrictions"],
        );
    }

    public function testOriginalFactsSurviveLaterEndingsAndAuthorizedRetriesNeverRewindThem(): void
    {
        $f = new SisFixture();
        $hold = $this->hold($f->student()->personId);
        $service = $this->service($f);
        $service->create($hold, "Fictional protected placement reason.");
        $initial = $service->view($hold->id);
        $first = EntityId::generate();
        $service->end(
            $hold->id,
            new DateTimeImmutable("2026-08-15"),
            $initial["revision"],
            $first,
            "Fictional first resolution.",
        );
        $later = $service->view($hold->id);
        $service->end(
            $hold->id,
            new DateTimeImmutable("2026-08-12"),
            $later["revision"],
            EntityId::generate(),
            "Fictional updated resolution.",
        );
        $before = $this->events($f);
        self::assertCount(4, $before);
        $service->end(
            $hold->id,
            new DateTimeImmutable("2026-08-15"),
            $initial["revision"],
            $first,
            "Fictional first resolution.",
        );
        $service->create($hold, "Fictional protected placement reason.");
        self::assertSame($before, $this->events($f));
        self::assertSame("2026-08-12", $service->view($hold->id)["ends_on"]);
        $history = $service->history($hold->id);
        self::assertSame(
            ["placed", "ended", "ended"],
            array_column($history["items"], "action"),
        );
        self::assertSame(
            ["2026-08-20", "2026-08-15", "2026-08-12"],
            array_map(
                static fn(array $i): string => $i["facts"]["ends_on"],
                $history["items"],
            ),
        );
        self::assertSame(
            ["2026-08-01", "2026-08-15", "2026-08-12"],
            array_column($history["items"], "effective_on"),
        );
        $json = json_encode($history, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString("reason", $json);
        self::assertStringNotContainsString("actor_id", $json);
        $stored = json_encode($before, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString("FICTIONAL.HOLD", $stored);
        self::assertStringNotContainsString(
            "Fictional protected placement reason.",
            $stored,
        );
        self::assertCount(3, $service->notes($hold->id)["items"]);
        $store = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(
                EventStoreFixture::config($f->connection->queryBuilder()),
            ),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
        self::assertSame(1, $store->verifyOperation($first));
        $aggregateId = new \Domain\Student\ValueObject\PersonRestrictionsId(
            $hold->personId->value,
        );
        $root = \Domain\Student\PersonRestrictions::root($aggregateId);
        $stream = EventStoreFixture::framework($f->connection->queryBuilder())->getAggregateHistoryFor($aggregateId);
        foreach ($stream as $event) {
            $root->acceptHistorical($event);
        }
        self::assertSame(
            "2026-08-12",
            $root->restrictions()[$hold->id->value]->facts()["ends_on"],
        );
        self::assertSame($before, $this->events($f));
        $this->expectException(ConcurrentModificationException::class);
        $service->end(
            $hold->id,
            new DateTimeImmutable("2026-08-14"),
            $initial["revision"],
            $first,
            "Changed retry.",
        );
    }

    public function testEarlierCurrentRowsAreObservedOnlyOnARealDecisionWithoutInventingPlacement(): void
    {
        $f = new SisFixture();
        $hold = $this->hold($f->student()->personId);
        new SqlRestrictionRepository($f->sql)->add($hold);
        new \Infrastructure\Persistence\Repository\SqlOfficeRepository($f->sql)->bind($hold->id, $f->office);
        $service = $this->service($f);
        $current = $service->view($hold->id);
        self::assertSame([], $this->events($f));
        self::assertSame([], $service->history($hold->id)["items"]);
        $service->end(
            $hold->id,
            new DateTimeImmutable("2026-08-15"),
            $current["revision"],
            EntityId::generate(),
            "Fictional reviewed earlier hold.",
        );
        $items = $service->history($hold->id)["items"];
        self::assertSame(["observed", "ended"], array_column($items, "action"));
        self::assertSame("2026-08-10", $items[0]["effective_on"]);
        self::assertEquals($hold->facts(), $items[0]["facts"]);
        self::assertSame("2026-08-20", $items[0]["facts"]["ends_on"]);
        self::assertCount(2, $this->events($f));
    }

    public function testDeletedOrChangedOrInjectedProjectionRowsCannotBypassRegistration(): void
    {
        foreach (["deleted", "changed", "injected"] as $tamper) {
            $f = new SisFixture();
            $student = $f->student();
            $section = $f->section();
            $hold = $this->hold($student->personId);
            $service = $this->service($f);
            $service->create($hold, "Fictional hold.");
            $events = $this->events($f);
            if ($tamper === "deleted") {
                $f->sql->execute(
                    "DELETE FROM sis_restriction_notes WHERE restriction_id = ?",
                    [$hold->id->value],
                );
                 $f->sql->execute("DELETE FROM sis_restriction_offices WHERE id = ?", [$hold->id->value]);
                $f->sql->execute("DELETE FROM sis_restrictions WHERE id = ?", [
                    $hold->id->value,
                ]);
            } elseif ($tamper === "changed") {
                $f->sql->update(
                    "sis_restrictions",
                    ["ends_on" => "2026-08-09"],
                    ["id" => $hold->id->value],
                );
            } else {
                new SqlRestrictionRepository($f->sql)->add(
                    $this->hold($student->personId),
                );
            }
            try {
                $f->service->register($student->id, $section->id, true);
                self::fail(
                    "An unverified hold projection controlled registration.",
                );
            } catch (CorruptHistoryException) {
                self::assertSame([], $f->sql->rows("sis_enrollments"));
                self::assertSame($events, $this->events($f));
            }
        }
    }

    public function testAuditFailureRollsBackJournalHeadReceiptsNotesAndProjectionTogether(): void
    {
        $f = new SisFixture();
        $hold = $this->hold($f->student()->personId);
        $audit = $this->createMock(AuditTrail::class);
        $audit
            ->expects(self::once())
            ->method("record")
            ->willThrowException(
                new RuntimeException("Fictional audit failure."),
            );
        try {
            $this->service($f, $audit)->create(
                $hold,
                "Fictional private reason.",
            );
            self::fail("An incomplete hold decision committed.");
        } catch (RuntimeException) {
            self::assertSame([], $f->sql->rows("sis_restrictions"));
            self::assertSame([], $f->sql->rows("sis_restriction_notes"));
            self::assertSame([], $this->events($f));
            self::assertSame(
                [],
                $f->sql->rows("sis_history_streams", [
                    "stream_type" => "person_restrictions",
                ]),
            );
            self::assertSame(
                [],
                $f->sql->rows("sis_history_operations", [
                    "id" => $hold->id->value,
                ]),
            );
        }
    }

    public function testEndFailureAndFinalAuthorizationChangesLeaveOriginalFactsIntact(): void
    {
        foreach (["failure", "actor", "permission"] as $case) {
            $f = new SisFixture();
            $hold = $this->hold($f->student()->personId);
            $service = $this->service($f);
            $service->create($hold, "Fictional original reason.");
            $revision = $service->view($hold->id)["revision"];
            $before = $this->events($f);
            $audit = $this->createMock(AuditTrail::class);
            $audit
                ->expects(self::once())
                ->method("record")
                ->willReturnCallback(static function () use ($case, $f): void {
                    if ($case === "actor") {
                        $f->access->actor = $f->user();
                    } elseif ($case === "permission") {
                        $f->access->staff = false;
                    } else {
                        throw new RuntimeException("Fictional end failure.");
                    }
                });
            try {
                $this->service($f, $audit)->end(
                    $hold->id,
                    new DateTimeImmutable("2026-08-15"),
                    $revision,
                    EntityId::generate(),
                    "Fictional resolution.",
                );
                self::fail("A failed or unauthorized end decision committed.");
            } catch (RuntimeException | AccessDeniedException) {
                self::assertSame($before, $this->events($f));
                self::assertSame(
                    "2026-08-20",
                    $f->sql->one("sis_restrictions", [
                        "id" => $hold->id->value,
                    ])["ends_on"],
                );
                self::assertCount(1, $f->sql->rows("sis_restriction_notes"));
            }
        }
    }

    public function testStudentOwnershipDoesNotAllowInternalHoldHistoryReads(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $hold = $this->hold($f->student()->personId);
        $service->create($hold, "Fictional reason.");
        $other = $this->hold($f->student()->personId);
        $service->create($other, "Fictional other reason.");
        $f->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $service->history($hold->id);
    }

    public function testHistoryPagesFreezeTheirHeadAndCursorsCannotCrossPeople(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $person = $f->student()->personId;
        $first = $this->hold($person);
        $service->create($first, "Fictional first reason.");
        for ($i = 0; $i < 20; ++$i) {
            $service->create($this->hold($person), "Fictional paged reason.");
        }
        $page = $service->history($first->id);
        self::assertNotNull($page["next_cursor"]);
        self::assertCount(1, $page["items"]);
        $later = $this->hold($person);
        $service->create($later, "Fictional later hold.");
        self::assertSame(
            [],
            $service->history($later->id, $page["next_cursor"])["items"],
        );
        $other = $this->hold($f->student()->personId);
        $service->create($other, "Fictional other person.");
        $this->expectException(CorruptHistoryException::class);
        $service->history($other->id, $page["next_cursor"]);
    }

    public function testNotesDoNotAppendHoldDecisionsAndAcceptedReceiptsRemainActorBound(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $hold = $this->hold($f->student()->personId);
        $service->create($hold, "Fictional hold.");
        $before = $this->events($f);
        self::assertCount(2, $before);
        $service->appendNote(
            $hold->id,
            EntityId::generate(),
            "Fictional follow-up.",
        );
        self::assertSame($before, $this->events($f));
        $f->access->actor = $f->user();
        $this->expectException(ConcurrentModificationException::class);
        $service->create($hold, "Fictional hold.");
    }

    public function testLookupIndexRollbackAndReapplyPreserveFacts(): void
    {
        $f = new SisFixture();
        $hold = $this->hold($f->student()->personId);
        $this->service($f)->create($hold, "Fictional indexed hold.");
        $facts = $f->sql->rows("sis_restrictions");
        $events = $this->events($f);
        $migration = new AddSisRestrictionPersonIndex("fixture");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $f->connection,
                    "test_migrations",
                ),
            ]),
        );
        $migration->down();
        $migration->up();
        self::assertSame($facts, $f->sql->rows("sis_restrictions"));
        self::assertSame($events, $this->events($f));
        $indexes = $f->sql->fetch("PRAGMA index_list('sis_restrictions')");
        self::assertContains(
            "sr_person_history",
            array_column($indexes, "name"),
        );
    }

    public function testOversizedHistoryStopsAfterOnePageBeforeReadingOrChangingOtherState(): void
    {
        $f = new SisFixture();
        $person = $f->student()->personId;
        $history = $this->createMock(
            \Domain\Shared\EventSourcing\HistoricalEventStore::class,
        );
        $history
            ->expects(self::once())
            ->method("page")
            ->willReturn(
                new \Domain\Shared\EventSourcing\EventPage(
                    [],
                    "another-page",
                    \Domain\Student\PersonRestrictions::MAX_EVENTS + 1,
                ),
            );
        $history->expects(self::never())->method("append");
        $ledger = new \Domain\Student\Service\RestrictionLedger(
            new SqlRestrictionRepository($f->sql),
            $history,
            $f->clock,
        );
        $this->expectException(
            \Domain\Shared\EventSourcing\HistoryReadLimitException::class,
        );
        $ledger->verify($person);
    }

    public function testPrivateReadsRecheckAuthorityBeforeReturningNotesOrHistory(): void
    {
        foreach (['notes', 'history'] as $method) {
            $f = new SisFixture();
            $hold = $this->hold($f->student()->personId);
            $this->service($f)->create($hold, 'Fictional private reason.');
            $before = $this->events($f);
            $audit = $this->createMock(AuditTrail::class);
            $audit->expects(self::once())->method('record')->willReturnCallback(static function () use ($f): void {
                $f->access->staff = false;
            });
            try {
                $this->service($f, $audit)->$method($hold->id);
                self::fail('A private read returned after its permission was removed.');
            } catch (AccessDeniedException) {
                self::assertSame($before, $this->events($f));
            }
        }
    }
}
