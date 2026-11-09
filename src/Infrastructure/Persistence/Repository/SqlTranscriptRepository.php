<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transcript\Repository\TranscriptRepository;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlTranscriptRepository implements TranscriptRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function lockStudent(EntityId $id): void
    {
        $this->sql->lock("sis_students", $id->value, "id");
    }
    public function lockPerson(EntityId $id): void
    {
        $this->sql->lock("sis_persons", $id->value, "id");
    }
    public function lockRequest(EntityId $id): void
    {
        $this->sql->lock("sis_transcript_requests", $id->value, "id");
    }
    public function request(EntityId $id): array
    {
        return $this->sql->one("sis_transcript_requests", ["id" => $id->value]);
    }

    public function credits(EntityId $student): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            "SELECT c." .
                $q("id") .
                ", c." .
                $q("course_code") .
                ", c." .
                $q("course_title") .
                ", c." .
                $q("credit_type") .
                ", c." .
                $q("attempted_milli") .
                ", c." .
                $q("completed_milli") .
                ", c." .
                $q("grade_points_milli") .
                ", c." .
                $q("status") .
                ", c." .
                $q("version") .
                ", t." .
                $q("code") .
                " AS " .
                $q("term_code") .
                ", t." .
                $q("starts_on") .
                " AS " .
                $q("term_starts_on") .
                ", g." .
                $q("code") .
                " AS " .
                $q("grade_code") .
                ", g." .
                $q("counts_gpa") .
                " FROM " .
                $q("sis_academic_credits") .
                " c JOIN " .
                $q("sis_terms") .
                " t ON t." .
                $q("id") .
                " = c." .
                $q("term_id") .
                " LEFT JOIN " .
                $q("sis_grades") .
                " g ON g." .
                $q("id") .
                " = c." .
                $q("grade_id") .
                " WHERE c." .
                $q("student_id") .
                " = ? AND c." .
                $q("status") .
                " <> ? ORDER BY t." .
                $q("starts_on") .
                ", c." .
                $q("id"),
            [$student->value, "D"],
            501,
        );
        foreach ($rows as &$row) {
            foreach (["attempted_milli", "completed_milli", "grade_points_milli", "version"] as $field) {
                $row[$field] = (int) $row[$field];
            }
            $row["counts_gpa"] = (bool) ($row["counts_gpa"] ?? false);
            $row["grade_code"] ??= $row["status"] === "W" ? "W" : null;
            if ($row["status"] === "V") {
                $row["grade_code"] = "Voided";
            }
        }
        unset($row);
        return $rows;
    }

    public function document(EntityId $id): ?array
    {
        return $this->sql->rows("sis_transcript_documents", ["id" => $id->value], 1)[0] ?? null;
    }

    public function latest(EntityId $request): ?array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT " .
                implode(", ", array_map($q, ["id", "request_id", "prepared_version", "created_at", "pdf_hash"])) .
                " FROM " .
                $q("sis_transcript_documents") .
                " WHERE " .
                $q("request_id") .
                " = ? ORDER BY " .
                $q("prepared_version") .
                " DESC",
            [$request->value],
            1,
        )[0] ?? null;
    }

    public function addDocument(EntityId $id, array $data): void
    {
        $this->sql->insert("sis_transcript_documents", ["id" => $id->value] + $data);
    }

    public function status(EntityId $request, string $status, int $version): void
    {
        if (
            $this->sql->update(
                "sis_transcript_requests",
                ["status" => $status, "version" => $version + 1],
                ["id" => $request->value, "version" => $version],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("The transcript request changed. Review it again.");
        }
    }

    public function delivery(EntityId $operation): ?array
    {
        return $this->sql->rows("sis_transcript_deliveries", ["id" => $operation->value], 1)[0] ?? null;
    }

    public function documentDelivery(EntityId $document): ?array
    {
        return $this->sql->rows("sis_transcript_deliveries", ["document_id" => $document->value], 1)[0] ?? null;
    }

    public function addDelivery(EntityId $operation, array $data): void
    {
        $this->sql->insert("sis_transcript_deliveries", ["id" => $operation->value] + $data);
    }
}
