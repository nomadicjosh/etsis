<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** Accepted immutable references and a small retained display label, without student profiles or reasons. */
final class StudentSpecializationRequirementsSchema
{
    public const array FIELDS = [
        'decision_id', 'previous_decision_id', 'student_id', 'assignment_id', 'program_id',
        'academic_year_id', 'program_version_id', 'cohort_binding_revision', 'specialization_id',
        'selection_decision_id', 'template_id', 'requirements_version_id', 'requirements_version', 'requirements_name',
    ];

    /** @param array<string,mixed> $p
     * @return array<string,mixed> */
    public static function read(array $p): array
    {
        if (count($p) !== count(self::FIELDS) || array_diff(array_keys($p), self::FIELDS) !== []) {
            throw new CorruptHistoryException();
        }
        foreach (self::FIELDS as $field) {
            if (!in_array($field, ['requirements_name', 'requirements_version', 'cohort_binding_revision'], true)) {
                if (!is_string($p[$field])) {
                    throw new CorruptHistoryException();
                }
                new EntityId($p[$field]);
            }
        }
        if (
            !is_int($p['cohort_binding_revision']) || $p['cohort_binding_revision'] < 0 ||
            $p['cohort_binding_revision'] > 2000000000 ||
            !is_int($p['requirements_version']) || $p['requirements_version'] < 1 ||
            $p['requirements_version'] > 2000000000 ||
            $p['decision_id'] === $p['previous_decision_id'] || $p['decision_id'] === $p['selection_decision_id'] ||
            !is_string($p['requirements_name']) || trim($p['requirements_name']) === '' ||
            mb_strlen($p['requirements_name']) > 191 || !mb_check_encoding($p['requirements_name'], 'UTF-8') ||
            preg_match('/[\x00-\x1F\x7F]/', $p['requirements_name'])
        ) {
            throw new CorruptHistoryException();
        }
        return array_replace(array_fill_keys(self::FIELDS, null), $p);
    }
}
