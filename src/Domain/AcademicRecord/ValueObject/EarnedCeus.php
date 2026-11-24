<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\ValueObject;

use Domain\Catalog\ValueObject\ContinuingEducationUnits;
use Domain\Registration\ValueObject\SectionDefinitionV1;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

use function Codefy\Framework\Helpers\trans;

/** Earned CEUs are a separate decision, never academic credits or an inferred offering award. */
final readonly class EarnedCeus
{
    public const string PERMISSION = 'sis:academic:ceus:amend';
    public const array EVENT_FIELDS = ['decision_id', 'previous_decision_id', 'previous_award', 'award', 'intent'];

    /** @param array<string, mixed> $facts */
    public function __construct(public array $facts)
    {
        $fields = ['earned_milli', 'offered_milli', 'section_version_id', 'completed_on'];
        if (
            count($facts) !== count($fields) || array_diff(array_keys($facts), $fields) !== [] ||
            !array_key_exists('earned_milli', $facts) ||
            ($facts['earned_milli'] !== null && !is_int($facts['earned_milli'])) ||
            !is_int($facts['offered_milli'] ?? null) || !is_string($facts['section_version_id'] ?? null)
        ) {
            throw new InvalidArgumentException(trans('Provide the complete earned CEU decision.'));
        }
        new ContinuingEducationUnits($facts['earned_milli']);
        new ContinuingEducationUnits($facts['offered_milli']);
        new EntityId($facts['section_version_id']);
        SectionDefinitionV1::assertDate($facts['completed_on']);
        if ($facts['earned_milli'] !== null && $facts['earned_milli'] > $facts['offered_milli']) {
            throw new InvalidArgumentException(trans('Earned CEUs cannot exceed the accepted offering declaration.'));
        }
    }

    public static function stored(?string $json): ?self
    {
        if ($json === null) {
            return null;
        }
        if (strlen($json) > 512) {
            throw new InvalidArgumentException(trans('Use a bounded earned CEU projection.'));
        }
        $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new InvalidArgumentException(trans('Use the complete earned CEU projection.'));
        }
        return new self($data);
    }

    public static function validateEvent(array $payload): void
    {
        if (
            count($payload) !== count(self::EVENT_FIELDS) ||
            array_diff(array_keys($payload), self::EVENT_FIELDS) !== [] ||
            !is_string($payload['decision_id'] ?? null) ||
            !is_string($payload['previous_decision_id'] ?? null) ||
            $payload['decision_id'] === $payload['previous_decision_id'] ||
            !is_string($payload['intent'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $payload['intent']) !== 1 ||
            !array_key_exists('previous_award', $payload) || !is_array($payload['award'] ?? null)
        ) {
            throw new InvalidArgumentException(trans('Use the complete attributed CEU decision.'));
        }
        new EntityId($payload['decision_id']);
        new EntityId($payload['previous_decision_id']);
        new self($payload['award']);
        if ($payload['previous_award'] !== null) {
            if (!is_array($payload['previous_award'])) {
                throw new InvalidArgumentException(trans('Use the complete previous CEU decision.'));
            }
            new self($payload['previous_award']);
        }
    }
}
