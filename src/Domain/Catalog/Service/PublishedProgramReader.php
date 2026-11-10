<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use Domain\Catalog\ProgramPublication;
use Domain\Catalog\Repository\ProgramDefinitionRepository;
use Domain\Catalog\ValueObject\ProgramDefinition;
use Domain\Catalog\ValueObject\ProgramPublicationId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;

/** Internal command reader. Callers authorize the student/admissions operation and hold its locks. */
final readonly class PublishedProgramReader
{
    public function __construct(
        private HistoricalEventStore $history,
        private ProgramDefinitionRepository $definitions,
    ) {
    }

    public function current(EntityId $program, EntityId $year, ?EntityId $catalog): ?ProgramDefinition
    {
        $stream = new StreamReference(new ProgramPublicationId($program->value), "program_publication");
        $root = ProgramPublication::root($stream->aggregateId);
        $latest = null;
        $cursor = null;
        do {
            $page = $this->history->page($stream, $cursor, 50);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                $payload = $stored->event->payload();
                if ($payload["academic_year_id"] === $year->value) {
                    if (
                        $latest !== null &&
                        ($payload["previous_catalog_id"] !== $latest->event->payload()["version_id"] ||
                            $payload["catalog_revision"] <= $latest->event->payload()["catalog_revision"])
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $latest = $stored;
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if ($latest === null) {
            if ($catalog !== null && $this->definitions->retained($catalog) !== null) {
                throw new CorruptHistoryException();
            }
            return null;
        }
        $payload = $latest->event->payload();
        if ($catalog === null || $catalog->value !== $payload["version_id"]) {
            throw new CorruptHistoryException();
        }
        $retained = $this->definitions->retained($catalog);
        $definition = ProgramDefinition::fromArray($payload["definition"]);
        $expected = [
            "program_id" => $program->value,
            "academic_year_id" => $year->value,
            "academic_year_code" => $payload["academic_year_code"],
            "catalog_revision" => $payload["catalog_revision"],
            "stream_version" => $latest->event->playhead(),
            "definition" => $definition->describe(),
        ];
        if ($retained !== $expected) {
            throw new CorruptHistoryException();
        }
        return $definition;
    }
}
