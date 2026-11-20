<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Advising\Repository\AdvisorRepository;
use Domain\Advising\ValueObject\AdvisorAssignments;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlAdvisorRepository implements AdvisorRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function rows(EntityId $pursuit): array
    {
        $rows = $this->sql->rows('sis_student_advisors', ['pursuit_id' => $pursuit->value], 51);
        return new AdvisorAssignments(array_map(static fn(array $r): array => array_intersect_key(
            $r,
            array_flip(['id', 'advisor_person_id', 'role', 'starts_on', 'ends_on'])
        ), $rows))->rows;
    }

    public function project(EntityId $student, EntityId $pursuit, array $rows): void
    {
        foreach ($rows as $row) {
            $old = $this->sql->rows('sis_student_advisors', ['id' => $row['id']], 1)[0] ?? null;
            if ($old !== null && ($old['pursuit_id'] !== $pursuit->value || $old['student_id'] !== $student->value)) {
                throw new CorruptHistoryException();
            }
        }
        $this->sql->execute('DELETE FROM ' . $this->sql->quote('sis_student_advisors') .
            ' WHERE ' . $this->sql->quote('pursuit_id') . ' = ?', [$pursuit->value]);
        foreach ($rows as $row) {
            $this->sql->insert(
                'sis_student_advisors',
                $row + ['student_id' => $student->value, 'pursuit_id' => $pursuit->value]
            );
        }
    }

    private function eligible(): string
    {
        $q = $this->sql->quote(...);
        return 'u.' . $q('sign_in_enabled') . ' = 1 AND (EXISTS (SELECT e.' . $q('id') . ' FROM ' .
        $q('sis_employees') . ' e WHERE e.' . $q('person_id') . ' = p.' . $q('id') .
        ' AND e.' . $q('active') . ' = 1) OR EXISTS (SELECT ur.' . $q('id') . ' FROM ' .
        $q('sis_access_user_roles') . ' ur JOIN ' . $q('sis_access_roles') . ' ar ON ar.' .
        $q('id') . ' = ur.' . $q('role_id') . ' WHERE ur.' . $q('user_id') . ' = u.' .
        $q('user_id') . " AND ar." . $q('name') . " = 'faculty'))";
    }

    private function candidatesFrom(): string
    {
        $q = $this->sql->quote(...);
        return $q('sis_persons') . ' p JOIN ' . $q('users') . ' u ON u.' . $q('user_id') . ' = p.' . $q('owner_user_id');
    }

    public function candidate(EntityId $person): bool
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch('SELECT p.' . $q('id') . ' FROM ' . $this->candidatesFrom() .
            ' WHERE p.' . $q('id') . ' = ? AND ' . $this->eligible(), [$person->value], 1) !== [];
    }

    public function candidates(string $search, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)) . '%';
        $parameters = [$like, $like];
        if ($after !== null) {
            $parameters[] = $after->value;
        }
        $rows = $this->sql->fetch('SELECT p.' . $q('id') . ', p.' . $q('given_name') . ', p.' . $q('family_name') .
            ' FROM ' . $this->candidatesFrom() . ' WHERE ' . $this->eligible() .
            ' AND (LOWER(p.' . $q('given_name') . ") LIKE ? ESCAPE '!' OR LOWER(p." . $q('family_name') .
            ") LIKE ? ESCAPE '!')" . ($after === null ? '' : ' AND p.' . $q('id') . ' > ?') .
            ' ORDER BY p.' . $q('id'), $parameters, 21);
        return ['items' => array_slice($rows, 0, 20), 'next_after' => count($rows) > 20 ? $rows[19]['id'] : null];
    }

    public function labels(array $persons): array
    {
        if ($persons === []) {
            return [];
        }
        if (count($persons) > 50) {
            throw new CorruptHistoryException();
        }
        foreach ($persons as $person) {
            new EntityId($person);
        }
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch('SELECT ' . $q('id') . ', ' . $q('given_name') . ', ' . $q('family_name') .
            ' FROM ' . $q('sis_persons') . ' WHERE ' . $q('id') . ' IN (' .
            implode(',', array_fill(0, count($persons), '?')) . ')', $persons, 50);
        return array_column($rows, null, 'id');
    }

    public function mine(EntityId $actor, string $date, string $search, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)) . '%';
        $parameters = [$actor->value, $date, $date, $like, $like, $like, $like];
        if ($after !== null) {
            $parameters[] = $after->value;
        }
        $rows = $this->sql->fetch(
            'SELECT DISTINCT a.' . $q('pursuit_id') . ', a.' . $q('student_id') .
            ' FROM ' . $q('sis_student_advisors') . ' a JOIN ' . $q('sis_persons') . ' advisor ON advisor.' .
            $q('id') . ' = a.' . $q('advisor_person_id') . ' JOIN ' . $q('sis_student_programs') .
            ' pursuit ON pursuit.' . $q('id') . ' = a.' . $q('pursuit_id') . ' JOIN ' . $q('sis_students') .
            ' student ON student.' . $q('id') . ' = a.' . $q('student_id') . ' JOIN ' . $q('sis_persons') .
            ' p ON p.' . $q('id') . ' = student.' . $q('person_id') . ' JOIN ' . $q('sis_person_identifiers') .
            ' pi ON pi.' . $q('person_id') . ' = p.' . $q('id') . ' JOIN ' . $q('sis_programs') .
            ' program ON program.' . $q('id') . ' = pursuit.' . $q('program_id') .
            ' WHERE advisor.' . $q('owner_user_id') . ' = ? AND a.' . $q('starts_on') . ' <= ? AND (a.' .
            $q('ends_on') . ' IS NULL OR a.' . $q('ends_on') . ' >= ?) AND pursuit.' . $q('status') .
            " = 'active' AND student." . $q('primary_program_id') . ' = pursuit.' . $q('program_id') .
            ' AND (LOWER(p.' . $q('given_name') . ") LIKE ? ESCAPE '!' OR LOWER(p." .
            $q('family_name') . ") LIKE ? ESCAPE '!' OR LOWER(program." . $q('code') .
            ") LIKE ? ESCAPE '!' OR LOWER(pi." . $q('institutional_id') . ") LIKE ? ESCAPE '!')" .
            ($after === null ? '' : ' AND a.' . $q('pursuit_id') . ' > ?') . ' ORDER BY a.' . $q('pursuit_id'),
            $parameters,
            21
        );
        return [
            'items' => array_slice($rows, 0, 20),
            'next_after' => count($rows) > 20 ? $rows[19]['pursuit_id'] : null
        ];
    }
}
