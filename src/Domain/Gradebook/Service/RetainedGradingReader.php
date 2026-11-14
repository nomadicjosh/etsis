<?php

declare(strict_types=1);

namespace Domain\Gradebook\Service;

use Domain\Gradebook\GradingScale;
use Domain\Gradebook\ValueObject\GradingScaleDefinition;
use Domain\Gradebook\ValueObject\GradingScaleId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Immutable definitions only. Its caller authorizes the academic scope and shares its replay budget. */
final readonly class RetainedGradingReader
{
    public function __construct(private HistoricalEventStore $history)
    {
    }

    /** @param list<EntityId> $versions
     * @return array<string,GradingScaleDefinition> */
    public function selected(EntityId $scale, array $versions, HistoryReadBudget $budget): array
    {
        if ($versions === [] || count($versions) > 201) {
            throw new InvalidArgumentException("Choose a bounded set of retained grading publications.");
        }
        $requested = [];
        foreach ($versions as $version) {
            if (isset($requested[$version->value])) {
                throw new InvalidArgumentException("Choose distinct grading publications.");
            }
            $requested[$version->value] = true;
        }
        $stream = new StreamReference(new GradingScaleId($scale->value), "grading_scale");
        $root = GradingScale::root($stream->aggregateId);
        $found = $seen = [];
        $cursor = null;
        do {
            $page = $budget->page($this->history, $stream, $cursor);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                $p = $stored->event->payload();
                if (isset($seen[$p["version_id"]])) {
                    throw new CorruptHistoryException();
                }
                $seen[$p["version_id"]] = true;
                if (isset($requested[$p["version_id"]]) && $p["definition"] !== null) {
                    $found[$p["version_id"]] = new GradingScaleDefinition($p["definition"]);
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if (count($found) !== count($requested)) {
            throw new RecordNotFoundException("Retained grading publication not found in its scale.");
        }
        return $found;
    }
}
