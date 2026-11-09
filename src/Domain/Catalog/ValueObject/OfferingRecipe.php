<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

final readonly class OfferingRecipe
{
    /** @var list<SectionBlueprint> */
    public array $sections;
    public function __construct(SectionBlueprint ...$sections)
    {
        $codes = array_map(static fn(SectionBlueprint $s): string => mb_strtolower($s->code), $sections);
        if (count($sections) < 1 || count($sections) > 25 || count(array_unique($codes)) !== count($sections)) {
            throw new ValidationException([
                ["field" => "sections", "message" => "Keep 1–25 sections with distinct codes in a template."],
            ]);
        }
        $this->sections = array_values($sections);
    }
    /** @return list<array{course_id: string, code: string, capacity: int, faculty_user_id: ?string, fee_minor: int, currency: string, delivery_mode: string, web_registration: bool}> */
    public function describe(): array
    {
        return array_map(static fn(SectionBlueprint $s): array => $s->describe(), $this->sections);
    }
    public function instantiate(EntityId $term): OfferingPlan
    {
        return new OfferingPlan(
            $term,
            ...array_map(static fn(SectionBlueprint $s) => $s->instantiate($term), $this->sections),
        );
    }
}
