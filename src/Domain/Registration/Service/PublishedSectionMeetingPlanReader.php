<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Registration\Event\SectionMeetingPlanWasPublished;
use Domain\Registration\Repository\SectionMeetingPlanRepository;
use Domain\Registration\ValueObject\SectionMeetingPlanDefinition;
use Domain\Registration\ValueObject\SectionMeetingPlanId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;

/** Internal verified current read. Every calling workflow must establish and recheck its own authority. */
final readonly class PublishedSectionMeetingPlanReader
{
    public function __construct(private SectionMeetingPlanRepository $plans, private HistoricalEventStore $history)
    {
    }

    public function read(EntityId $section): ?StoredEvent
    {
        $head = $this->history->latest(new StreamReference(
            new SectionMeetingPlanId($section->value),
            'section_meeting_plan'
        ));
        $projection = $this->plans->current($section);
        if ($head === null && $projection === null) {
            return null;
        }
        if (
            $head === null || $projection === null || !$head->event instanceof SectionMeetingPlanWasPublished
            || $head->event->playhead() !== (int) $projection['version']
            || $head->event->payload()['version_id'] !== $projection['publication_id']
            || new SectionMeetingPlanDefinition($head->event->payload()['definition'])->fingerprint() !==
                $projection['definition_hash']
        ) {
            throw new CorruptHistoryException();
        }
        return $head;
    }
}
