<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use Domain\Registration\Section;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

final readonly class OfferingPlan
{
    /** @var list<Section> */
    public array $sections;

    public function __construct(public EntityId $term, Section ...$sections)
    {
        $ids = $codes = [];
        foreach ($sections as $section) {
            if ($section->active || $section->termId->value !== $term->value) {
                throw new ValidationException([
                    ["field" => "sections", "message" => "Prepare draft sections for one term."],
                ]);
            }
            $ids[] = $section->id->value;
            $codes[] = mb_strtolower($section->code);
        }
        if (
            count($sections) < 1 ||
            count($sections) > 25 ||
            count(array_unique($ids)) !== count($sections) ||
            count(array_unique($codes)) !== count($sections)
        ) {
            throw new ValidationException([
                [
                    "field" => "sections",
                    "message" => "Prepare between 1 and 25 sections with distinct references and codes.",
                ],
            ]);
        }
        $this->sections = array_values($sections);
    }

    /**
     * @return list<array{id: string, course_id: string, term_id: string, faculty_user_id: ?string,
     *     code: string, capacity: int, web_registration: bool, fee_minor: int, currency: string,
     *     delivery_mode: string}>
     */
    public function describe(): array
    {
        return array_map(
            static fn(Section $s): array => [
                "id" => $s->id->value,
                "course_id" => $s->courseId->value,
                "term_id" => $s->termId->value,
                "faculty_user_id" => $s->facultyUserId?->value,
                "code" => $s->code,
                "capacity" => $s->capacity,
                "web_registration" => $s->webRegistration,
                "fee_minor" => $s->fee->minor,
                "currency" => $s->fee->currency,
                "delivery_mode" => $s->deliveryMode->value,
            ],
            $this->sections,
        );
    }
}
