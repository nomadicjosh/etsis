<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Person\Repository\PhotoRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlPhotoRepository implements PhotoRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function add(array $row): void
    {
        $this->sql->insert("sis_photo_files", $row);
    }

    public function file(EntityId $id): ?array
    {
        return $this->sql->current("sis_photo_files", $id->value);
    }

    public function update(EntityId $id, array $changes): void
    {
        $this->sql->update("sis_photo_files", $changes, ["id" => $id->value]);
    }

    public function remove(EntityId $id): void
    {
        $this->sql->execute(
            "DELETE FROM " .
                $this->sql->quote("sis_photo_files") .
                " WHERE " .
                $this->sql->quote("id") .
                " = ?",
            [$id->value],
        );
    }

    public function due(string $now): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_photo_files") .
                " WHERE " .
                $q("expires_at") .
                " <= ? ORDER BY " .
                $q("id"),
            [$now],
            20,
        );
    }

    public function active(EntityId $person): array
    {
        return $this->sql->rows(
            "sis_photo_files",
            ["person_id" => $person->value, "state" => "active"],
            2,
        );
    }

    public function pending(EntityId $actor, string $now): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT " .
                $q("id") .
                " FROM " .
                $q("sis_photo_files") .
                " WHERE " .
                $q("actor_id") .
                " = ? AND " .
                $q("state") .
                " = ? AND " .
                $q("expires_at") .
                " > ?",
            [$actor->value, "review", $now],
            5,
        );
    }

    public function email(EntityId $owner): string
    {
        return $this->sql->one("users", ["user_id" => $owner->value])["email"];
    }

    public function matches(string $stem, string $mode): array
    {
        $q = $this->sql->quote(...);
        $select =
        "SELECT p." .
        $q("id") .
        ", p." .
        $q("given_name") .
        ", p." .
        $q("family_name") .
        ", i." .
        $q("institutional_id") .
        " FROM " .
        $q("sis_persons") .
        " p JOIN " .
        $q("sis_person_identifiers") .
        " i ON i." .
        $q("person_id") .
        " = p." .
        $q("id");
        if ($mode === "id") {
            $rows = $this->sql->fetch(
                $select .
                    " WHERE p." .
                    $q("id") .
                    " = ? OR i." .
                    $q("institutional_id") .
                    " = ?",
                [$stem, $stem],
                3,
            );
            $matches = static fn(array $row): bool => $row["id"] === $stem ||
                $row["institutional_id"] === $stem;
        } elseif (
            $mode !== "name" ||
            preg_match('/^([^_,]+)[_,]([^_,]+)$/uD', $stem, $names) !== 1
        ) {
            return [];
        } else {
            $given = mb_strtolower(trim($names[1]));
            $family = mb_strtolower(trim($names[2]));
            $rows = $this->sql->fetch(
                $select .
                    " WHERE LOWER(p." .
                    $q("given_name") .
                    ") = ? AND LOWER(p." .
                    $q("family_name") .
                    ") = ?",
                [$given, $family],
                3,
            );
            $matches = static fn(array $row): bool => mb_strtolower(
                trim($row["given_name"]),
            ) === $given &&
                mb_strtolower(trim($row["family_name"])) === $family;
        }
        // Never claim uniqueness from a truncated candidate set or a database's looser collation.
        if (count($rows) === 3) {
            return array_map(
                static fn(array $row): array => ["id" => $row["id"]],
                array_slice($rows, 0, 2),
            );
        }
        return array_values(
            array_map(
                static fn(array $row): array => ["id" => $row["id"]],
                array_filter($rows, $matches),
            ),
        );
    }
}
