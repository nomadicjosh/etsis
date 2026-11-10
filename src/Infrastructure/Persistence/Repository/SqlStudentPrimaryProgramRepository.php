<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentPrimaryProgramRepository;
use Domain\Student\Student;
use Domain\Student\StudentPrimaryProgram;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlStudentPrimaryProgramRepository implements StudentPrimaryProgramRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function marker(EntityId $student): ?array
    {
        $r = $this->sql->rows("sis_primary_program_streams", ["id" => $student->value], 1)[0] ?? null;
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
            "assignment_id" => $r["assignment_id"],
            "stream_version" => (int) $r["stream_version"],
            "last_decision_id" => $r["last_decision_id"],
        ];
    }
    public function track(StudentPrimaryProgram $root): void
    {
        if ($root->playhead() !== 1 || $root->earlierHistoryUnknown()) {
            throw new CorruptHistoryException();
        }
        $s = $root->selection() ?? throw new CorruptHistoryException();
        $this->sql->insert("sis_primary_program_streams", [
            "id" => (string) $root->aggregateId(),
            "assignment_id" => $s["assignment_id"],
            "stream_version" => 1,
            "last_decision_id" => $root->decision() ?? throw new CorruptHistoryException(),
        ]);
    }
    public function project(StudentPrimaryProgram $root, Student $before, int $expected): void
    {
        $s = $root->selection() ?? throw new CorruptHistoryException();
        $id = (string) $root->aggregateId();
        if (
            $id !== $before->id->value ||
            $root->person() !== $before->personId->value ||
            $root->entryTerm() !== $before->entryTermId->value ||
            $root->playhead() <= $expected
        ) {
            throw new CorruptHistoryException();
        }
        $m = [
            "id" => $id,
            "assignment_id" => $s["assignment_id"],
            "stream_version" => $root->playhead(),
            "last_decision_id" => $root->decision() ?? throw new CorruptHistoryException(),
        ];
        if ($expected === 0) {
            $this->sql->insert("sis_primary_program_streams", $m);
        } elseif (
            $this->sql->update("sis_primary_program_streams", $m, [
                "id" => $id,
                "stream_version" => $expected,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException(
                "The primary selection history changed. Review it again.",
            );
        }
        if (
            $this->sql->update(
                "sis_students",
                ["primary_program_id" => $s["program_id"]],
                [
                    "id" => $id,
                    "person_id" => $before->personId->value,
                    "entry_term_id" => $before->entryTermId->value,
                    "primary_program_id" => $before->programId->value,
                ],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("The primary program changed. Review it again.");
        }
    }
}
