<?php

declare(strict_types=1);

namespace Infrastructure\Lms;

use Domain\Lms\Repository\MoodleBindingRepository;
use Domain\Lms\ValueObject\MoodleBinding;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use PDOException;

final readonly class SqlMoodleBindingRepository implements MoodleBindingRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function current(): ?MoodleBinding
    {
        $row = $this->sql->rows("sis_moodle_bindings", ["scope" => "institution"], 1)[0] ?? null;
        return $row === null
        ? null
        : new MoodleBinding(
            new EntityId($row["id"]),
            $row["site_fingerprint"],
            $row["instance_fingerprint"],
            new EntityId($row["approved_by"]),
            $row["approved_at"],
        );
    }

    public function add(MoodleBinding $binding, #[\SensitiveParameter] string $encryptedReason): void
    {
        try {
            $this->sql->insert("sis_moodle_bindings", [
                "id" => $binding->id->value,
                "scope" => "institution",
                "site_fingerprint" => $binding->siteFingerprint,
                "instance_fingerprint" => $binding->instanceFingerprint,
                "approved_by" => $binding->approvedBy->value,
                "approved_at" => $binding->approvedAt,
                "encrypted_reason" => $encryptedReason,
            ]);
        } catch (PDOException) {
            // Do not expose SQL parameters or driver messages; the caller rolls back the transaction.
            throw new ConcurrentModificationException(
                "The installation approval could not be saved. Refresh and review again.",
            );
        }
    }
}
