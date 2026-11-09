<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Registration\ValueObject\EnrollmentFacts;
use Domain\Registration\Repository\EnrollmentHistoryRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use PDOException;

final readonly class SqlEnrollmentHistoryRepository implements EnrollmentHistoryRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function mapping(EntityId $id): ?array
    {
        $r = $this->sql->rows("sis_registration_streams", ["id" => $id->value], 1)[0] ?? null;
        if ($r !== null) {
            $r["last_sequence"] = $this->integer($r["last_sequence"]);
        }
        return $r;
    }
    public function current(EntityId $id): ?array
    {
        $r = $this->sql->rows("sis_enrollments", ["id" => $id->value], 1)[0] ?? null;
        if ($r === null) {
            return null;
        }
        $r["version"] = $this->integer($r["version"]);
        foreach (["registered_at", "status_at"] as $key) {
            if (!is_string($r[$key])) {
                throw new CorruptHistoryException();
            }
            $r[$key] = EnrollmentFacts::utc(new \DateTimeImmutable($r[$key], new \DateTimeZone("UTC")));
        }
        return new EnrollmentFacts(array_intersect_key($r, array_flip(EnrollmentFacts::FIELDS)))->facts;
    }
    public function decisions(EntityId $enrollment): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_registration_decisions") .
                " WHERE " .
                $q("enrollment_id") .
                " = ? ORDER BY " .
                $q("stream_version"),
            [$enrollment->value],
            3,
        );
        return array_map($this->decision(...), $rows);
    }
    public function latest(EntityId $section): ?array
    {
        $q = $this->sql->quote(...);
        $r =
        $this->sql->fetch(
            "SELECT * FROM " .
                    $q("sis_registration_decisions") .
                    " WHERE " .
                    $q("section_id") .
                    " = ? ORDER BY " .
                    $q("sequence") .
                    " DESC",
            [$section->value],
            1,
        )[0] ?? null;
        return $r === null ? null : $this->decision($r);
    }
    public function maximumSequence(EntityId $section): int
    {
        $q = $this->sql->quote(...);
        return $this->integer(
            $this->sql->fetch(
                "SELECT MAX(" .
                    $q("last_sequence") .
                    ") AS " .
                    $q("highest") .
                    " FROM " .
                    $q("sis_registration_streams") .
                    " WHERE " .
                    $q("section_id") .
                    " = ?",
                [$section->value],
                1,
            )[0]["highest"] ?? 0,
        );
    }
    public function usedSeats(EntityId $section): int
    {
        $q = $this->sql->quote(...);
        return $this->integer(
            $this->sql->fetch(
                "SELECT COUNT(*) AS " .
                    $q("amount") .
                    " FROM " .
                    $q("sis_enrollments") .
                    " WHERE " .
                    $q("section_id") .
                    " = ? AND " .
                    $q("status") .
                    " IN (?, ?)",
                [$section->value, "A", "N"],
                1,
            )[0]["amount"],
        );
    }
    public function termCode(EntityId $term): string
    {
        return $this->sql->one("sis_terms", ["id" => $term->value])["code"];
    }
    public function project(array $facts, int $sequence, ?int $expectedVersion): void
    {
        new EnrollmentFacts($facts);
        $id = new EntityId($facts["id"]);
        $m = $this->mapping($id);
        $routing = [
            "id" => $facts["id"],
            "student_id" => $facts["student_id"],
            "section_id" => $facts["section_id"],
            "term_id" => $facts["term_id"],
            "last_sequence" => $sequence,
        ];
        if ($m === null) {
            $this->sql->insert("sis_registration_streams", $routing);
        } else {
            foreach (["id", "student_id", "section_id", "term_id"] as $key) {
                if ($m[$key] !== $routing[$key]) {
                    throw new CorruptHistoryException();
                }
            }
            if (
                $this->sql->update(
                    "sis_registration_streams",
                    ["last_sequence" => $sequence],
                    ["id" => $id->value, "last_sequence" => $m["last_sequence"]],
                ) !== 1
            ) {
                throw new ConcurrentModificationException("The registration history changed.");
            }
        }
        if ($facts["status"] === "D") {
            if ($expectedVersion === null) {
                if ($this->current($id) !== null) {
                    throw new ConcurrentModificationException("The enrollment already exists.");
                }
                return;
            }
            $q = $this->sql->quote(...);
            if (
                $this->sql->execute(
                    "DELETE FROM " .
                        $q("sis_enrollments") .
                        " WHERE " .
                        $q("id") .
                        " = ? AND " .
                        $q("version") .
                        " = ?",
                    [$id->value, $expectedVersion],
                ) !== 1
            ) {
                throw new ConcurrentModificationException("The enrollment changed.");
            }
        } elseif ($expectedVersion === null) {
            $this->sql->insert("sis_enrollments", $facts);
        } else {
            unset($facts["id"]);
            if (
                $this->sql->update("sis_enrollments", $facts, [
                    "id" => $id->value,
                    "version" => $expectedVersion,
                ]) !== 1
            ) {
                throw new ConcurrentModificationException("The enrollment changed.");
            }
        }
    }
    public function index(array $decision): void
    {
        try {
            $this->sql->insert("sis_registration_decisions", $decision);
        } catch (PDOException $e) {
            if (in_array((string) $e->getCode(), ["23000", "23505"], true)) {
                throw new ConcurrentModificationException(
                    "Another registration decision used this section order.",
                );
            }
            throw $e;
        }
    }
    public function forStudent(EntityId $student, ?EntityId $after, int $limit): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_registration_streams") .
                " WHERE " .
                $q("student_id") .
                " = ?" .
                ($after === null ? "" : " AND " . $q("id") . " > ?") .
                " ORDER BY " .
                $q("id"),
            $after === null ? [$student->value] : [$student->value, $after->value],
            $limit,
        );
    }
    /** @param array<string,mixed> $r
     * @return array<string,mixed> */
    private function decision(array $r): array
    {
        foreach (["sequence", "stream_version"] as $key) {
            $r[$key] = $this->integer($r[$key]);
        }
        return $r;
    }
    private function integer(mixed $v): int
    {
        if (!is_int($v) && (!is_string($v) || preg_match('/^(0|[1-9][0-9]*)$/D', $v) !== 1)) {
            throw new CorruptHistoryException();
        }
        return (int) $v;
    }
}
