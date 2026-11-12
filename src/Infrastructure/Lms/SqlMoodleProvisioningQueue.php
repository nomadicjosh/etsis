<?php

declare(strict_types=1);

namespace Infrastructure\Lms;

use Domain\Lms\Repository\MoodleProvisioningQueue;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlMoodleProvisioningQueue implements MoodleProvisioningQueue
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function claim(int $now): ?array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            "SELECT " .
                $q("id") .
                ", " .
                $q("request_id") .
                ", " .
                $q("version") .
                ", " .
                $q("attempts") .
                " FROM " .
                $q("sis_moodle_provisioning_outbox") .
                " WHERE (" .
                $q("state") .
                " = ? OR (" .
                $q("state") .
                " = ? AND " .
                $q("lease_until") .
                " <= ?)) ORDER BY " .
                $q("id"),
            ["queued", "processing", $now],
            1,
        );
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        $version = (int) $row["version"];
        $attempts = (int) $row["attempts"] + 1;
        $changed = $this->sql->update(
            "sis_moodle_provisioning_outbox",
            [
                "state" => "processing",
                "version" => $version + 1,
                "attempts" => $attempts,
                "lease_until" => $now + 300,
                "issue_code" => "none",
            ],
            ["id" => $row["id"], "version" => $version],
        );
        return $changed === 1
        ? [
                "id" => $row["id"],
                "request_id" => $row["request_id"],
                "version" => $version + 1,
                "attempts" => $attempts,
            ]
        : null;
    }
    public function approval(EntityId $request): array
    {
        return $this->sql->one("sis_moodle_account_requests", ["id" => $request->value]);
    }
    public function finish(EntityId $job, int $version, string $state, string $issue): void
    {
        if (
            !in_array($state, ["completed", "review_required", "processing"], true) ||
            !in_array(
                $issue,
                ["none", "request_changed", "connection_uncertain", "attempt_limit", "email_delivery"],
                true,
            )
        ) {
            throw new \InvalidArgumentException("Invalid provisioning transition.");
        }
        $data = ["state" => $state, "version" => $version + 1, "issue_code" => $issue];
        if ($state !== "processing") {
            $data["lease_until"] = 0;
        }
        if (
            $this->sql->update("sis_moodle_provisioning_outbox", $data, [
                "id" => $job->value,
                "version" => $version,
                "state" => "processing",
            ]) !== 1
        ) {
            throw new ConcurrentModificationException("The provisioning claim changed.");
        }
    }
}
