<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\ValueObject\EntityId;
use Domain\Workspace\Repository\OperationsRepository;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;

final readonly class SqlOperationsRepository implements OperationsRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function page(string $context, EntityId $id, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        $columns = static fn (string $alias, array $names): string => implode(', ', array_map(
            static fn (string $name): string => $alias . '.' . $q($name),
            $names
        ));
        [$table, $foreign, $fields] = match ($context) {
            'roster' => ['sis_enrollments', 'section_id', ['id', 'student_id', 'status']],
            'enrollments' => ['sis_enrollments', 'student_id', ['id', 'section_id', 'status', 'term_id']],
            'assignments' => ['sis_assignments', 'section_id', ['id', 'title', 'possible_hundredths', 'due_on']],
            'restrictions' => ['sis_restrictions', 'person_id',
                ['id', 'code', 'severity', 'starts_on', 'ends_on', 'blocks_staff']],
            'prerequisites' => ['sis_prerequisites', 'course_id', ['id', 'required_course_id']],
            'window' => ['sis_reg_windows', 'term_id', ['id', 'opens_at', 'closes_at', 'course_limit']],
            'audit' => ['sis_audit', 'subject_id', ['id', 'action', 'recorded_at', 'actor_id']],
            'deliveries' => ['sis_deliveries', 'campaign_id', ['id', 'recipient_user_id', 'status', 'version']],
            default => throw new InvalidArgumentException('Unknown operations context.'),
        };
        $select = $columns('r', $fields);
        $from = $q($table) . ' r';
        if ($context === 'prerequisites') {
            $from .= ' JOIN ' . $q('sis_courses') . ' c ON c.' . $q('id') . ' = r.' . $q('required_course_id');
            $select .= ', ' . $columns('c', ['code', 'title']);
        }
        if ($context === 'deliveries') {
            $from .= ' LEFT JOIN ' . $q('sis_persons') . ' p ON p.' . $q('owner_user_id')
            . ' = r.' . $q('recipient_user_id');
            $select .= ', ' . $columns('p', ['given_name', 'family_name']);
        }
        if ($context === 'roster') {
            $from .= ' JOIN ' . $q('sis_students') . ' s ON s.' . $q('id') . ' = r.' . $q('student_id')
            . ' JOIN ' . $q('sis_persons') . ' p ON p.' . $q('id') . ' = s.' . $q('person_id')
            . ' JOIN ' . $q('sis_person_identifiers') . ' i ON i.' . $q('person_id') . ' = p.' . $q('id')
            . ' JOIN ' . $q('sis_academic_credits') . ' c ON c.' . $q('source_id') . ' = r.' . $q('id')
            . ' LEFT JOIN ' . $q('sis_grades') . ' g ON g.' . $q('id') . ' = c.' . $q('grade_id');
            $select .= ', ' . $columns('p', ['given_name', 'family_name']) . ', i.' . $q('institutional_id')
            . ', c.' . $q('version') . ' AS ' . $q('grade_version') . ', g.' . $q('code')
            . ' AS ' . $q('grade_code');
        }
        if ($context === 'enrollments') {
            $from .= ' JOIN ' . $q('sis_sections') . ' s ON s.' . $q('id') . ' = r.' . $q('section_id')
            . ' JOIN ' . $q('sis_academic_credits') . ' c ON c.' . $q('source_id') . ' = r.' . $q('id');
            $select .= ', s.' . $q('code') . ' AS ' . $q('section_code') . ', c.' . $q('course_code')
            . ', c.' . $q('course_title');
        }
        $parameters = [$id->value];
        $where = 'r.' . $q($foreign) . ' = ?';
        if ($after !== null) {
            $where .= ' AND r.' . $q('id') . ' < ?';
            $parameters[] = $after->value;
        }
        $rows = $this->sql->fetch('SELECT ' . $select . ' FROM ' . $from . ' WHERE ' . $where
            . ' ORDER BY r.' . $q('id') . ' DESC', $parameters, 51);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);

        return ['items' => $rows, 'next_after' => $more ? $rows[49]['id'] : null];
    }
}
