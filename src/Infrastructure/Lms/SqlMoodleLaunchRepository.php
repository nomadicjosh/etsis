<?php

declare(strict_types=1);

namespace Infrastructure\Lms;

use Domain\Lms\Repository\MoodleLaunchRepository;
use Domain\Lms\ValueObject\{MoodleLaunch, MoodleExternalId};
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlMoodleLaunchRepository implements MoodleLaunchRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function personForOwner(EntityId $user): ?EntityId
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            "SELECT " . $q("id") . " FROM " . $q("sis_persons") . " WHERE " . $q("owner_user_id") . " = ?",
            [$user->value],
            2,
        );
        if (count($rows) > 1) {
            throw new \RuntimeException("The canonical person record requires review.");
        }
        return isset($rows[0]) ? new EntityId($rows[0]["id"]) : null;
    }
    public function add(MoodleLaunch $launch, #[\SensitiveParameter] string $ticketHash): void
    {
        $this->sql->insert("sis_moodle_launches", [
            "id" => $launch->id->value,
            "ticket_hash" => $ticketHash,
            "session_id" => $launch->session->value,
            "user_id" => $launch->user->value,
            "person_id" => $launch->person->value,
            "binding_id" => $launch->binding->value,
            "link_id" => $launch->link->value,
            "moodle_user_id" => $launch->external->value,
            "ticket_expires_at" => gmdate("Y-m-d\TH:i:s\Z", $launch->ticketExpiresAt),
            "session_expires_at" => gmdate("Y-m-d\TH:i:s\Z", $launch->sessionExpiresAt),
            "consumed" => 0,
        ]);
    }
    public function forTicket(string $hash): ?MoodleLaunch
    {
        return $this->find(["ticket_hash" => $hash]);
    }
    public function byId(EntityId $id): ?MoodleLaunch
    {
        return $this->find(["id" => $id->value]);
    }
    public function consume(EntityId $id): bool
    {
        return $this->sql->update(
            "sis_moodle_launches",
            ["consumed" => 1],
            ["id" => $id->value, "consumed" => 0],
        ) === 1;
    }
    /** @param array<string,string> $criteria */
    private function find(array $criteria): ?MoodleLaunch
    {
        $row = $this->sql->rows("sis_moodle_launches", $criteria, 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        if (!in_array((string) $row["consumed"], ["0", "1"], true)) {
            throw new \RuntimeException("Invalid Moodle launch state.");
        }
        return new MoodleLaunch(
            new EntityId($row["id"]),
            new EntityId($row["session_id"]),
            new EntityId($row["user_id"]),
            new EntityId($row["person_id"]),
            new EntityId($row["binding_id"]),
            new EntityId($row["link_id"]),
            new MoodleExternalId($row["moodle_user_id"]),
            $this->timestamp($row["ticket_expires_at"]),
            $this->timestamp($row["session_expires_at"]),
            (int) $row["consumed"] === 1,
        );
    }
    private function timestamp(mixed $value): int
    {
        $date = is_string($value)
        ? \DateTimeImmutable::createFromFormat("!Y-m-d\TH:i:s\Z", $value, new \DateTimeZone("UTC"))
        : false;
        if ($date === false || $date->format("Y-m-d\TH:i:s\Z") !== $value) {
            throw new \RuntimeException("Invalid Moodle launch deadline.");
        }
        return $date->getTimestamp();
    }
}
