<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Catalog\Course;
use Domain\Catalog\ValueObject\CreditPolicy;
use Domain\Registration\Section;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

use function Codefy\Framework\Helpers\trans_html;

/** Genuine student choice under an explicitly retained offering/course range; never a later correction. */
final readonly class SelectedRegistrationCredits implements \JsonSerializable
{
    public function __construct(
        public int $milli,
        public EntityId $courseVersion,
        public EntityId $sectionVersion,
        public CreditPolicy $policy,
    ) {
        if ($policy->minimumMilli === $policy->maximumMilli) {
            throw new InvalidArgumentException('Student credit choices require a variable-credit policy.');
        }
        $policy->requireChoice($milli, 'credits_milli');
    }

    public static function choose(Section $section, Course $course, ?int $milli): ?self
    {
        if (!$section->studentSelectableCredits) {
            if ($milli !== null && $milli !== $course->registrationCreditsMilli()) {
                throw new ValidationException([['field' => 'credits_milli', 'message' => trans_html(
                    'This section uses one fixed offering quantity. Refresh its credit information.'
                )]]);
            }
            return null;
        }
        if (
            $milli === null || $section->publicationVersionId === null || $course->publicationVersionId === null
            || $course->creditPolicy === null
            || $course->creditPolicy->minimumMilli === $course->creditPolicy->maximumMilli
        ) {
            throw new ValidationException([['field' => 'credits_milli', 'message' => trans_html(
                'Choose academic credits under this section’s published variable-credit policy.'
            )]]);
        }
        return new self($milli, $course->publicationVersionId, $section->publicationVersionId, $course->creditPolicy);
    }

    public static function from(array $data): self
    {
        if (
            count($data) !== 4 || array_diff(
                ['credits_milli', 'course_version_id', 'section_version_id', 'policy'],
                array_keys($data)
            ) !== [] || !is_int($data['credits_milli'] ?? null) || !is_array($data['policy'] ?? null)
        ) {
            throw new InvalidArgumentException('Use complete retained student credit-choice evidence.');
        }
        return new self(
            $data['credits_milli'],
            new EntityId($data['course_version_id']),
            new EntityId($data['section_version_id']),
            CreditPolicy::fromArray($data['policy'])
        );
    }

    public function values(): array
    {
        return ['credits_milli' => $this->milli, 'course_version_id' => $this->courseVersion->value,
            'section_version_id' => $this->sectionVersion->value, 'policy' => $this->policy->describe()];
    }

    public function routing(?string $courseVersion, ?string $sectionVersion, ?int $milli = null): void
    {
        if (
            $this->courseVersion->value !== $courseVersion || $this->sectionVersion->value !== $sectionVersion
            || ($milli !== null && $this->milli !== $milli)
        ) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
    }

    /** Retrying registration preserves its original chosen quantity, including after later corrections. */
    public static function retry(?self $original, ?int $milli): void
    {
        if ($original !== null && $milli !== null && $original->milli !== $milli) {
            throw new ValidationException([['field' => 'credits_milli', 'message' => trans_html(
                'This enrollment already has a different accepted credit choice. Review its academic record.'
            )]]);
        }
    }
    public function jsonSerialize(): array
    {
        return ['credit_choice' => '[protected registration evidence]'];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
