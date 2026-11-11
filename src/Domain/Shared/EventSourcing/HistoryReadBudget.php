<?php

declare(strict_types=1);

namespace Domain\Shared\EventSourcing;

use InvalidArgumentException;

/** One request's shared replay limits. No caching or authoritative state. */
final class HistoryReadBudget
{
    private int $pages = 0;
    private int $events = 0;

    public function __construct(
        private readonly int $maximumPages = 400,
        private readonly int $maximumEvents = 2000,
    ) {
        if ($maximumPages < 1 || $maximumPages > 1000 || $maximumEvents < 1 || $maximumEvents > 10000) {
            throw new InvalidArgumentException("Use bounded history-read limits.");
        }
    }
    public function page(
        HistoricalEventStore $store,
        StreamReference $stream,
        ?string $cursor = null,
    ): EventPage {
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
        return $page;
    }
}
