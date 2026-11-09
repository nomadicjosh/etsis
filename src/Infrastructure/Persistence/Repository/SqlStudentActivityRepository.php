<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentActivityRepository;
use Domain\Student\StudentActivity;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlStudentActivityRepository implements StudentActivityRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function current(EntityId $student): array
    {
        $r = $this->sql->one("sis_students", ["id" => $student->value]);
        if (!in_array($r["active"], [0, 1, "0", "1"], true)) {
            throw new CorruptHistoryException();
        }
        return ["person_id" => $r["person_id"], "active" => (bool) $r["active"]];
    }
    public function marker(EntityId $student): ?array
    {
        $row = $this->sql->rows("sis_student_activity", ["id" => $student->value], 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        unset($row["id"]);
        if (
            count($row) !== 4 ||
            (!is_int($row["stream_version"]) &&
                (!is_string($row["stream_version"]) ||
                    preg_match('/^[1-9][0-9]*$/D', $row["stream_version"]) !== 1))
        ) {
            throw new CorruptHistoryException();
        }
        return [
            "stream_version" => (int) $row["stream_version"],
            "last_decision_id" => $row["last_decision_id"],
            "current_decision_id" => $row["current_decision_id"],
            "effective_on" => $row["effective_on"],
        ];
    }
    public function project(EntityId $student, StudentActivity $root, bool $before, int $expected): void
    {
        if ($root->active() === null || $root->person() === null || $root->playhead() <= $expected) {
            throw new CorruptHistoryException();
        }
        $marker = $root->marker();
        if ($expected === 0) {
            $this->sql->insert("sis_student_activity", ["id" => $student->value] + $marker);
        } elseif (
            $this->sql->update("sis_student_activity", $marker, [
                "id" => $student->value,
                "stream_version" => $expected,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException("The activity history changed. Review it again.");
        }
        // Avoid relying on driver-specific affected-row behavior for an unchanged current flag.
        if (
            $before !== $root->active() &&
            $this->sql->update(
                "sis_students",
                ["active" => (int) $root->active()],
                ["id" => $student->value, "person_id" => $root->person(), "active" => (int) $before],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("The student activity changed. Review it again.");
        }
    }
}
