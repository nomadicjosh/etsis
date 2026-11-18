<?php

declare(strict_types=1);

namespace Domain\Admissions\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** Frozen intake approval; original submission/answers are never replaced. */
final class AdmissionDeferralSchema
{
    public const array FIELDS = ['decision_id', 'previous_decision_id', 'application_version',
        'kind', 'before', 'after', 'source'];
    public static function validate(#[\SensitiveParameter] array $p): void
    {
        if (
            count($p) !== count(self::FIELDS) || array_diff(array_keys($p), self::FIELDS) !== []
            || !is_int($p['application_version']) || $p['application_version'] < 1
            || !in_array($p['kind'], ['defer', 'reconfirm'], true)
        ) {
            throw new CorruptHistoryException();
        }
        foreach (['decision_id', 'previous_decision_id'] as $key) {
            if (!is_string($p[$key])) {
                throw new CorruptHistoryException();
            }
            new EntityId($p[$key]);
        }
        self::term($p['before']);
        self::term($p['after']);
        if (
            $p['decision_id'] === $p['previous_decision_id'] || $p['after']['academic_year_id'] === null
            || $p['after']['starts_on'] < $p['after']['year_starts_on']
            || $p['after']['ends_on'] > $p['after']['year_ends_on']
            || ($p['kind'] === 'defer' && ($p['before']['id'] === $p['after']['id']
                || $p['after']['starts_on'] <= $p['before']['starts_on']))
            || ($p['kind'] === 'reconfirm' && ($p['before']['id'] !== $p['after']['id']
                || $p['before'] === $p['after']))
        ) {
            throw new CorruptHistoryException();
        }
        AdmissionDecisionSchema::source($p['source']);
        if ($p['source']['entry_term_code'] !== $p['after']['code']) {
            throw new CorruptHistoryException();
        }
    }
    public static function term(mixed $t): void
    {
        $fields = ['id', 'code', 'name', 'starts_on', 'ends_on', 'academic_year_id',
            'year_code', 'year_starts_on', 'year_ends_on'];
        if (!is_array($t) || count($t) !== count($fields) || array_diff(array_keys($t), $fields) !== []) {
            throw new CorruptHistoryException();
        }
        if (!is_string($t['id'])) {
            throw new CorruptHistoryException();
        }
        new EntityId($t['id']);
        foreach (['code' => 22, 'name' => 120] as $field => $limit) {
            if (!AdmissionOfferSchema::text($t[$field], $limit)) {
                throw new CorruptHistoryException();
            }
        }
        self::date($t['starts_on']);
        self::date($t['ends_on']);
        if ($t['ends_on'] < $t['starts_on']) {
            throw new CorruptHistoryException();
        }
        if ($t['academic_year_id'] === null) {
            if ($t['year_code'] !== null || $t['year_starts_on'] !== null || $t['year_ends_on'] !== null) {
                throw new CorruptHistoryException();
            }
            return;
        }
        if (
            !is_string($t['academic_year_id']) || !is_string($t['year_code'])
            || preg_match('/^[12][0-9]{3}-[12][0-9]{3}$/D', $t['year_code']) !== 1
        ) {
            throw new CorruptHistoryException();
        }
        new EntityId($t['academic_year_id']);
        self::date($t['year_starts_on']);
        self::date($t['year_ends_on']);
        if ($t['year_ends_on'] < $t['year_starts_on']) {
            throw new CorruptHistoryException();
        }
    }
    private static function date(mixed $value): void
    {
        if (!is_string($value)) {
            throw new CorruptHistoryException();
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new CorruptHistoryException();
        }
    }
}
