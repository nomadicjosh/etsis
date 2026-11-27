<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Registration\Event\SectionDefinitionWasPublished;
use Domain\Registration\ValueObject\SectionMeetingPlanDefinition;
use Domain\Registration\ValueObject\SectionPublicationId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\ValidationException;

/** Internal exact-offering verification shared by meeting publication and faculty assessment. */
final readonly class MeetingPlanOfferingReader
{
    public function __construct(private HistoricalEventStore $history)
    {
    }

    /** @return array<string,array<string,mixed>> */
    public function read(SectionMeetingPlanDefinition $plan, HistoryReadBudget $budget): array
    {
        $needed = array_fill_keys(array_column($plan->data["meetings"], "offering_publication_id"), true);
        if ($needed === []) {
            return [];
        }
        $stream = new StreamReference(
            new SectionPublicationId($plan->data["section_id"]),
            "section_publication",
        );
        $definitions = [];
        $accept = static function ($stored) use (&$definitions, &$needed): void {
            if (!($stored->event instanceof SectionDefinitionWasPublished)) {
                throw new CorruptHistoryException();
            }
            $payload = $stored->event->payload();
            if (isset($needed[$payload["version_id"]])) {
                $definitions[$payload["version_id"]] = $payload["definition"];
                unset($needed[$payload["version_id"]]);
            }
        };
        if (($head = $this->history->latest($stream)) !== null) {
            $accept($head);
        }
        $cursor = null;
        while ($needed !== []) {
            $page = $budget->page($this->history, $stream, $cursor);
            foreach ($page->events as $stored) {
                $accept($stored);
            }
            $cursor = $page->nextCursor;
            if ($cursor === null && $needed !== []) {
                throw new CorruptHistoryException();
            }
        }
        self::validate($plan, $definitions);
        return $definitions;
    }

    /** Shared retained-date/delivery rules; callers must authenticate the definitions first. */
    public static function validate(SectionMeetingPlanDefinition $plan, #[\SensitiveParameter] array $definitions): void
    {
        $zone = new DateTimeZone($plan->data["timezone"]);
        foreach ($plan->data["meetings"] as $meeting) {
            $definition = $definitions[$meeting["offering_publication_id"]] ?? throw new CorruptHistoryException();
            $start = new DateTimeImmutable($definition["instruction_starts_on"], $zone);
            $end = new DateTimeImmutable($definition["instruction_ends_on"], new DateTimeZone("UTC"))
                ->modify("+1 day")
                ->format("Y-m-d");
            if (
                new DateTimeImmutable($meeting["starts_at"]) < $start ||
                new DateTimeImmutable($meeting["ends_at"]) > new DateTimeImmutable($end, $zone) ||
                ($meeting["room_id"] === null &&
                    !in_array($definition["delivery_mode"], ["online", "hybrid"], true))
            ) {
                throw new ValidationException([
                    [
                        "field" => "meetings",
                        "message" =>
                            "A meeting no longer matches its retained offering. Review the linked booking first.",
                    ],
                ]);
            }
        }
    }
}
