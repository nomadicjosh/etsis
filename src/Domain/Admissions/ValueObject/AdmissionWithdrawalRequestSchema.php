<?php

declare(strict_types=1);

namespace Domain\Admissions\ValueObject;

use Domain\Admissions\Enum\ApplicationStatus;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** Frozen encrypted working-record format. A request has one opening and at most one resolution. */
final class AdmissionWithdrawalRequestSchema
{
    private const array FIELDS = ['id', 'application_id', 'owner_user_id', 'status', 'version', 'reason',
        'requested_at', 'application_status', 'application_version', 'policy_mode', 'policy_revision',
        'request_revision', 'resolution', 'receipts'];
    public static function validate(#[\SensitiveParameter] array $r): void
    {
        if (
            count($r) !== 14 || array_diff(array_keys($r), self::FIELDS) !== []
            || !is_int($r['version']) || !in_array($r['version'], [1, 2], true)
            || !is_array($r['receipts']) || count($r['receipts']) !== $r['version']
            || !AdmissionOfferSchema::text($r['reason'], 2000)
            || !in_array($r['application_status'], ['submitted', 'accepted'], true)
            || !in_array($r['policy_mode'], ['request', 'direct'], true)
            || !in_array($r['status'], ['pending', 'approved', 'declined', 'cancelled', 'withdrawn'], true)
        ) {
            throw new CorruptHistoryException();
        }
        foreach (['id', 'application_id', 'owner_user_id'] as $field) {
            self::id($r[$field]);
        }
        foreach (['application_version', 'policy_revision', 'request_revision'] as $field) {
            if (!is_int($r[$field]) || $r[$field] < ($field === 'request_revision' ? 1 : 0)) {
                throw new CorruptHistoryException();
            }
        }
        self::date($r['requested_at']);
        if (
            ($r['status'] === 'pending') !== ($r['resolution'] === null)
            || ($r['status'] === 'withdrawn' && ($r['version'] !== 1 || $r['policy_mode'] !== 'direct'))
            || ($r['status'] !== 'withdrawn' && $r['policy_mode'] !== 'request')
            || ($r['version'] === 1 && !in_array($r['status'], ['pending', 'withdrawn'], true))
        ) {
            throw new CorruptHistoryException();
        }
        $versions = [];
        foreach ($r['receipts'] as $op => $receipt) {
            self::id($op);
            if (
                !is_array($receipt) || count($receipt) !== 3 || !is_bool($receipt['mine'] ?? null)
                || !is_array($receipt['result'] ?? null) || count($receipt['result']) !== 6
            ) {
                throw new CorruptHistoryException();
            }
            self::id($receipt['actor'] ?? null);
            $p = $receipt['result'];
            if (
                ($p['request_id'] ?? null) !== $r['id'] || !in_array($p['version'] ?? null, [1, 2], true)
                || isset($versions[$p['version']]) || !is_string($p['application_status'] ?? null)
                || ApplicationStatus::tryFrom($p['application_status']) === null
            ) {
                throw new CorruptHistoryException();
            }
            self::date($p['effective_at'] ?? null);
            if (($p['decision_id'] ?? null) !== null) {
                self::id($p['decision_id']);
                if ($p['decision_id'] !== $op || $p['application_status'] !== 'withdrawn') {
                    throw new CorruptHistoryException();
                }
            }
            $versions[$p['version']] = $receipt;
        }
        $opening = $versions[1] ?? throw new CorruptHistoryException();
        $last = $versions[$r['version']] ?? throw new CorruptHistoryException();
        if (
            !isset($r['receipts'][$r['id']]) || !$opening['mine'] || $opening['actor'] !== $r['owner_user_id']
            || $last['result']['status'] !== $r['status'] || $last['result']['version'] !== $r['version']
        ) {
            throw new CorruptHistoryException();
        }
        if ($r['resolution'] !== null) {
            $p = $r['resolution'];
            if (
                !is_array($p) || count($p) !== 5 || !AdmissionOfferSchema::text($p['response'] ?? null, 2000)
                || ($p['actor'] ?? null) !== $last['actor'] || ($p['at'] ?? null) !== $last['result']['effective_at']
                || ($p['decision_id'] ?? null) !== $last['result']['decision_id']
                || ($p['action'] ?? null) !== match ($r['status']) {
                    'approved' => 'approve', 'withdrawn' => 'direct', 'declined' => 'decline', 'cancelled' => 'cancel',
                    default => null
                }
            ) {
            throw new CorruptHistoryException();
            }
        }
    }
    private static function id(mixed $v): void
    {
        if (!is_string($v)) {
            throw new CorruptHistoryException();
        }
        new EntityId($v);
    }
    private static function date(mixed $v): void
    {
        if (!is_string($v)) {
            throw new CorruptHistoryException();
        }
        try {
            $date = new \DateTimeImmutable($v);
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
        if ($date->format(DATE_ATOM) !== $v) {
            throw new CorruptHistoryException();
        }
    }
}
