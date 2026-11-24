<?php

declare(strict_types=1);

namespace Domain\Registration\Waitlist;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** Internal verified boundary. Callers own authorization, section locks and the atomic business transaction. */
final readonly class WaitlistLedger
{
    public function __construct(
        private WaitlistRepository $records,
        private HistoricalEventStore $history,
        private WaitlistOutcomeNotifications $notifications,
    ) {
    }
    public static function budget(): HistoryReadBudget
    {
        return new HistoryReadBudget(
            maximumEvents: 6000,
            maximumBytes: 4_194_304,
            maximumMilliseconds: 5000,
        );
    }
    public function load(EntityId $section, HistoryReadBudget $budget): SectionWaitlist
    {
        $current = $this->records->current($section);
        $root = SectionWaitlist::root(new WaitlistId($section->value));
        if ($current === null && $this->history->latest($this->stream($section)) === null) {
            if ($this->records->active($section) !== []) {
                throw new CorruptHistoryException();
            }
            return $root;
        }
        $cursor = null;
        do {
            $page = $budget->page($this->history, $this->stream($section), $cursor);
            if ($page->throughVersion > SectionWaitlist::MAX_EVENTS) {
                throw new CorruptHistoryException();
            }
            foreach ($page->events as $stored) {
                $root->accept($stored);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if (
            $current === null ||
            $root->playhead() !== $page->throughVersion ||
            $root->playhead() !== $current["version"]
        ) {
            throw new ConcurrentModificationException(
                "The waitlist changed or its source disagrees. Refresh it.",
            );
        }
        if (
            !hash_equals(
                WaitlistPolicy::hash($root->projection()),
                WaitlistPolicy::hash($current["projection"]),
            )
        ) {
            throw new CorruptHistoryException();
        }
        $active = array_map(static function (array $row): array {
            unset($row["section_id"]);
            return $row;
        }, $this->records->active($section));
        if (
            !hash_equals(
                WaitlistPolicy::hash($root->projection()["active"]),
                WaitlistPolicy::hash($active),
            )
        ) {
            throw new CorruptHistoryException();
        }
        return $root;
    }
    public function append(
        EntityId $section,
        SectionWaitlist $root,
        #[\SensitiveParameter] array $payload,
        bool $policy,
        #[\SensitiveParameter] EventContext $context,
    ): void {
        if ($root->playhead() >= SectionWaitlist::MAX_EVENTS) {
            throw new ValidationException([
                [
                    "field" => "waitlist",
                    "message" =>
                        "This waitlist reached its bounded history allowance. No partial decision was saved.",
                ],
            ]);
        }
        $payload["intent"] = SectionWaitlist::intent($payload, $context->reason ?? "");
        $class = $policy ? WaitlistPolicyWasSet::class : WaitlistPlaceWasChanged::class;
        $event = $class
        ::occur(new WaitlistId($section->value), $payload, [
                "__aggregate_type" => "section_waitlist",
            ])
            ->withPlayhead($root->playhead() + 1);
        $expected = $root->playhead();
        $receipt = $this->history->append(
            $this->stream($section),
            new EntityId($payload["operation_id"]),
            $expected,
            $context,
            [$event],
        );
        if ($receipt["last_version"] !== $expected + 1) {
            throw new CorruptHistoryException();
        }
        $root->applyAccepted($event, $context);
        $entry = $policy ? null : $root->entries()[$payload["entry_id"]];
        $this->records->save($section, $expected, $root->projection(), $entry);
        if ($entry !== null) {
            $this->notifications->record(
                $section,
                new EntityId($payload['operation_id']),
                $expected + 1,
                $entry,
                $context->effectiveAt ?? throw new CorruptHistoryException()
            );
        }
    }
    private function stream(EntityId $section): StreamReference
    {
        return new StreamReference(new WaitlistId($section->value), "section_waitlist");
    }
}
