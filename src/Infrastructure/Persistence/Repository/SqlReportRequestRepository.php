<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Reporting\ReportRequest;
use Domain\Reporting\Repository\ReportRequestRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlReportRequestRepository implements ReportRequestRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function find(EntityId $id): ?ReportRequest
    {
        $r = $this->sql->rows("sis_report_requests", ["id" => $id->value], 1)[0] ?? null;
        return $r === null
        ? null
        : new ReportRequest(
            $id,
            new EntityId($r["requested_by"]),
            $r["assigned_to"] === null ? null : new EntityId($r["assigned_to"]),
            $r["status"],
            (int) $r["version"],
            $r["report_id"] === null ? null : new EntityId($r["report_id"]),
        );
    }
    public function lock(EntityId $id): void
    {
        $this->sql->lock("sis_report_requests", $id->value, "id");
    }
    public function lockRequester(EntityId $actor): void
    {
        // The users table has a legacy-named primary key. A scoped no-op write serializes this actor's creates.
        $this->sql->update("users", ["user_id" => $actor->value], ["user_id" => $actor->value]);
        $this->sql->one("users", ["user_id" => $actor->value]);
    }
    public function add(ReportRequest $request, string $encrypted, ?string $dueOn, string $at): void
    {
        $this->sql->insert("sis_report_requests", [
            "id" => $request->id->value,
            "requested_by" => $request->requestedBy->value,
            "assigned_to" => null,
            "status" => "open",
            "version" => 0,
            "due_on" => $dueOn,
            "report_id" => null,
            "created_at" => $at,
            "updated_at" => $at,
            "encrypted_content" => $encrypted,
        ]);
    }
    public function save(ReportRequest $request, int $version, string $at): void
    {
        if (
            $this->sql->update(
                "sis_report_requests",
                [
                    "assigned_to" => $request->assignedTo?->value,
                    "status" => $request->status,
                    "version" => $request->version,
                    "report_id" => $request->reportId?->value,
                    "updated_at" => $at,
                ],
                ["id" => $request->id->value, "version" => $version],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("The report request changed. Review it again.");
        }
    }
    public function content(EntityId $id): array
    {
        return $this->sql->fetch(
            $this->selection() . " WHERE r." . $this->sql->quote("id") . " = ?",
            [$id->value],
            1,
        )[0] ?? throw new \Domain\Shared\Exception\RecordNotFoundException("Request not found.");
    }
    public function page(?EntityId $requester, ?string $status, ?EntityId $after, int $limit): array
    {
        $q = $this->sql->quote(...);
        $where = [];
        $args = [];
        foreach (["requested_by" => $requester?->value, "status" => $status] as $field => $value) {
            if ($value !== null) {
                $where[] = "r." . $q($field) . " = ?";
                $args[] = $value;
            }
        }
        if ($after !== null) {
            $where[] = "r." . $q("id") . " > ?";
            $args[] = $after->value;
        }
        return $this->sql->fetch(
            $this->selection() .
                ($where === [] ? "" : " WHERE " . implode(" AND ", $where)) .
                " ORDER BY r." .
                $q("id"),
            $args,
            $limit,
        );
    }
    public function history(EntityId $request, int $afterVersion, int $limit): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_report_request_actions") .
                " WHERE " .
                $q("request_id") .
                " = ? AND " .
                $q("version") .
                " > ? ORDER BY " .
                $q("version"),
            [$request->value, $afterVersion],
            $limit,
        );
    }
    public function operation(EntityId $operation): ?array
    {
        return $this->sql->rows("sis_report_request_actions", ["id" => $operation->value], 1)[0] ?? null;
    }
    public function record(
        EntityId $operation,
        EntityId $request,
        EntityId $actor,
        int $version,
        string $hash,
        string $encrypted,
        string $at,
    ): void {
        $this->sql->insert("sis_report_request_actions", [
            "id" => $operation->value,
            "request_id" => $request->value,
            "actor_id" => $actor->value,
            "version" => $version,
            "request_hash" => $hash,
            "encrypted_content" => $encrypted,
            "created_at" => $at,
        ]);
    }
    private function selection(): string
    {
        $q = $this->sql->quote(...);
        return "SELECT r.*, p." .
        $q("given_name") .
        " AS " .
        $q("requester_given") .
        ", p." .
        $q("family_name") .
        " AS " .
        $q("requester_family") .
        ", a." .
        $q("given_name") .
        " AS " .
        $q("assignee_given") .
        ", a." .
        $q("family_name") .
        " AS " .
        $q("assignee_family") .
        " FROM " .
        $q("sis_report_requests") .
        " r LEFT JOIN " .
        $q("sis_persons") .
        " p ON p." .
        $q("owner_user_id") .
        " = r." .
        $q("requested_by") .
        " LEFT JOIN " .
        $q("sis_persons") .
        " a ON a." .
        $q("owner_user_id") .
        " = r." .
        $q("assigned_to");
    }
}
