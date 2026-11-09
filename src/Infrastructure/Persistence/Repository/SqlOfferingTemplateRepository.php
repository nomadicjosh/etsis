<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Catalog\Repository\OfferingTemplateRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlOfferingTemplateRepository implements OfferingTemplateRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function find(EntityId $id): ?array
    {
        $row = $this->sql->rows("sis_offering_templates", ["id" => $id->value], 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        $row["version"] = (int) $row["version"];
        $row["active"] = (bool) $row["active"];
        $row["section_count"] = (int) $row["section_count"];
        return $row;
    }
    public function lock(EntityId $id): void
    {
        $this->sql->lock("sis_offering_templates", $id->value, "id");
    }
    public function lockFaculty(EntityId $id): void
    {
        $this->sql->update("users", ["user_id" => $id->value], ["user_id" => $id->value]);
        if (
            $this->sql->fetch(
                \Infrastructure\Persistence\SqlRowLock::select(
                    $this->sql->quote("users"),
                    $this->sql->quote("user_id"),
                    $this->sql->driver(),
                ),
                [$id->value],
            ) === []
        ) {
            throw new \Domain\Shared\Exception\RecordNotFoundException("Faculty account not found.");
        }
    }
    public function page(?bool $active, string $search, ?EntityId $after, int $limit): array
    {
        $q = $this->sql->quote(...);
        $where = [];
        $args = [];
        if ($active !== null) {
            $where[] = $q("active") . " = ?";
            $args[] = (int) $active;
        }
        if ($search !== "") {
            $where[] = "LOWER(" . $q("name") . ") LIKE LOWER(?) ESCAPE '!'";
            $args[] = "%" . str_replace(["!", "%", "_"], ["!!", "!%", "!_"], $search) . "%";
        }
        if ($after !== null) {
            $where[] = $q("id") . " > ?";
            $args[] = $after->value;
        }
        return $this->sql->fetch(
            "SELECT " .
                implode(", ", array_map($q, ["id", "name", "active", "version", "section_count", "updated_at"])) .
                " FROM " .
                $q("sis_offering_templates") .
                ($where === [] ? "" : " WHERE " . implode(" AND ", $where)) .
                " ORDER BY " .
                $q("id"),
            $args,
            $limit,
        );
    }
    public function save(
        EntityId $id,
        EntityId $actor,
        int $expectedVersion,
        string $name,
        bool $active,
        int $count,
        string $encrypted,
        string $at,
    ): void {
        $values = [
            "name" => $name,
            "active" => (int) $active,
            "version" => $expectedVersion + 1,
            "section_count" => $count,
            "encrypted_content" => $encrypted,
            "updated_at" => $at,
        ];
        if ($expectedVersion === -1) {
            $this->sql->insert(
                "sis_offering_templates",
                ["id" => $id->value, "created_by" => $actor->value, "created_at" => $at] + $values,
            );
        } elseif (
            $this->sql->update("sis_offering_templates", $values, [
                "id" => $id->value,
                "version" => $expectedVersion,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException("Reload the offering template before saving.");
        }
    }
    public function operation(EntityId $id): ?array
    {
        return $this->sql->rows("sis_offering_template_changes", ["id" => $id->value], 1)[0] ?? null;
    }
    public function record(
        EntityId $operation,
        EntityId $template,
        EntityId $actor,
        int $version,
        string $hash,
        string $encrypted,
        string $at,
    ): void {
        $this->sql->insert("sis_offering_template_changes", [
            "id" => $operation->value,
            "template_id" => $template->value,
            "actor_id" => $actor->value,
            "version" => $version,
            "request_hash" => $hash,
            "encrypted_content" => $encrypted,
            "created_at" => $at,
        ]);
    }
    public function history(EntityId $template, int $afterVersion, int $limit): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_offering_template_changes") .
                " WHERE " .
                $q("template_id") .
                " = ? AND " .
                $q("version") .
                " > ? ORDER BY " .
                $q("version"),
            [$template->value, $afterVersion],
            $limit,
        );
    }
}
