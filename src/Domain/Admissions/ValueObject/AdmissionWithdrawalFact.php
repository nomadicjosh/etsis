<?php

declare(strict_types=1);

namespace Domain\Admissions\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** Protected provenance for a direct applicant withdrawal or a staff-approved working request. */
final class AdmissionWithdrawalFact
{
    public static function validate(#[\SensitiveParameter] mixed $fact, #[\SensitiveParameter] array $payload): void
    {
        $fields = ['channel', 'request_id', 'requested_by', 'requested_at', 'policy_mode', 'policy_revision'];
        if (
            !is_array($fact) || count($fact) !== 6 || array_diff(array_keys($fact), $fields) !== []
            || ($payload['status'] ?? null) !== 'withdrawn'
            || !in_array($fact['channel'], ['applicant', 'staff'], true)
            || !is_int($fact['policy_revision']) || $fact['policy_revision'] < 0
            || $fact['policy_mode'] !== ($fact['channel'] === 'applicant' ? 'direct' : 'request')
        ) {
            throw new CorruptHistoryException();
        }
        foreach (['request_id', 'requested_by'] as $key) {
            if (!is_string($fact[$key])) {
                throw new CorruptHistoryException();
            }
            new EntityId($fact[$key]);
        }
        if (!is_string($fact['requested_at'])) {
            throw new CorruptHistoryException();
        }
        try {
            $date = new \DateTimeImmutable($fact['requested_at']);
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
        if (
            $date->format(DATE_ATOM) !== $fact['requested_at']
            || ($fact['channel'] === 'applicant' && ($fact['request_id'] !== $payload['decision_id']
                || !in_array($payload['previous_status'], ['submitted', 'accepted'], true)))
        ) {
            throw new CorruptHistoryException();
        }
    }
}
