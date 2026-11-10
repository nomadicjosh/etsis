<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Event\PrimaryProgramWasObserved;
use Domain\Student\Event\PrimaryProgramWasSelected;

/** Frozen v1 contract; labels are what staff saw, not a dependency on today's catalog text. */
final class PrimaryProgramSchema
{
    /** @param array<string,mixed> $p */
    public static function validate(string $class, array $p): void
    {
        $started = $class === \Domain\Student\Event\PrimaryProgramWasEstablished::class;
        $observed = $class === PrimaryProgramWasObserved::class || $started;
        $fields = $observed
        ? ["decision_id", "student_id", "person_id", "institutional_entry_term_id", "selection"]
        : ["decision_id", "previous_decision_id", "selection"];
        if ($started) {
            $fields[] = "application_id";
            self::id($p["application_id"] ?? null);
        }
        if (
            (!$observed && $class !== PrimaryProgramWasSelected::class) ||
            count($p) !== count($fields) ||
            array_diff(array_keys($p), $fields) !== []
        ) {
            throw new CorruptHistoryException();
        }
        self::id($p["decision_id"]);
        if ($observed) {
            foreach (["student_id", "person_id", "institutional_entry_term_id"] as $key) {
                self::id($p[$key]);
            }
        } else {
            self::id($p["previous_decision_id"]);
            if ($p["decision_id"] === $p["previous_decision_id"]) {
                throw new CorruptHistoryException();
            }
        }
        self::selection($p["selection"]);
        if ($started && $p["selection"]["entry_term_id"] !== $p["institutional_entry_term_id"]) {
            throw new CorruptHistoryException();
        }
    }
    public static function selection(mixed $s): void
    {
        if (
            !is_array($s) ||
            count($s) !== 6 ||
            array_diff(array_keys($s), [
                "assignment_id",
                "program_id",
                "entry_term_id",
                "program_code",
                "program_title",
                "entry_term_code",
            ]) !== []
        ) {
            throw new CorruptHistoryException();
        }
        foreach (["assignment_id", "program_id", "entry_term_id"] as $key) {
            self::id($s[$key]);
        }
        foreach (["program_code", "program_title", "entry_term_code"] as $key) {
            $v = $s[$key];
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
    }
    /** Keep command results and replayed receipts identical despite canonical JSON key ordering.
     * @param array<string,mixed> $s
     * @return array<string,mixed> */
    public static function normalizedSelection(array $s): array
    {
        self::selection($s);
        return [
            "assignment_id" => $s["assignment_id"],
            "program_id" => $s["program_id"],
            "entry_term_id" => $s["entry_term_id"],
            "program_code" => $s["program_code"],
            "program_title" => $s["program_title"],
            "entry_term_code" => $s["entry_term_code"],
        ];
    }
    private static function id(mixed $v): void
    {
        if (!is_string($v)) {
            throw new CorruptHistoryException();
        }
        new EntityId($v);
    }
}
