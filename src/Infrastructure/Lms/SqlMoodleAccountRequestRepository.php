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

    private function issue(string $code): string
    {
        return match ($code) {
            "request_changed"
            => "The person or approved setup changed. Ask an integration administrator to review this request.",
            "connection_uncertain"
            => "Outcome uncertain. The worker will reconcile this operation after its lease expires.",
            "attempt_limit"
            => "Attempts stopped. An integration administrator must reconcile this operation before a new request.",
            "email_delivery"
            => "Account linked; setup email needs review. Use Moodle password recovery, keeping this account.",
            "email_resolved"
            => "Staff recorded that access was resolved for the existing account. No email was resent by etSIS.",
            default => "",
        };
    }
    public function processing(EntityId $request): array
    {
        $q = $this->sql->quote(...);
        $row =
        $this->sql->fetch(
            "SELECT o." .
                    $q("version") .
                    ", o." .
                    $q("state") .
                    ", o." .
                    $q("issue_code") .
                    ", r." .
                    $q("resolution_hash") .
                    " FROM " .
                    $q("sis_moodle_provisioning_outbox") .
                    " o JOIN " .
                    $q("sis_moodle_account_requests") .
                    " r ON r." .
                    $q("id") .
                    " = o." .
                    $q("request_id") .
                    " WHERE r." .
                    $q("id") .
                    " = ?",
            [$request->value],
            1,
        )[0] ?? null;
        if (
            $row === null ||
            !in_array($row["state"], ["queued", "processing", "completed", "review_required"], true) ||
            !in_array(
                $row["issue_code"],
                [
                    "none",
                    "request_changed",
                    "connection_uncertain",
                    "attempt_limit",
                    "email_delivery",
                    "email_resolved",
                ],
                true,
            )
        ) {
            throw new \RuntimeException("An account request has an unrecognized processing state.");
        }
        return [
            "version" => (int) $row["version"],
            "state" => $row["state"],
            "issue_code" => $row["issue_code"],
            "issue" => $this->issue($row["issue_code"]),
            "resolution_hash" => $row["resolution_hash"],
        ];
    }

    public function resolveEmail(
        EntityId $request,
        int $expectedVersion,
        string $reviewHash,
        #[\SensitiveParameter] string $encryptedResolution,
    ): void {
        if (
            $this->sql->update(
                "sis_moodle_provisioning_outbox",
                [
                    "state" => "completed",
                    "issue_code" => "email_resolved",
                    "version" => $expectedVersion + 1,
                ],
                [
                    "request_id" => $request->value,
                    "version" => $expectedVersion,
                    "state" => "review_required",
                    "issue_code" => "email_delivery",
                ],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("The account request changed. Refresh its status.");
        }
        $q = $this->sql->quote(...);
        if (
            $this->sql->execute(
                "UPDATE " . $q("sis_moodle_account_requests") . " SET " . $q("resolution_hash") .
                " = ?, " . $q("encrypted_resolution") . " = ? WHERE " . $q("id") . " = ? AND " .
                $q("resolution_hash") . " IS NULL AND " . $q("encrypted_resolution") . " IS NULL",
                [$reviewHash, $encryptedResolution, $request->value],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("The account approval changed. Refresh its status.");
        }
    }
}
