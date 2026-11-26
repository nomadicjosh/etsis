<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Person\Repository\NotificationPreferencesRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlNotificationPreferencesRepository implements NotificationPreferencesRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function current(EntityId $person): ?array
    {
        $row = $this->sql->current("sis_notification_preferences", $person->value);
        if ($row === null) {
            return null;
        }
        return [
            "revision" => (int) $row["revision"],
            "preferences" => [
                "sound" => (bool) $row["sound"],
                "sound_id" => $row["sound_id"],
                "keep_visible" => (bool) $row["keep_visible"],
            ],
        ];
    }

    public function save(EntityId $person, array $preferences, int $revision): void
    {
        $data = [
            "sound" => (int) $preferences["sound"],
            "sound_id" => $preferences["sound_id"],
            "keep_visible" => (int) $preferences["keep_visible"],
            "revision" => $revision + 1,
        ];
        if ($revision === 0) {
            $this->sql->insert("sis_notification_preferences", ["id" => $person->value] + $data);
        } else {
            $this->sql->update("sis_notification_preferences", $data, [
                "id" => $person->value,
                "revision" => $revision,
            ]);
        }
    }
}
