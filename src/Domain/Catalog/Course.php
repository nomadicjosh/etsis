<?php

declare(strict_types=1);

namespace Domain\Catalog;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class Course
{
    /** @param list<string>|null $publishedPrerequisites */
    public function __construct(
        public EntityId $id,
        public string $code,
        public string $title,
        public int $creditsMilli,
        public bool $active = true,
        public ?string $publishedTranscriptTitle = null,
        public ?EntityId $publicationVersionId = null,
        public ?array $publishedPrerequisites = null,
        public ?\Domain\Catalog\ValueObject\PrerequisiteGroups $prerequisiteGroups = null,
        public ?\Domain\Catalog\ValueObject\CreditPolicy $creditPolicy = null,
        public ?int $offeringCreditsMilli = null,
    ) {
        $creditPolicy?->requireChoice($creditsMilli);
        if (in_array($id->value, $prerequisiteGroups?->references() ?? [], true)) {
            throw new InvalidArgumentException('A course cannot require itself.');
        }
        if (($publicationVersionId === null) !== ($publishedPrerequisites === null)) {
            throw new InvalidArgumentException(
                "A retained course version requires its prerequisite definition.",
            );
        }
        if ($publishedPrerequisites !== null) {
            new \Domain\Catalog\ValueObject\CourseDefinition(
                $code,
                $title,
                $creditsMilli,
                $publishedPrerequisites,
                prerequisiteGroups: $prerequisiteGroups,
                creditPolicy: $creditPolicy,
            );
        }
        if (
            $publishedTranscriptTitle !== null &&
            new \Domain\Catalog\ValueObject\CourseText($publishedTranscriptTitle)->transcriptTitle !==
                $publishedTranscriptTitle
        ) {
            throw new InvalidArgumentException("A canonical published transcript title is required.");
        }
        if (
            trim($code) === "" ||
            strlen($code) > 80 ||
            trim($title) === "" ||
            mb_strlen($title) > 191 ||
            $creditsMilli < 0 ||
            $creditsMilli > 999_999
        ) {
            throw new InvalidArgumentException(
                "Valid course details and nonnegative credit thousandths are required.",
            );
        }
    }

    public function registrationCreditsMilli(): int
    {
        $credits = $this->offeringCreditsMilli ?? $this->creditsMilli;
        ($this->creditPolicy ?? \Domain\Catalog\ValueObject\CreditPolicy::fixed($this->creditsMilli))
            ->requireChoice($credits);
        return $credits;
    }
}
