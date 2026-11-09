<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

/** The original four-field contract remains unchanged; v2 adds explicitly nullable catalog text. */
final readonly class CourseDefinitionV2
{
    public function __construct(public CourseDefinition $core, public CourseText $text)
    {
    }

    /** @return array{code: string, title: string, credits_milli: int, prerequisites: list<string>, transcript_title: ?string, description: ?string} */
    public function describe(): array
    {
        return $this->core->describe() + $this->text->describe();
    }
}
