<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Registration\Event\SectionOfferingVersionWasSelected;
use Domain\Registration\Event\SectionOfferingVersionWasAmended;
use Domain\Registration\Repository\SectionVersionRepository;
use Domain\Registration\ValueObject\PublishedSectionDefinition;
use Domain\Registration\ValueObject\SectionOfferingBindingId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;

/** Internal command check: one committed selection head event, independent of publication-stream length. */
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
        $last = $history->latest(self::stream($section));
        $position = $versions->selectionPosition($section);
        if ($last === null && $selected === null && $position === 0) {
            return null;
        }
        if (
            $selected === null ||
            $last === null ||
            $last->event->playhead() !== $position ||
            ($position === 1
                ? !($last->event instanceof SectionOfferingVersionWasSelected)
                : !($last->event instanceof SectionOfferingVersionWasAmended)) ||
            $last->event->payload()["publication_id"] !== $selected->publication->value ||
            !hash_equals($last->event->payload()["definition_hash"], $selected->fingerprint())
        ) {
            throw new CorruptHistoryException();
        }
        return $selected;
    }
}
