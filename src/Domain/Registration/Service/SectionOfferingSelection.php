<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Registration\Event\SectionOfferingVersionWasSelected;
use Domain\Registration\Repository\SectionVersionRepository;
use Domain\Registration\ValueObject\PublishedSectionDefinition;
use Domain\Registration\ValueObject\SectionOfferingBindingId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;

/** Internal command check: at most one selection event, independent of publication-stream length. */
final class SectionOfferingSelection
{
    public static function stream(EntityId $section): StreamReference
    {
        return new StreamReference(new SectionOfferingBindingId($section->value), "section_offering_binding");
    }

    public static function verify(
        EntityId $section,
        SectionVersionRepository $versions,
        HistoricalEventStore $history,
    ): ?PublishedSectionDefinition {
        $selected = $versions->selected($section);
        $page = $history->page(self::stream($section), null, 2);
        if ($page->throughVersion === 0 && $page->events === [] && $selected === null) {
            return null;
        }
        if (
            $selected === null ||
            $page->throughVersion !== 1 ||
            count($page->events) !== 1 ||
            $page->nextCursor !== null ||
            !($page->events[0]->event instanceof SectionOfferingVersionWasSelected) ||
            $page->events[0]->event->playhead() !== 1 ||
            $page->events[0]->event->payload()["publication_id"] !== $selected->publication->value ||
            !hash_equals($page->events[0]->event->payload()["definition_hash"], $selected->fingerprint())
        ) {
            throw new CorruptHistoryException();
        }
        return $selected;
    }
}
