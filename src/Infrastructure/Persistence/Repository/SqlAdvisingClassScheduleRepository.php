<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Advising\Repository\AdvisingClassScheduleRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlAdvisingClassScheduleRepository implements AdvisingClassScheduleRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function enrollments(EntityId $student, string $firstDay, string $lastDay): array
    {
        $q = $this->sql->quote(...);
        $versions = 'SELECT 1 FROM ' . $q('sis_section_versions') . ' v WHERE v.' . $q('section_id')
        . ' = e.' . $q('section_id');
        return $this->sql->fetch(
            'SELECT e.' . $q('id') . ', e.' . $q('section_id') . ', e.' . $q('version')
            . ', p.' . $q('publication_id') . ', h.' . $q('version') . ' AS ' . $q('history_version')
            . ' FROM ' . $q('sis_enrollments') . ' e JOIN ' . $q('sis_sections') . ' s ON s.' . $q('id')
            . ' = e.' . $q('section_id') . ' JOIN ' . $q('sis_terms') . ' t ON t.' . $q('id') . ' = s.'
            . $q('term_id') . ' LEFT JOIN ' . $q('sis_section_meeting_plans') . ' p ON p.' . $q('id')
            . ' = s.' . $q('id') . ' LEFT JOIN ' . $q('sis_history_streams') . ' h ON h.' . $q('aggregate_id')
            . ' = s.' . $q('id') . ' AND h.' . $q('stream_type') . ' = ? WHERE e.' . $q('student_id')
            . ' = ? AND e.' . $q('status') . ' IN (?, ?) AND (EXISTS (' . $versions
            . ' AND v.' . $q('instruction_starts_on') . ' <= ? AND v.' . $q('instruction_ends_on') . ' >= ?)'
            . ' OR (NOT EXISTS (' . $versions . ') AND t.' . $q('starts_on') . ' <= ? AND t.'
            . $q('ends_on') . ' >= ?)) ORDER BY e.' . $q('section_id'),
            ['section_meeting_plan', $student->value, 'A', 'N', $lastDay, $firstDay, $lastDay, $firstDay],
            51
        );
    }

    public function teaching(EntityId $person, EntityId $account, string $firstDay, string $lastDay): array
    {
        $q = $this->sql->quote(...);
        return array_column($this->sql->fetch(
            'SELECT DISTINCT v.' . $q('section_id') . ' FROM ' . $q('sis_section_versions') . ' v'
            . ' WHERE (v.' . $q('faculty_person_id') . ' = ? OR (v.' . $q('faculty_person_id')
            . ' IS NULL AND v.' . $q('faculty_user_id') . ' = ?)) AND v.' . $q('instruction_starts_on')
            . ' <= ? AND v.' . $q('instruction_ends_on') . ' >= ? ORDER BY v.' . $q('section_id'),
            [$person->value, $account->value, $lastDay, $firstDay],
            51
        ), 'section_id');
    }
}
