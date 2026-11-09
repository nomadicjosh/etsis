<?php

declare(strict_types=1);

namespace Domain\Transfer\ValueObject;

use InvalidArgumentException;

final class TransferRevision
{
    /** @param array<string, mixed> $record */
    public static function of(string $kind, array $record): string
    {
        $fields = match ($kind) {
            'institutions' => ['id', 'code', 'name'],
            'external' => ['id', 'institution_id', 'code', 'title'],
            'equivalencies' => ['id', 'institution_id', 'institution_code', 'institution_name',
                'external_course_id', 'external_code', 'external_title', 'course_id', 'course_code', 'course_title',
                'starts_on', 'ends_on', 'minimum_grade_rank', 'credits_milli', 'grade_id', 'awarded_grade',
                'points_milli', 'passes', 'counts_gpa'],
            default => throw new InvalidArgumentException('Unknown transfer catalog record.'),
        };

        return hash('sha256', json_encode([$kind, array_map(static fn (string $field): mixed =>
            $record[$field] ?? null, $fields)], JSON_THROW_ON_ERROR));
    }
}
