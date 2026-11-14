<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** References only: names and governing limits belong to the retained program publication. */
final class StudentSpecializationSchema
{
    public const array FIELDS = [
        'decision_id', 'previous_decision_id', 'student_id', 'assignment_id', 'program_id',
        'academic_year_id', 'program_version_id', 'cohort_binding_revision', 'specialization_ids',
    ];

    /** @param array<string,mixed> $payload
     * @return array<string,mixed> */
    public static function read(array $payload): array
    {
        if (count($payload) !== count(self::FIELDS) || array_diff(array_keys($payload), self::FIELDS) !== []) {
            throw new CorruptHistoryException();
        }
        foreach (self::FIELDS as $field) {
            if (in_array($field, ['specialization_ids', 'cohort_binding_revision'], true)) {
                continue;
            }
            if ($field === 'previous_decision_id' && $payload[$field] === null) {
                continue;
            }
            if (!is_string($payload[$field])) {
                throw new CorruptHistoryException();
            }
            new EntityId($payload[$field]);
        }
        if (
            !is_int($payload['cohort_binding_revision']) || $payload['cohort_binding_revision'] < 0 ||
            $payload['cohort_binding_revision'] > 2000000000 ||
            $payload['decision_id'] === $payload['previous_decision_id']
        ) {
            throw new CorruptHistoryException();
        }
        self::ids($payload['specialization_ids']);
        return array_replace(array_fill_keys(self::FIELDS, null), $payload);
    }

    /** @return list<string> */
    public static function ids(mixed $ids): array
    {
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 100) {
            throw new \InvalidArgumentException('Choose a bounded list of specializations.');
        }
        foreach ($ids as $id) {
            if (!is_string($id)) {
                throw new \InvalidArgumentException('Use specialization identities.');
            }
            new EntityId($id);
        }
        $sorted = $ids;
        sort($sorted, SORT_STRING);
        if ($sorted !== $ids || count(array_unique($ids)) !== count($ids)) {
            throw new \InvalidArgumentException('Use distinct specialization identities in identity order.');
        }
        return $ids;
    }
}
