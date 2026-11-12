<?php

declare(strict_types=1);

namespace Infrastructure\Lms;

use Domain\Lms\Repository\MoodlePersonLinkRepository;
use Domain\Lms\ValueObject\MoodleExternalId;
use Domain\Lms\ValueObject\MoodlePersonLink;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use PDOException;

final readonly class SqlMoodlePersonLinkRepository implements MoodlePersonLinkRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function forPerson(EntityId $binding, EntityId $person): ?MoodlePersonLink
    {
        return $this->find(["binding_id" => $binding->value, "person_id" => $person->value]);
    }

    public function forExternal(EntityId $binding, MoodleExternalId $external): ?MoodlePersonLink
    {
        return $this->find(["binding_id" => $binding->value, "moodle_user_id" => $external->value]);
    }

    public function add(MoodlePersonLink $link, #[\SensitiveParameter] string $encryptedReview): void
    {
        try {
            $this->sql->insert("sis_moodle_person_links", [
                "id" => $link->id->value,
                "binding_id" => $link->binding->value,
                "person_id" => $link->person->value,
                "moodle_user_id" => $link->external->value,
                "approved_by" => $link->approvedBy->value,
                "approved_at" => $link->approvedAt,
                "encrypted_review" => $encryptedReview,
            ]);
        } catch (PDOException) {
            throw new ConcurrentModificationException(
                "The Moodle person link could not be saved. Refresh its saved details.",
            );
        }
    }

    /** @param array<string,string> $criteria */
    private function find(array $criteria): ?MoodlePersonLink
    {
        $row = $this->sql->rows("sis_moodle_person_links", $criteria, 1)[0] ?? null;
        return $row === null
        ? null
        : new MoodlePersonLink(
            new EntityId($row["id"]),
            new EntityId($row["binding_id"]),
            new EntityId($row["person_id"]),
            new MoodleExternalId($row["moodle_user_id"]),
            new EntityId($row["approved_by"]),
            $row["approved_at"],
        );
    }
}
