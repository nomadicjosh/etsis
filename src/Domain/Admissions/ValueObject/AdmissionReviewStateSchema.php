<?php

declare(strict_types=1);

namespace Domain\Admissions\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

final class AdmissionReviewStateSchema
{
    public const array FIELDS = ['decision_id', 'previous_decision_id', 'previous_status',
        'status', 'application_version', 'source'];

    public static function validate(#[\SensitiveParameter] array $payload): void
    {
        if (count($payload) !== count(self::FIELDS) || array_diff(array_keys($payload), self::FIELDS) !== []) {
            throw new CorruptHistoryException();
        }
        foreach (['decision_id', 'previous_decision_id'] as $key) {
            if (!is_string($payload[$key])) {
                throw new CorruptHistoryException();
            }
            new EntityId($payload[$key]);
        }
        if (
            $payload['decision_id'] === $payload['previous_decision_id']
            || !is_int($payload['application_version']) || $payload['application_version'] < 1
            || !($payload['status'] === 'withdrawn'
                ? in_array($payload['previous_status'], ['submitted', 'accepted', 'rejected'], true)
                : $payload['status'] === 'submitted' && $payload['previous_status'] === 'withdrawn')
        ) {
            throw new CorruptHistoryException();
        }
        AdmissionDecisionSchema::source($payload['source']);
    }
}
