<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Retained assessment evidence; the public summary omits account and comparison inventories. */
final readonly class FacultyConflictAssessment
{
    public function __construct(public array $data)
    {
        $this->keys($data, [
            "mode",
            "revision",
            "faculty_ids",
            "unassigned_meetings",
            "sources",
            "pairs",
            "conflicts",
        ]);
        if (
            !in_array($data["mode"], ["block", "warn"], true) ||
            !is_int($data["revision"]) ||
            $data["revision"] < 0 ||
            $data["revision"] > 2000000000 ||
            !is_int($data["unassigned_meetings"]) ||
            $data["unassigned_meetings"] < 0 ||
            $data["unassigned_meetings"] > 500 ||
            !is_int($data["pairs"]) ||
            $data["pairs"] < 0 ||
            $data["pairs"] > 1000000
        ) {
            throw new InvalidArgumentException("Invalid faculty conflict assessment.");
        }
        foreach (["faculty_ids" => 20, "sources" => 50, "conflicts" => 20] as $key => $limit) {
            if (!is_array($data[$key]) || !array_is_list($data[$key]) || count($data[$key]) > $limit) {
                throw new InvalidArgumentException("Keep faculty assessment evidence bounded.");
            }
        }
        $ids = $data["faculty_ids"];
        sort($ids, SORT_STRING);
        if ($ids !== array_values(array_unique($data["faculty_ids"]))) {
            throw new InvalidArgumentException("Use distinct faculty identities in canonical order.");
        }
        foreach ($ids as $id) {
            if (!is_string($id)) {
                throw new InvalidArgumentException("Use faculty account identities.");
            }
            new EntityId($id);
        }
        $previous = null;
        foreach ($data["sources"] as $source) {
            if (!is_array($source)) {
                throw new InvalidArgumentException("Use complete compared plan references.");
            }
            $this->keys($source, ["section_id", "publication_id", "version", "definition_hash"]);
            new EntityId($source["section_id"]);
            new EntityId($source["publication_id"]);
            if (
                !is_int($source["version"]) ||
                $source["version"] < 1 ||
                !is_string($source["definition_hash"]) ||
                preg_match('/^[a-f0-9]{64}$/D', $source["definition_hash"]) !== 1 ||
                ($previous !== null && strcmp($previous, $source["section_id"]) >= 0)
            ) {
                throw new InvalidArgumentException("Use exact compared plan versions.");
            }
            $previous = $source["section_id"];
        }
        if (
            count($data["conflicts"]) !== min(20, $data["pairs"]) ||
            ($ids === [] && ($data["sources"] !== [] || $data["pairs"] !== 0))
        ) {
            throw new InvalidArgumentException("Keep conflict counts consistent with their evidence.");
        }
        foreach ($data["conflicts"] as $conflict) {
            if (!is_array($conflict)) {
                throw new InvalidArgumentException("Use complete faculty overlaps.");
            }
            $this->keys($conflict, [
                "section_id",
                "section_code",
                "term_code",
                "own_occurrence_id",
                "other_occurrence_id",
                "starts_at",
                "ends_at",
            ]);
            foreach (["section_id", "own_occurrence_id", "other_occurrence_id"] as $id) {
                new EntityId($conflict[$id]);
            }
            foreach (["section_code", "term_code"] as $label) {
                if (
                    !is_string($conflict[$label]) ||
                    trim($conflict[$label]) === "" ||
                    mb_strlen($conflict[$label]) > 80 ||
                    preg_match('/[\x00-\x1f\x7f]/', $conflict[$label])
                ) {
                    throw new InvalidArgumentException("Use printable section labels.");
                }
            }
            foreach (["starts_at", "ends_at"] as $field) {
                $value = $conflict[$field];
                $date = is_string($value)
                ? \DateTimeImmutable::createFromFormat(
                    "!Y-m-d\TH:i:s\Z",
                    $value,
                    new \DateTimeZone("UTC"),
                )
                : false;
                if (!$date || $date->format("Y-m-d\TH:i:s\Z") !== $value) {
                    throw new InvalidArgumentException("Use UTC conflict times.");
                }
            }
            if (
                $conflict["starts_at"] >= $conflict["ends_at"] ||
                !in_array($conflict["section_id"], array_column($data["sources"], "section_id"), true)
            ) {
                throw new InvalidArgumentException("Use an overlap from a compared plan.");
            }
        }
    }

    public function fingerprint(): string
    {
        $data = $this->data;
        foreach (["sources", "conflicts"] as $key) {
            foreach ($data[$key] as &$row) {
                ksort($row, SORT_STRING);
            }
            unset($row);
        }
        ksort($data, SORT_STRING);
        return hash("sha256", json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function blocked(): bool
    {
        return $this->data["mode"] === "block" && $this->data["pairs"] > 0;
    }

    public function summary(): array
    {
        return [
            "mode" => $this->data["mode"],
            "revision" => $this->data["revision"],
            "status" =>
                $this->data["pairs"] > 0
                    ? "conflict"
                    : ($this->data["faculty_ids"] !== []
                        ? "clear"
                        : ($this->data["unassigned_meetings"] > 0
                            ? "unassigned"
                            : "not_needed")),
            "checked_faculty" => count($this->data["faculty_ids"]),
            "compared_sections" => count($this->data["sources"]),
            "unassigned_meetings" => $this->data["unassigned_meetings"],
            "pairs" => $this->data["pairs"],
            "blocked" => $this->blocked(),
            "conflicts" => array_map(
                static fn(array $row): array => [
                    "section_code" => $row["section_code"],
                    "term_code" => $row["term_code"],
                    "starts_at" => $row["starts_at"],
                    "ends_at" => $row["ends_at"],
                ],
                $this->data["conflicts"],
            ),
        ];
    }

    private function keys(array $data, array $expected): void
    {
        if (count($data) !== count($expected) || array_diff(array_keys($data), $expected) !== []) {
            throw new InvalidArgumentException("Use complete faculty assessment facts.");
        }
    }
}
