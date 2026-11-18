<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\AcademicRecord\Repository\GradeChangeRepository;
use Domain\AcademicRecord\ValueObject\GradeChangeSchema;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlGradeChangeRepository implements GradeChangeRepository
{
    public function __construct(private SqlRecords $sql, private MessageCipher $cipher)
    {
    }
    public function policy(bool $current = false): array
    {
        // Confirmations need a current locking read after replay, including under repeatable-read isolation.
        $row = $current
        ? $this->sql->current('sis_grade_change_policy', self::POLICY_ID)
        : $this->sql->one("sis_grade_change_policy", ["id" => self::POLICY_ID]);
        if ($row === null) {
            throw new CorruptHistoryException();
        }
        $revision = filter_var($row["revision"], FILTER_VALIDATE_INT);
        if ($revision === false) {
            throw new CorruptHistoryException();
        }
        $policy = ["mode" => $row["mode"], "revision" => $revision];
        GradeChangeSchema::policy($policy);
        return $policy;
    }
    public function lockPolicy(): void
    {
        $this->sql->lock("sis_grade_change_policy", self::POLICY_ID, "id");
    }
    public function savePolicy(string $mode, int $revision): void
    {
        if (!in_array($mode, ["direct", "approval"], true) || $revision < 0 || $revision >= 2000000000) {
            throw new ValidationException([
                ["field" => "mode", "message" => "Choose direct corrections or second-person approval."],
            ]);
        }
        if (
            $this->sql->update(
                "sis_grade_change_policy",
                ["mode" => $mode, "revision" => $revision + 1],
                ["id" => self::POLICY_ID, "revision" => $revision],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("Reload grade-change policy before saving.");
        }
    }
    public function request(EntityId $id): array
    {
        $q = $this->sql->quote(...);
        $row =
        $this->sql->fetch($this->selection() . " WHERE r." . $q("id") . " = ?", [$id->value], 1)[0] ??
        throw new \Domain\Shared\Exception\RecordNotFoundException("Grade correction not found.");
        return $this->decode($row);
    }
    public function pending(EntityId $credit): array
    {
        return array_map(
            $this->decode(...),
            $this->sql->rows(
                "sis_grade_change_requests",
                ["credit_id" => $credit->value, "status" => "pending"],
                2,
            ),
        );
    }
    public function page(?EntityId $after, int $limit): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            $this->selection() .
                " WHERE r." .
                $q("status") .
                " = ?" .
                ($after === null ? "" : " AND r." . $q("id") . " > ?") .
                " ORDER BY r." .
                $q("id"),
            $after === null ? ["pending"] : ["pending", $after->value],
            $limit,
        );
        return array_map($this->decode(...), $rows);
    }
    private function selection(): string
    {
        $q = $this->sql->quote(...);
        return "SELECT r.*, c." .
        $q("course_code") .
        ", c." .
        $q("course_title") .
        ", p." .
        $q("institutional_id") .
        " FROM " .
        $q("sis_grade_change_requests") .
        " r LEFT JOIN " .
        $q("sis_academic_credits") .
        " c ON c." .
        $q("id") .
        " = r." .
        $q("credit_id") .
        " LEFT JOIN " .
        $q("sis_students") .
        " s ON s." .
        $q("id") .
        " = r." .
        $q("student_id") .
        " LEFT JOIN " .
        $q("sis_person_identifiers") .
        " p ON p." .
        $q("person_id") .
        " = s." .
        $q("person_id");
    }
    public function project(array $record, ?int $expected): void
    {
        $row = $record;
        $row["details"] = $this->cipher->seal(json_encode($row["details"], JSON_THROW_ON_ERROR));
        if ($expected === null) {
            $this->sql->insert("sis_grade_change_requests", $row);
        } elseif (
            $this->sql->update("sis_grade_change_requests", $row, [
                "id" => $row["id"],
                "version" => $expected,
                "status" => "pending",
            ]) !== 1
        ) {
            throw new CorruptHistoryException();
        }
    }
    private function decode(array $row): array
    {
        $version = filter_var($row["version"], FILTER_VALIDATE_INT);
        if (
            $version === false ||
            $version < 1 ||
            !in_array($row["status"], ["pending", "approved", "rejected", "cancelled"], true)
        ) {
            throw new CorruptHistoryException();
        }
        $row["version"] = $version;
        $row["details"] = json_decode($this->cipher->open($row["details"]), true, 12, JSON_THROW_ON_ERROR);
        if (!is_array($row["details"])) {
            throw new CorruptHistoryException();
        }
        GradeChangeSchema::validate(
            \Domain\AcademicRecord\Event\GradeChangeWasRequested::class,
            $row["details"],
        );
        if (
            $row["id"] !== $row["details"]["decision_id"] ||
            $row["actor_id"] !== $row["details"]["actor_id"]
        ) {
            throw new CorruptHistoryException();
        }
        return $row;
    }
}
