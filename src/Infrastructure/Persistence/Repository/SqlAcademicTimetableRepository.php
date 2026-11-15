<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Scheduling\Repository\AcademicTimetableRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlAcademicTimetableRepository implements AcademicTimetableRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function terms(EntityId $owner, string $search, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        $statement = $this->termSql();
        $params = [$owner->value, $owner->value];
        if ($search !== '') {
            $literal = strtr(mb_strtolower($search), ['!' => '!!', '%' => '!%', '_' => '!_']);
            $statement .= ' AND LOWER(t.' . $q('code') . ") LIKE ? ESCAPE '!'";
            $params[] = '%' . $literal . '%';
        }
        if ($after !== null) {
            $statement .= ' AND t.' . $q('id') . ' < ?';
            $params[] = $after->value;
        }
        return $this->sql->fetch($statement . ' ORDER BY t.' . $q('id') . ' DESC', $params, 21);
    }

    public function recommendedTerm(EntityId $owner, string $today): ?array
    {
        $q = $this->sql->quote(...);
        $start = 't.' . $q('starts_on');
        $end = 't.' . $q('ends_on');
        return $this->sql->fetch(
            $this->termSql() . ' ORDER BY CASE WHEN ' . $start . ' <= ? AND '
            . $end . ' >= ? THEN 0 WHEN ' . $start . ' > ? THEN 1 ELSE 2 END, CASE WHEN '
            . $start . ' > ? THEN ' . $start . ' ELSE NULL END, ' . $end . ' DESC, t.' . $q('id') . ' DESC',
            [$owner->value, $owner->value, $today, $today, $today, $today],
            1
        )[0] ?? null;
    }

    public function term(EntityId $owner, EntityId $term): array
    {
        return $this->sql->fetch(
            $this->termSql() . ' AND t.' . $this->sql->quote('id') . ' = ?',
            [$owner->value, $owner->value, $term->value],
            1
        )[0]
        ?? throw new AccessDeniedException('This term has no classes assigned to your account.');
    }

    public function sections(EntityId $owner, EntityId $term): array
    {
        $q = $this->sql->quote(...);
        $learning = $this->sql->fetch('SELECT DISTINCT e.' . $q('section_id') . ' AS ' . $q('id')
            . ' FROM ' . $q('sis_persons') . ' p JOIN ' . $q('sis_students') . ' st ON st.' . $q('person_id')
            . ' = p.' . $q('id') . ' JOIN ' . $q('sis_enrollments') . ' e ON e.' . $q('student_id')
            . ' = st.' . $q('id') . ' JOIN ' . $q('sis_sections') . ' s ON s.' . $q('id') . ' = e.'
            . $q('section_id') . ' AND s.' . $q('term_id') . ' = e.' . $q('term_id')
            . ' WHERE p.' . $q('owner_user_id') . ' = ? AND e.' . $q('term_id') . ' = ? AND e.'
            . $q('status') . ' IN (?, ?) ORDER BY e.' . $q('section_id'), [$owner->value, $term->value, 'A', 'N'], 51);
        $teaching = $this->sql->fetch(
            'SELECT ' . $q('id') . ' FROM ' . $q('sis_sections') . ' WHERE '
            . $q('faculty_user_id') . ' = ? AND ' . $q('term_id') . ' = ? ORDER BY ' . $q('id'),
            [$owner->value, $term->value],
            51
        );
        $learn = array_fill_keys(array_column($learning, 'id'), true);
        $teach = array_fill_keys(array_column($teaching, 'id'), true);
        $ids = array_keys($learn + $teach);
        if (count($ids) > 50) {
            throw new ValidationException([['field' => 'term_id',
                'message' => 'More than 50 classes match. Ask your records office to review this term.',
            ]]);
        }
        if ($ids === []) {
            return [];
        }
        $rows = $this->sql->fetch('SELECT s.' . $q('id') . ', s.' . $q('code') . ', cv.' . $q('code')
            . ' AS ' . $q('course_code') . ', cv.' . $q('title') . ' AS ' . $q('course_title') . ', mp.'
            . $q('publication_id') . ', mp.' . $q('version') . ' AS ' . $q('plan_version') . ', mp.'
            . $q('definition_hash') . ' FROM ' . $q('sis_sections') . ' s LEFT JOIN '
            . $q('sis_section_course_versions') . ' sc ON sc.' . $q('id') . ' = s.' . $q('id')
            . ' LEFT JOIN ' . $q('sis_course_versions') . ' cv ON cv.' . $q('id') . ' = sc.' . $q('course_version_id')
            . ' LEFT JOIN ' . $q('sis_section_meeting_plans') . ' mp ON mp.' . $q('id') . ' = s.' . $q('id')
            . ' WHERE s.' . $q('term_id') . ' = ? AND s.' . $q('id') . ' IN ('
            . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY s.' . $q('id'), [$term->value, ...$ids], 51);
        return array_map(static fn(array $row): array => $row + [
            'learning' => isset($learn[$row['id']]), 'teaching' => isset($teach[$row['id']]),
        ], $rows);
    }

    private function termSql(): string
    {
        $q = $this->sql->quote(...);
        return 'SELECT t.' . $q('id') . ', t.' . $q('code') . ', t.' . $q('starts_on') . ', t.' . $q('ends_on')
        . ' FROM ' . $q('sis_terms') . ' t WHERE t.' . $q('id') . ' IN (SELECT s.' . $q('term_id')
        . ' FROM ' . $q('sis_sections') . ' s WHERE s.' . $q('faculty_user_id') . ' = ? UNION SELECT e.'
        . $q('term_id') . ' FROM ' . $q('sis_enrollments') . ' e JOIN ' . $q('sis_students')
        . ' st ON st.' . $q('id') . ' = e.'
        . $q('student_id') . ' JOIN ' . $q('sis_persons') . ' p ON p.' . $q('id') . ' = st.' . $q('person_id')
        . ' JOIN ' . $q('sis_sections') . ' enrolled ON enrolled.' . $q('id') . ' = e.' . $q('section_id')
        . ' AND enrolled.' . $q('term_id') . ' = e.' . $q('term_id') . ' WHERE e.'
        . $q('status') . ' IN (\'A\', \'N\') AND p.' . $q('owner_user_id') . ' = ?)';
    }
}
