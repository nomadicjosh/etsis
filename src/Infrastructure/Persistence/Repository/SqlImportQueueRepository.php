<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Import\Repository\ImportQueueRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlImportQueueRepository implements ImportQueueRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function stage(array $job, string $encrypted, string $hash): void
    {
        $this->sql->insert("sis_import_jobs", $job);
        $this->sql->insert("sis_import_artifacts", [
            "id" => $job["id"],
            "encrypted_payload" => $encrypted,
            "payload_hash" => $hash,
        ]);
    }
    public function job(EntityId $job): ?array
    {
        return $this->sql->current("sis_import_jobs", $job->value);
    }
    public function artifact(EntityId $job): ?array
    {
        return $this->sql->current("sis_import_artifacts", $job->value);
    }
    public function update(EntityId $job, array $changes): void
    {
        $this->sql->update("sis_import_jobs", $changes, ["id" => $job->value]);
    }
    public function page(EntityId $owner, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_import_jobs") .
                " WHERE " .
                $q("owner_user_id") .
                " = ? AND " .
                $q("state") .
                " <> ?" .
                ($after === null ? "" : " AND " . $q("id") . " < ?") .
                " ORDER BY " .
                $q("id") .
                " DESC",
            [
                $owner->value,
                "review",
                ...$after === null ? [] : [$after->value],
            ],
            21,
        );
    }
    public function pending(string $now): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_import_jobs") .
                " WHERE " .
                $q("state") .
                " = ? OR (" .
                $q("state") .
                " = ? AND " .
                $q("lease_until") .
                " <= ?) ORDER BY " .
                $q("id"),
            ["queued", "running", $now],
            20,
        );
    }
    public function claim(
        EntityId $job,
        int $revision,
        string $token,
        string $until,
    ): bool {
        return $this->sql->update(
            "sis_import_jobs",
            [
                "state" => "running",
                "lease_token" => $token,
                "lease_until" => $until,
                "revision" => $revision + 1,
            ],
            ["id" => $job->value, "revision" => $revision],
        ) === 1;
    }
    public function purgeArtifact(EntityId $job): void
    {
        $this->sql->execute(
            "DELETE FROM " .
                $this->sql->quote("sis_import_artifacts") .
                " WHERE " .
                $this->sql->quote("id") .
                " = ?",
            [$job->value],
        );
    }
    public function notify(array $notice): void
    {
        $this->sql->insert("sis_import_notices", $notice);
    }
    public function notices(
        EntityId $owner,
        bool $unread,
        ?EntityId $after = null,
    ): array {
        $q = $this->sql->quote(...);
        $parameters = [$owner->value];
        $cursor = '';
        if ($after !== null) {
            $previous = $this->sql->rows('sis_import_notices', [
                'id' => $after->value, 'owner_user_id' => $owner->value,
            ], 1)[0] ?? null;
            if ($previous === null) {
                throw new \Domain\Shared\Exception\ConcurrentModificationException(
                    \Codefy\Framework\Helpers\trans('Import notifications changed. Refresh the list.'),
                );
            }
            $date = $q('recorded_at');
            $cursor = ' AND (' . $date . ' < ? OR (' . $date . ' = ? AND ' . $q('id') . ' < ?))';
            array_push($parameters, $previous['recorded_at'], $previous['recorded_at'], $after->value);
        }
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_import_notices") .
                " WHERE " .
                $q("owner_user_id") .
                " = ?" .
                ($unread ? " AND " . $q("read_at") . " IS NULL" : "") .
                $cursor .
                " ORDER BY " .
                $q('recorded_at') . ' DESC, ' . $q("id") .
                " DESC",
            $parameters,
            21,
        );
    }
    public function readNotice(
        EntityId $owner,
        EntityId $notice,
        string $at,
    ): void {
        $this->sql->update(
            "sis_import_notices",
            ["read_at" => $at],
            ["id" => $notice->value, "owner_user_id" => $owner->value],
        );
    }
    public function deleteNotice(EntityId $owner, EntityId $notice): void
    {
        $q = $this->sql->quote(...);
        $this->sql->execute(
            "DELETE FROM " .
                $q("sis_import_notices") .
                " WHERE " .
                $q("id") .
                " = ? AND " .
                $q("owner_user_id") .
                " = ?",
            [$notice->value, $owner->value],
        );
    }
    public function abandoned(string $now): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_import_jobs") .
                " WHERE " .
                $q("state") .
                " = ? AND " .
                $q("expires_at") .
                " <= ? ORDER BY " .
                $q("id"),
            ["review", $now],
            20,
        );
    }
    public function discardReview(EntityId $job): void
    {
        $this->purgeArtifact($job);
        $q = $this->sql->quote(...);
        $this->sql->execute(
            "DELETE FROM " .
                $q("sis_import_jobs") .
                " WHERE " .
                $q("id") .
                " = ? AND " .
                $q("state") .
                " = ?",
            [$job->value, "review"],
        );
    }
}
