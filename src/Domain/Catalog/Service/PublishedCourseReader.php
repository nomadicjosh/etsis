<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use Domain\Catalog\CoursePublication;
use Domain\Catalog\Event\CourseDefinitionWasPublished;
use Domain\Catalog\ValueObject\CoursePublicationId;
use Domain\Catalog\ValueObject\PublishedCourseDefinition;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;

/** Immutable catalog reads. The calling workflow must authorize its office/student scope. */
final readonly class PublishedCourseReader
{
    public function __construct(private HistoricalEventStore $history)
    {
    }

    /** Curated frozen choices without protected publication reasons or actors.
     * @return array<string,mixed>
     */
    public function page(EntityId $course, ?string $cursor = null): array
    {
        $page = $this->history->page($this->stream($course), $cursor, 10);
        $items = [];
        foreach ($page->events as $stored) {
            $e = $stored->event;
            if (!($e instanceof CourseDefinitionWasPublished)) {
                throw new CorruptHistoryException();
            }
            $d = PublishedCourseDefinition::fromPublication($course, $e->payload())->definition;
            $items[] = [
                "version_id" => $e->payload()["version_id"],
                "version" => $e->playhead(),
                "code" => $d->code,
                "title" => $d->title,
                "transcript_title" => $d->text->transcriptTitle ?? $d->title,
                "credits_milli" => $d->creditsMilli,
            ];
        }
        return ["items" => $items, "next_cursor" => $page->nextCursor];
    }

    public function version(
        EntityId $course,
        EntityId $version,
        HistoryReadBudget $budget,
    ): PublishedCourseDefinition {
        $stream = $this->stream($course);
        $root = CoursePublication::root($stream->aggregateId);
        $cursor = null;
        $found = null;
        do {
            $page = $budget->page($this->history, $stream, $cursor);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                $p = $stored->event->payload();
                if ($p["version_id"] === $version->value) {
                    if ($found !== null) {
                        throw new CorruptHistoryException();
                    }
                    $found = PublishedCourseDefinition::fromPublication($course, $p);
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        return $found ??
        throw new RecordNotFoundException("Choose a publication belonging to this institutional course.");
    }

    private function stream(EntityId $course): StreamReference
    {
        return new StreamReference(new CoursePublicationId($course->value), "course_publication");
    }
}
