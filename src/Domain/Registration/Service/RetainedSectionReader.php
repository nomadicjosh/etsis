<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Registration\SectionPublication;
use Domain\Registration\ValueObject\SectionPublicationId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Internal immutable academic context. The calling workflow authorizes and locks the student. */
final readonly class RetainedSectionReader
{
    public function __construct(private HistoricalEventStore $history)
    {
    }

    public function periods(array $references, HistoryReadBudget $budget): array
    {
        $definitions = $this->definitions($references, $budget);
        foreach ($definitions as &$versions) {
            foreach ($versions as &$definition) {
                $definition = [
                    'course_id' => $definition['course_id'], 'course_version_id' => $definition['course_version_id'],
                    'term_id' => $definition['term_id'], 'starts_on' => $definition['instruction_starts_on'],
                    'ends_on' => $definition['instruction_ends_on'],
                ];
            }
            unset($definition);
        }
        unset($versions);
        return $definitions;
    }

    /** Resolve every requested version while verifying each frozen section stream once per invocation.
     * @param list<array{section_id:string,version_id:string}> $references
     * @return array<string,array<string,array<string,mixed>>>
     */
    public function definitions(array $references, HistoryReadBudget $budget): array
    {
        $result = $this->publications($references, $budget);
        foreach ($result as &$versions) {
            foreach ($versions as &$definition) {
                $definition = $definition->definition->data;
            }
            unset($definition);
        }
        unset($versions);
        return $result;
    }

    /**
     * Exact protected publications, including their original classification snapshots.
     * @param list<array{section_id:string,version_id:string}> $references
     * @return array<string,array<string,\Domain\Registration\ValueObject\PublishedSectionDefinition>>
     */
    public function publications(array $references, HistoryReadBudget $budget): array
    {
        if (count($references) > 200) {
            throw new InvalidArgumentException("Resolve at most 200 retained section references.");
        }
        $requested = [];
        foreach ($references as $reference) {
            $section = new EntityId($reference["section_id"]);
            $version = new EntityId($reference["version_id"]);
            $requested[$section->value][$version->value] = true;
        }
        ksort($requested, SORT_STRING);
        $result = [];
        foreach ($requested as $section => $versions) {
            $stream = new StreamReference(new SectionPublicationId($section), "section_publication");
            $root = SectionPublication::root($stream->aggregateId);
            $cursor = null;
            $found = [];
            do {
                $page = $budget->page($this->history, $stream, $cursor);
                foreach ($page->events as $stored) {
                    $root->acceptHistorical($stored->event);
                    $published = $root->latest() ?? throw new CorruptHistoryException();
                    $version = $published["version_id"];
                    if (isset($versions[$version])) {
                        if (isset($found[$version])) {
                            throw new CorruptHistoryException();
                        }
                        $found[$version] = new \Domain\Registration\ValueObject\PublishedSectionDefinition(
                            new EntityId($section),
                            new EntityId($version),
                            $root->playhead(),
                            new \Domain\Registration\ValueObject\SectionDefinitionV1($published['definition']),
                            isset($published['classifications'])
                                ? \Domain\Registration\ValueObject\SectionClassifications::fromArray(
                                    $published['classifications']
                                ) : null,
                        );
                    }
                }
                $cursor = $page->nextCursor;
            } while ($cursor !== null);
            if (count($found) !== count($versions)) {
                throw new CorruptHistoryException();
            }
            $result[$section] = $found;
        }
        return $result;
    }
}
