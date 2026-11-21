<?php

declare(strict_types=1);

namespace Domain\Shared\EventSourcing;

use InvalidArgumentException;

/** One request's shared replay limits. No caching or authoritative state. */
final class HistoryReadBudget
{
    private int $pages = 0;
    private int $events = 0;
    private int $bytes = 0;
    private readonly int $started;

    public function __construct(
        private readonly int $maximumPages = 400,
        private readonly int $maximumEvents = 2000,
        private readonly ?int $maximumBytes = null,
        private readonly ?int $maximumMilliseconds = null,
    ) {
        $this->started = hrtime(true);
        if (
            ($maximumBytes !== null && $maximumBytes < 1) ||
            ($maximumMilliseconds !== null && $maximumMilliseconds < 1)
        ) {
            throw new InvalidArgumentException("Use positive optional history-read limits.");
        }
        if ($maximumPages < 1 || $maximumPages > 1000 || $maximumEvents < 1 || $maximumEvents > 10000) {
            throw new InvalidArgumentException("Use bounded history-read limits.");
        }
    }
    public function page(
        HistoricalEventStore $store,
        StreamReference $stream,
        ?string $cursor = null,
    ): EventPage {
        $this->checkTime();
        if ($this->maximumBytes !== null && $this->bytes > $this->maximumBytes) {
            throw new HistoryReadLimitException("This verification exceeds its payload limit.");
        }
        if ($this->pages >= $this->maximumPages || $this->events >= $this->maximumEvents) {
            throw new HistoryReadLimitException("This verification exceeds its replay limit.");
        }
        $limit = min(20, $this->maximumEvents - $this->events);
        $this->pages++;
        $page = $store->page($stream, $cursor, $limit);
        if (count($page->events) > $limit) {
            throw new CorruptHistoryException();
        }
        $this->events += count($page->events);
        if ($this->maximumBytes !== null) {
            foreach ($page->events as $stored) {
                $this->bytes += strlen(json_encode(
                    [$stored->event->payload(), $stored->event->metadata()],
                    JSON_THROW_ON_ERROR,
                ));
                if ($this->bytes > $this->maximumBytes) {
                    throw new HistoryReadLimitException("This verification exceeds its payload limit.");
                }
            }
        }
        $this->checkTime();
        return $page;
    }
    public function eventsRead(): int
    {
        return $this->events;
    }

    public function checkTime(): void
    {
        if (
            $this->maximumMilliseconds !== null &&
            hrtime(true) - $this->started >= $this->maximumMilliseconds * 1_000_000
        ) {
            throw new HistoryReadLimitException("This verification exceeds its time limit.");
        }
    }
}
