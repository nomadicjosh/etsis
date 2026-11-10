<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Event\StudentProgramWasObserved;
use Domain\Student\Event\StudentProgramStatusWasChanged;
use Domain\Student\Event\StudentProgramWasAssigned;
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
        $fields = match ($class) {
            StudentProgramWasAssigned::class => ["decision_id", "assignment", "source"],
            StudentProgramWasObserved::class => ["decision_id", "assignment"],
            StudentProgramStatusWasChanged::class => ["decision_id", "previous_decision_id", "status"],
            default => throw new CorruptHistoryException(),
        };
        if (count($p) !== count($fields) || array_diff(array_keys($p), $fields) !== []) {
            throw new CorruptHistoryException();
        }
        self::id($p["decision_id"]);
        if ($class === StudentProgramStatusWasChanged::class) {
            self::id($p["previous_decision_id"]);
            self::status($p["status"]);
            if ($p["decision_id"] === $p["previous_decision_id"]) {
                throw new CorruptHistoryException();
            }
            return;
        }
        $a = $p["assignment"];
        if (
            !is_array($a) ||
            count($a) !== 5 ||
            array_diff(array_keys($a), ["id", "student_id", "program_id", "entry_term_id", "status"]) !== []
        ) {
            throw new CorruptHistoryException();
        }
        foreach (["id", "student_id", "program_id", "entry_term_id"] as $key) {
            self::id($a[$key]);
        }
        self::status($a["status"]);
        if ($class === StudentProgramWasAssigned::class) {
            if ($a["status"] !== "active" || $p["decision_id"] !== $a["id"]) {
                throw new CorruptHistoryException();
            }
            self::source($p["source"]);
        }
    }
    /** @param mixed $source */
    public static function source(mixed $source): void
    {
        if (
            !is_array($source) ||
            count($source) !== 4 ||
            array_diff(array_keys($source), [
                "program_code",
                "program_title",
                "entry_term_code",
                "cohort",
            ]) !== []
        ) {
            throw new CorruptHistoryException();
        }
        foreach (["program_code", "program_title", "entry_term_code"] as $key) {
            self::text($source[$key]);
        }
        $c = $source["cohort"];
        if ($c === null) {
            return;
        }
        if (
            !is_array($c) ||
            count($c) !== 4 ||
            array_diff(array_keys($c), ["catalog_id", "year_id", "year_code", "revision"]) !== [] ||
            !is_int($c["revision"]) ||
            $c["revision"] < 1
        ) {
            throw new CorruptHistoryException();
        }
        self::id($c["catalog_id"]);
        self::id($c["year_id"]);
        self::text($c["year_code"]);
    }
    private static function text(mixed $v): void
    {
        if (
            !is_string($v) ||
            !mb_check_encoding($v, "UTF-8") ||
            trim($v) === "" ||
            mb_strlen($v) > 500 ||
            preg_match('/[\x00-\x1F\x7F]/', $v)
        ) {
            throw new CorruptHistoryException();
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
