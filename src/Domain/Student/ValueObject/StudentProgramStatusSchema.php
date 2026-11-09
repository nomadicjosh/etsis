<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Event\StudentProgramWasObserved;
use Domain\Student\Event\StudentProgramStatusWasChanged;
use Domain\Student\StudentProgram;

/** Frozen v1 reader; observations describe current facts, never an invented earlier assignment. */
final class StudentProgramStatusSchema
{
    /** @return array{id:string,student_id:string,program_id:string,entry_term_id:string,status:string} */
    public static function facts(StudentProgram $p): array
    {
        return [
            "id" => $p->id->value,
            "student_id" => $p->studentId->value,
            "program_id" => $p->programId->value,
            "entry_term_id" => $p->entryTermId->value,
            "status" => $p->status,
        ];
    }
    /** @param array<string,mixed> $p */
    public static function validate(string $class, array $p): void
    {
        $observed = $class === StudentProgramWasObserved::class;
        $fields = $observed
        ? ["decision_id", "assignment"]
        : ["decision_id", "previous_decision_id", "status"];
        if (
            (!$observed && $class !== StudentProgramStatusWasChanged::class) ||
            count($p) !== count($fields) ||
            array_diff(array_keys($p), $fields) !== []
        ) {
            throw new CorruptHistoryException();
        }
        self::id($p["decision_id"]);
        if ($observed) {
            $a = $p["assignment"];
            if (
                !is_array($a) ||
                count($a) !== 5 ||
                array_diff(array_keys($a), ["id", "student_id", "program_id", "entry_term_id", "status"]) !==
                    []
            ) {
                throw new CorruptHistoryException();
            }
            foreach (["id", "student_id", "program_id", "entry_term_id"] as $key) {
                self::id($a[$key]);
            }
            self::status($a["status"]);
        } else {
            self::id($p["previous_decision_id"]);
            self::status($p["status"]);
            if ($p["decision_id"] === $p["previous_decision_id"]) {
                throw new CorruptHistoryException();
            }
        }
    }
    private static function id(mixed $v): void
    {
        if (!is_string($v)) {
            throw new CorruptHistoryException();
        }
        new EntityId($v);
    }
    private static function status(mixed $v): void
    {
        if (!in_array($v, ["active", "closed"], true)) {
            throw new CorruptHistoryException();
        }
    }
}
