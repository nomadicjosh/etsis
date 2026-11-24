<?php

declare(strict_types=1);

namespace Domain\Admissions\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** An actual correction of planned program; original submitted facts remain separate. */
final class AdmissionProgramCorrectionSchema
{
    public const array FIELDS = ['decision_id', 'previous_decision_id', 'application_version',
        'before', 'after', 'source', 'shared_form'];

    public static function validate(#[\SensitiveParameter] array $p): void
    {
        if (
            count($p) !== count(self::FIELDS) || array_diff(array_keys($p), self::FIELDS) !== []
            || !is_int($p['application_version']) || $p['application_version'] < 1
        ) {
            throw new CorruptHistoryException();
        }
        foreach (['decision_id', 'previous_decision_id'] as $key) {
            if (!is_string($p[$key])) {
                throw new CorruptHistoryException();
            }
            new EntityId($p[$key]);
        }
        if ($p['shared_form'] !== null) {
            $f = $p['shared_form'];
            if (
                !is_array($f) || count($f) !== 3
                || array_diff(array_keys($f), ['id', 'academic_level_id', 'definition']) !== []
                || !is_string($f['id']) || !is_string($f['academic_level_id'])
                || !is_string($f['definition']) || preg_match('/^[a-f0-9]{64}$/D', $f['definition']) !== 1
            ) {
                throw new CorruptHistoryException();
            }
            new EntityId($f['id']);
            new EntityId($f['academic_level_id']);
        }
        self::program($p['before']);
        self::program($p['after']);
        AdmissionDecisionSchema::source($p['source']);
        if (
            ($p['source']['submission'] === null) !== ($p['shared_form'] === null)
            || ($p['shared_form'] !== null && $p['source']['submission']['form_id'] !== $p['shared_form']['id'])
            || $p['decision_id'] === $p['previous_decision_id'] || $p['before']['id'] === $p['after']['id']
            || $p['source']['program_code'] !== $p['after']['code']
            || $p['source']['program_title'] !== $p['after']['title']
        ) {
            throw new CorruptHistoryException();
        }
    }

    public static function program(mixed $p): void
    {
        if (
            !is_array($p) || count($p) !== 3 || array_diff(array_keys($p), ['id', 'code', 'title']) !== []
            || !is_string($p['id']) || !AdmissionOfferSchema::text($p['code'], 80)
            || !AdmissionOfferSchema::text($p['title'], 191)
        ) {
            throw new CorruptHistoryException();
        }
        new EntityId($p['id']);
    }
}
