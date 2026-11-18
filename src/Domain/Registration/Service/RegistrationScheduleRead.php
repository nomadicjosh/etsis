<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Registration\ValueObject\SectionMeetingPlanDefinition;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** One invocation's bounded head cache. Never shared between requests, reviews or final confirmation checks. */
final class RegistrationScheduleRead
{
    private array $plans = [];
    private int $meetings = 0;
    public function read(
        EntityId $section,
        PublishedSectionMeetingPlanReader $reader,
    ): ?array {
        if (array_key_exists($section->value, $this->plans)) {
            return $this->plans[$section->value];
        }
        if (count($this->plans) >= 256) {
            self::limit();
        }
        $head = $reader->read($section);
        if ($head === null) {
            return $this->plans[$section->value] = null;
        }
        $definition = new SectionMeetingPlanDefinition(
            $head->event->payload()["definition"],
        );
        $this->meetings += count($definition->data["meetings"]);
        if ($this->meetings > 20000) {
            self::limit();
        }
        return $this->plans[$section->value] = [
            "reference" => [
                "section_id" => $section->value,
                "publication_id" => $head->event->payload()["version_id"],
                "version" => $head->event->playhead(),
                "definition_hash" => $definition->fingerprint(),
            ],
            "definition" => $definition->data,
        ];
    }
    private static function limit(): never
    {
        throw new ValidationException([
            [
                "field" => "schedule",
                "message" =>
                    "This schedule review exceeds its bounded verification limit. Ask " .
                    "the registrar to review it; no partial registration was saved.",
            ],
        ]);
    }
}
