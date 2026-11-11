<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Codefy\Domain\EventSourcing\DomainEvent;
use DateTimeImmutable;
use Domain\AcademicRecord\ValueObject\AcademicCreditId;
use Domain\DegreeAudit\ValueObject\AcademicInputInventory;
use Domain\DegreeAudit\ValueObject\AcademicInputSet;
use Domain\DegreeAudit\ValueObject\AcademicInputStatus;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\EventPage;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class AcademicInputBoundsTest extends TestCase
{
    private function bags(): array
    {
        return array_fill_keys(
            ["credits", "academic", "registration", "enrollments", "transfer", "awards"],
            [],
        );
    }
    private function stream(): StreamReference
    {
        return new StreamReference(new AcademicCreditId(EntityId::generate()->value), "academic_credit");
    }
    private function event(): StoredEvent
    {
        return new StoredEvent(
            EntityId::generate(),
            $this->createStub(DomainEvent::class),
            new EventContext(null, null, null, origin: "framework"),
            new DateTimeImmutable("2026-08-01"),
            str_repeat("a", 64),
        );
    }
    public function testAllSourcesMergeIntoOneDeterministicReferenceAndContradictingLinksAreRejected(): void
    {
        $rows = $this->bags();
        $source = EntityId::generate()->value;
        $credit = EntityId::generate()->value;
        $section = EntityId::generate()->value;
        $term = EntityId::generate()->value;
        $rows["credits"][] = [
            "id" => $credit,
            "source_id" => $source,
            "term_id" => $term,
            "credit_type" => "institutional",
        ];
        $rows["academic"][] = ["id" => $credit, "enrollment_id" => $source, "section_id" => $section];
        $rows["registration"][] = ["id" => $source, "section_id" => $section, "term_id" => $term];
        $rows["enrollments"] = $rows["registration"];
        $result = new AcademicInputInventory($rows);
        self::assertCount(1, $result->sources);
        self::assertTrue($result->sources[0]["credit_seen"]);
        self::assertTrue($result->sources[0]["academic_seen"]);
        self::assertTrue($result->sources[0]["registration_seen"]);
        self::assertTrue($result->sources[0]["source_seen"]);
        $rows["enrollments"][0]["term_id"] = EntityId::generate()->value;
        $this->expectException(CorruptHistoryException::class);
        new AcademicInputInventory($rows);
    }
    public function testCombinedLimitAppliesEvenWhenEveryIndividualSourceQueryIsWithinItsCap(): void
    {
        $rows = $this->bags();
        for ($i = 0; $i < 101; $i++) {
            $rows["academic"][] = [
                "id" => EntityId::generate()->value,
                "enrollment_id" => EntityId::generate()->value,
                "section_id" => EntityId::generate()->value,
            ];
        }
        for ($i = 0; $i < 100; $i++) {
            $rows["transfer"][] = [
                "id" => EntityId::generate()->value,
                "credit_id" => EntityId::generate()->value,
            ];
        }
        $this->expectException(HistoryReadLimitException::class);
        new AcademicInputInventory($rows);
    }
    public function testEmptyStreamsStillSpendTheSharedPageBudget(): void
    {
        $store = $this->createMock(HistoricalEventStore::class);
        $store->expects(self::exactly(2))->method("page")->willReturn(new EventPage([], null, 0));
        $budget = new HistoryReadBudget(maximumPages: 2);
        $budget->page($store, $this->stream());
        $budget->page($store, $this->stream());
        $this->expectException(HistoryReadLimitException::class);
        $budget->page($store, $this->stream());
    }
    public function testRemainingEventAllowanceControlsFetchSizeAndExhaustionCannotReturnAnotherPage(): void
    {
        $event = $this->event();
        $store = $this->createMock(HistoricalEventStore::class);
        $limits = [];
        $store
            ->expects(self::exactly(2))
            ->method("page")
            ->willReturnCallback(function ($stream, $cursor, $limit) use (&$limits, $event): EventPage {
                $limits[] = $limit;
                return new EventPage([$event], null, 1);
            });
        $budget = new HistoryReadBudget(maximumEvents: 2);
        $budget->page($store, $this->stream());
        $budget->page($store, $this->stream());
        self::assertSame([2, 1], $limits);
        $this->expectException(HistoryReadLimitException::class);
        $budget->page($store, $this->stream());
    }
    public function testStoreCannotExceedTheRequestedReplayAllowance(): void
    {
        $store = $this->createMock(HistoricalEventStore::class);
        $store
            ->expects(self::once())
            ->method("page")
            ->willReturn(new EventPage([$this->event(), $this->event()], null, 2));
        $this->expectException(CorruptHistoryException::class);
        new HistoryReadBudget(maximumEvents: 1)->page($store, $this->stream());
    }
    public function testProtectedInputSnapshotCannotAccidentallySerializeRemovedReferences(): void
    {
        $result = new AcademicInputSet(
            AcademicInputStatus::Verified,
            [],
            [["credit_id" => "fictional-removed-record"]],
        );
        self::assertSame(
            '{"inputs":"[protected academic inputs]"}',
            json_encode($result, JSON_THROW_ON_ERROR),
        );
        self::assertSame(["inputs" => "[protected academic inputs]"], $result->__debugInfo());
    }
}
