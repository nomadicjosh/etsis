<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

/** V1/v2 remain frozen; v3 adds the explicitly assigned subject, or an explicit null. */
final readonly class CourseDefinitionV3
{
    public function __construct(public CourseDefinitionV2 $course, public ?SubjectSnapshot $subject)
    {
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return $this->course->describe() + ["subject" => $this->subject?->describe()];
    }
}
