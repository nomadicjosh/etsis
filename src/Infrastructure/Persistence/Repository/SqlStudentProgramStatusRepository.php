<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentProgramStatusRepository;
use Domain\Student\StudentProgramStatus;
use Domain\Student\StudentProgram;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlStudentProgramStatusRepository implements StudentProgramStatusRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function forProgram(EntityId $student, EntityId $program, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_student_programs") .
                " WHERE " .
                $q("student_id") .
                " = ? AND " .
                $q("program_id") .
                " = ?" .
                ($after === null ? "" : " AND " . $q("id") . " > ?") .
                " ORDER BY " .
                $q("id"),
            $after === null
                ? [$student->value, $program->value]
                : [$student->value, $program->value, $after->value],
            50,
        );
        return array_map(
            static fn(array $r): StudentProgram => new StudentProgram(
                new EntityId($r["id"]),
                new EntityId($r["student_id"]),
                new EntityId($r["program_id"]),
                new EntityId($r["entry_term_id"]),
                $r["status"],
            ),
            $rows,
        );
    }
    public function marker(EntityId $assignment): ?array
    {
        $r = $this->sql->rows("sis_program_status_streams", ["id" => $assignment->value], 1)[0] ?? null;
        if ($r === null) {
            return null;
        }
        if (
            count($r) !== 4 ||
            (!is_int($r["stream_version"]) &&
                (!is_string($r["stream_version"]) ||
                    preg_match('/^[1-9][0-9]*$/D', $r["stream_version"]) !== 1))
        ) {
            throw new CorruptHistoryException();
        }
        return [
            "id" => $r["id"],
            "student_id" => $r["student_id"],
            "stream_version" => (int) $r["stream_version"],
            "last_decision_id" => $r["last_decision_id"],
        ];
    }
    public function project(StudentProgramStatus $root, string $before, int $expected): void
    {
        $f = $root->facts() ?? throw new CorruptHistoryException();
        $m = [
            "id" => $f["id"],
            "student_id" => $f["student_id"],
            "stream_version" => $root->playhead(),
            "last_decision_id" => $root->decision() ?? throw new CorruptHistoryException(),
        ];
        if ($expected === 0) {
            $this->sql->insert("sis_program_status_streams", $m);
        } elseif (
            $this->sql->update("sis_program_status_streams", $m, [
                "id" => $f["id"],
                "student_id" => $f["student_id"],
                "stream_version" => $expected,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException("The program history changed. Review it again.");
        }
        if (
            $this->sql->update(
                "sis_student_programs",
                ["status" => $f["status"]],
                [
                    "id" => $f["id"],
                    "student_id" => $f["student_id"],
                    "program_id" => $f["program_id"],
                    "entry_term_id" => $f["entry_term_id"],
                    "status" => $before,
                ],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("The program assignment changed. Review it again.");
        }
    }
}
