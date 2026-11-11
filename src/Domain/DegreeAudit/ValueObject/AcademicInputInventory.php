<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\ValueObject\EntityId;

/** Bounded discovery from surviving current/source/history indexes, not another source of academic truth. */
final readonly class AcademicInputInventory
{
    public const int LIMIT = 200;
    /** @var list<array<string,mixed>> */
    public array $sources;

    /** @param array<string,list<array<string,mixed>>> $rows */
    public function __construct(array $rows)
    {
        $kinds = ["credits", "academic", "registration", "enrollments", "transfer", "awards"];
        if (count($rows) !== count($kinds) || array_diff(array_keys($rows), $kinds) !== []) {
            throw new CorruptHistoryException();
        }
        $sources = [];
        foreach ($rows as $kind => $items) {
            if (count($items) > self::LIMIT) {
                throw new HistoryReadLimitException("This verification exceeds its record limit.");
            }
            foreach ($items as $row) {
                if ($kind === "credits") {
                    $origin = $row["credit_type"] ?? null;
                    if (!in_array($origin, ["institutional", "transfer"], true)) {
                        throw new CorruptHistoryException();
                    }
                    $this->merge($sources, $origin, self::id($row, "source_id"), [
                        "credit_id" => self::id($row, "id"),
                        "credit_seen" => true,
                        "term_id" => self::id($row, "term_id"),
                    ]);
                } elseif ($kind === "academic") {
                    $this->merge($sources, "institutional", self::id($row, "enrollment_id"), [
                        "credit_id" => self::id($row, "id"),
                        "academic_seen" => true,
                        "section_id" => self::id($row, "section_id"),
                    ]);
                } elseif (in_array($kind, ["registration", "enrollments"], true)) {
                    $this->merge($sources, "institutional", self::id($row, "id"), [
                        $kind === "registration" ? "registration_seen" : "source_seen" => true,
                        "section_id" => self::id($row, "section_id"),
                        "term_id" => self::id($row, "term_id"),
                    ]);
                } else {
                    $data = [
                        "credit_id" => self::id($row, "credit_id"),
                        $kind === "transfer" ? "academic_seen" : "source_seen" => true,
                    ];
                    if ($kind === "awards") {
                        $data["term_id"] = self::id($row, "term_id");
                    }
                    $this->merge($sources, "transfer", self::id($row, "id"), $data);
                }
            }
        }
        ksort($sources, SORT_STRING);
        $this->sources = array_values($sources);
    }

    /** @param array<string,array<string,mixed>> $sources
     * @param array<string,mixed> $data
     */
    private function merge(array &$sources, string $origin, string $id, array $data): void
    {
        $key = $origin . ":" . $id;
        $row = $sources[$key] ?? [
            "origin" => $origin,
            "id" => $id,
            "credit_id" => null,
            "section_id" => null,
            "term_id" => null,
            "credit_seen" => false,
            "academic_seen" => false,
            "registration_seen" => false,
            "source_seen" => false,
        ];
        foreach ($data as $field => $value) {
            if (
                ($value === true && $row[$field] === true) ||
                ($value !== true && $row[$field] !== null && $row[$field] !== $value)
            ) {
                throw new CorruptHistoryException();
            }
            $row[$field] = $value;
        }
        $sources[$key] = $row;
        if (count($sources) > self::LIMIT) {
            throw new HistoryReadLimitException("This verification exceeds its record limit.");
        }
    }
    /** @param array<string,mixed> $row */
    private static function id(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new CorruptHistoryException();
        }
        new EntityId($value);
        return $value;
    }
}
