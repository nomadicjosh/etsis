<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use DateTimeImmutable;
use Domain\Gradebook\Assignment;
use Domain\Gradebook\AssignmentScore;
use Domain\Gradebook\GradeDefinition;
use Domain\Gradebook\Repository\GradebookRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlGradebookRepository implements GradebookRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function addGrade(GradeDefinition $grade): void
    {
        $this->sql->insert('sis_grades', ['id' => $grade->id->value, 'code' => $grade->code,
            'points_milli' => $grade->pointsMilli, 'passes' => (int) $grade->passes,
                'counts_gpa' => (int) $grade->countsGpa]);
    }

    public function grade(EntityId $id): GradeDefinition
    {
        $r = $this->sql->one('sis_grades', ['id' => $id->value]);

        return new GradeDefinition(
            $id,
            $r['code'],
            (int) $r['points_milli'],
            (bool) $r['passes'],
            (bool) $r['counts_gpa']
        );
    }

    public function addAssignment(Assignment $assignment): void
    {
        $this->sql->insert('sis_assignments', ['id' => $assignment->id->value,
            'section_id' => $assignment->sectionId->value,
            'title' => $assignment->title, 'possible_hundredths' => $assignment->possibleHundredths,
            'due_on' => $assignment->dueOn->format('Y-m-d')]);
    }

    public function assignment(EntityId $id): Assignment
    {
        $r = $this->sql->one('sis_assignments', ['id' => $id->value]);

        return new Assignment(
            $id,
            new EntityId($r['section_id']),
            $r['title'],
            (int) $r['possible_hundredths'],
            new DateTimeImmutable($r['due_on'])
        );
    }

    public function saveAssignment(Assignment $assignment): void
    {
        $this->sql->update(
            'sis_assignments',
            ['title' => $assignment->title,
            'possible_hundredths' => $assignment->possibleHundredths, 'due_on' => $assignment->dueOn->format('Y-m-d')],
            ['id' => $assignment->id->value, 'section_id' => $assignment->sectionId->value]
        );
    }

    public function highestScore(EntityId $assignment): ?int
    {
        $q = $this->sql->quote(...);
        $row = $this->sql->fetch('SELECT MAX(' . $q('earned_hundredths') . ') AS ' . $q('maximum')
            . ' FROM ' . $q('sis_scores') . ' WHERE ' . $q('assignment_id') . ' = ?', [$assignment->value])[0];

        return $row['maximum'] === null ? null : (int) $row['maximum'];
    }

    public function attendanceRecord(EntityId $section, EntityId $student, string $on): ?array
    {
        return $this->sql->rows('sis_attendance', ['section_id' => $section->value,
            'student_id' => $student->value, 'held_on' => $on], 1)[0] ?? null;
    }

    public function attendancePage(EntityId $section, EntityId $student, ?EntityId $after, int $limit): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            'SELECT ' . $q('id') . ', ' . $q('held_on') . ', ' . $q('status') . ' FROM '
            . $q('sis_attendance') . ' WHERE ' . $q('section_id') . ' = ? AND ' . $q('student_id') . ' = ?'
            . ($after === null ? '' : ' AND ' . $q('id') . ' > ?') . ' ORDER BY ' . $q('id'),
            $after === null ? [$section->value, $student->value] : [$section->value, $student->value, $after->value],
            $limit
        );
    }

    public function attendanceCounts(EntityId $section, EntityId $student): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch('SELECT ' . $q('status') . ', COUNT(*) AS ' . $q('total') . ' FROM '
            . $q('sis_attendance') . ' WHERE ' . $q('section_id') . ' = ? AND ' . $q('student_id') . ' = ? GROUP BY '
            . $q('status'), [$section->value, $student->value]);
        $counts = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public function score(AssignmentScore $score, int $expectedVersion): void
    {
        $criteria = ['assignment_id' => $score->assignmentId->value, 'student_id' => $score->studentId->value];
        $existing = $this->sql->rows('sis_scores', $criteria);
        if ($existing === [] && $expectedVersion === 0) {
            $this->sql->insert('sis_scores', ['id' => EntityId::generate()->value] + $criteria
                + ['earned_hundredths' => $score->earnedHundredths, 'version' => 1]);
        } elseif (
            $this->sql->update('sis_scores', ['earned_hundredths' => $score->earnedHundredths,
            'version' => $expectedVersion + 1], $criteria + ['version' => $expectedVersion]) !== 1
        ) {
            throw new ConcurrentModificationException('The assignment score changed.');
        }
    }

    public function finalGrade(EntityId $enrollment, GradeDefinition $grade, int $expectedVersion): void
    {
        $credit = $this->sql->one('sis_academic_credits', ['source_id' => $enrollment->value]);
        $attempted = (int) $credit['attempted_milli'];
        if (
            $this->sql->update('sis_academic_credits', ['grade_id' => $grade->id->value,
            'completed_milli' => $grade->passes ? $attempted : 0,
            'grade_points_milli' => $grade->countsGpa ? intdiv($attempted * $grade->pointsMilli, 1000) : 0,
            'version' => $expectedVersion + 1], ['id' => $credit['id'], 'version' => $expectedVersion]) !== 1
        ) {
            throw new ConcurrentModificationException('The final grade changed.');
        }
    }

    public function attendance(EntityId $section, EntityId $student, string $on, string $status): void
    {
        $criteria = ['section_id' => $section->value, 'student_id' => $student->value, 'held_on' => $on];
        if ($this->sql->rows('sis_attendance', $criteria) === []) {
            $this->sql->insert(
                'sis_attendance',
                ['id' => EntityId::generate()->value] + $criteria + ['status' => $status]
            );
        } else {
            $this->sql->update('sis_attendance', ['status' => $status], $criteria);
        }
    }

    public function scores(EntityId $student, EntityId $section, ?EntityId $after = null, ?int $limit = null): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            'SELECT a.' . $q('id') . ' AS ' . $q('assignment_id') . ', a.' . $q('title')
            . ', a.' . $q('possible_hundredths') . ', a.' . $q('due_on') . ', s.' . $q('earned_hundredths')
            . ', s.' . $q('version') . ' FROM ' . $q('sis_assignments') . ' a LEFT JOIN ' . $q('sis_scores')
            . ' s ON s.' . $q('assignment_id') . ' = a.' . $q('id') . ' AND s.' . $q('student_id') . ' = ?'
            . ' WHERE a.' . $q('section_id') . ' = ?'
            . ($after === null ? '' : ' AND a.' . $q('id') . ' > ?') . ' ORDER BY a.' . $q('id'),
            $after === null ? [$student->value, $section->value] : [$student->value, $section->value, $after->value],
            $limit
        );
        foreach ($rows as &$row) {
            $row['possible_hundredths'] = (int) $row['possible_hundredths'];
            $row['earned_hundredths'] = $row['earned_hundredths'] === null ? null : (int) $row['earned_hundredths'];
            $row['version'] = (int) ($row['version'] ?? 0);
        }
        unset($row);

        return $rows;
    }
}
