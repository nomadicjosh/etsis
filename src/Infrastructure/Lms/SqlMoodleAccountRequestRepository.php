<?php

declare(strict_types=1);

namespace Infrastructure\Lms;

use Domain\Lms\Repository\MoodleAccountRequestRepository;
use Domain\Lms\ValueObject\MoodleAccountRequest;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use PDOException;

final readonly class SqlMoodleAccountRequestRepository implements MoodleAccountRequestRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function forPerson(EntityId $binding, EntityId $person): ?MoodleAccountRequest
    {
        $row =
        $this->sql->rows(
            "sis_moodle_account_requests",
            ["binding_id" => $binding->value, "person_id" => $person->value],
            1,
        )[0] ?? null;
        if ($row === null) {
            return null;
        }
        return new MoodleAccountRequest(
            new EntityId($row["id"]),
            new EntityId($row["binding_id"]),
            new EntityId($row["person_id"]),
            new EntityId($row["requested_by"]),
            $row["requested_at"],
            $row["review_hash"],
        );
    }

    public function add(MoodleAccountRequest $request, #[\SensitiveParameter] string $encryptedApproval): void
    {
        try {
            $this->sql->insert("sis_moodle_account_requests", [
                "id" => $request->id->value,
                "binding_id" => $request->binding->value,
                "person_id" => $request->person->value,
                "requested_by" => $request->requestedBy->value,
                "requested_at" => $request->requestedAt,
                "review_hash" => $request->reviewHash,
                "encrypted_approval" => $encryptedApproval,
            ]);
        } catch (PDOException) {
            throw new ConcurrentModificationException(
                "An account request could not be saved. Refresh its status.",
            );
        }
    }

    public function enqueue(EntityId $request, string $createdAt): void
    {
        $this->sql->insert("sis_moodle_provisioning_outbox", [
            "id" => EntityId::generate()->value,
            "request_id" => $request->value,
            "state" => "queued",
            "version" => 0,
            "created_at" => $createdAt,
        ]);
    }

    public function state(EntityId $request): string
    {
        $row = $this->sql->one("sis_moodle_provisioning_outbox", ["request_id" => $request->value]);
        if (!in_array($row["state"], ["queued", "processing", "completed", "review_required"], true)) {
            throw new \RuntimeException("An account request has an unrecognized processing state.");
        }
        return $row["state"];
    }
}
