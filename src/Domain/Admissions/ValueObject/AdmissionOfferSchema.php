<?php

declare(strict_types=1);

namespace Domain\Admissions\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** Immutable offer wording and the policy actually issued to the applicant. */
final class AdmissionOfferSchema
{
    public const array FIELDS = ['operation_id', 'previous_operation_id', 'admission_context_id',
        'application_version', 'action', 'channel', 'offer'];
    public const array TERMS = ['id', 'owner_id', 'title', 'message', 'respond_by',
        'require_acceptance', 'policy_revision', 'status'];

    public static function text(#[\SensitiveParameter] mixed $text, int $maximum): bool
    {
        return is_string($text) && mb_check_encoding($text, 'UTF-8') && trim($text) !== ''
        && mb_strlen($text) <= $maximum && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text);
    }
    public static function validate(#[\SensitiveParameter] array $p): void
    {
        if (count($p) !== count(self::FIELDS) || array_diff(array_keys($p), self::FIELDS) !== []) {
            throw new CorruptHistoryException();
        }
        foreach (['operation_id', 'admission_context_id'] as $key) {
            if (!is_string($p[$key])) {
                throw new CorruptHistoryException();
            }
            new EntityId($p[$key]);
        }
        if ($p['previous_operation_id'] !== null) {
            if (!is_string($p['previous_operation_id'])) {
                throw new CorruptHistoryException();
            }
            new EntityId($p['previous_operation_id']);
        }
        if (
            !is_int($p['application_version']) || $p['application_version'] < 1
            || !in_array($p['action'], ['issue', 'accept', 'decline', 'cancel'], true)
            || !in_array($p['channel'], ['staff', 'applicant'], true)
            || ($p['channel'] === 'applicant' && !in_array($p['action'], ['accept', 'decline'], true))
        ) {
            throw new CorruptHistoryException();
        }
        $o = $p['offer'];
        if (!is_array($o) || count($o) !== count(self::TERMS) || array_diff(array_keys($o), self::TERMS) !== []) {
            throw new CorruptHistoryException();
        }
        foreach (['id', 'owner_id'] as $key) {
            if (!is_string($o[$key])) {
                throw new CorruptHistoryException();
            }
            new EntityId($o[$key]);
        }
        if (
            !self::text($o['title'], 120) || !self::text($o['message'], 10000)
            || !is_bool($o['require_acceptance']) || !is_int($o['policy_revision']) || $o['policy_revision'] < 0
            || $o['status'] !== match ($p['action']) {
                'issue' => 'pending', 'accept' => 'accepted', 'decline' => 'declined', 'cancel' => 'cancelled'
            } || ($p['action'] === 'issue' && $o['id'] !== $p['operation_id'])
        ) {
        throw new CorruptHistoryException();
        }
        if ($o['respond_by'] !== null) {
            if (!is_string($o['respond_by'])) {
                throw new CorruptHistoryException();
            }
            try {
                $at = new \DateTimeImmutable($o['respond_by']);
            } catch (\Throwable) {
                throw new CorruptHistoryException();
            }
            if ($at->format(DATE_ATOM) !== $o['respond_by']) {
                throw new CorruptHistoryException();
            }
        }
    }
}
