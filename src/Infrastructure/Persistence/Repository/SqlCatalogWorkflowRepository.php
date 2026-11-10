<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Catalog\Repository\CatalogWorkflowRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;
use PDOException;

final readonly class SqlCatalogWorkflowRepository implements CatalogWorkflowRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function prerequisites(EntityId $course): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            "SELECT c." .
                $q("id") .
                ", c." .
                $q("code") .
                ", c." .
                $q("title") .
                ", c." .
                $q("active") .
                " FROM " .
                $q("sis_prerequisites") .
                " p JOIN " .
                $q("sis_courses") .
                " c ON c." .
                $q("id") .
                " = p." .
                $q("required_course_id") .
                " WHERE p." .
                $q("course_id") .
                " = ? ORDER BY c." .
                $q("id"),
            [$course->value],
            101,
        );
        return array_map(
            static fn(array $r): array => [
                "id" => $r["id"],
                "code" => $r["code"],
                "title" => $r["title"],
                "active" => (bool) $r["active"],
            ],
            $rows,
        );
    }

    public function operation(EntityId $id): ?array
    {
        return $this->sql->rows("sis_catalog_operations", ["id" => $id->value], 1)[0] ?? null;
    }

    public function offeringPage(?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_catalog_operations") .
                " WHERE " .
                $q("kind") .
                " = ?" .
                ($after === null ? "" : " AND " . $q("id") . " < ?") .
                " ORDER BY " .
                $q("id") .
                " DESC",
            $after === null ? ["offerings"] : ["offerings", $after->value],
            51,
        );
    }

    public function lockOperation(EntityId $id): void
    {
        $this->sql->lock("sis_catalog_operations", $id->value, "id");
    }

    public function record(EntityId $id, EntityId $actor, string $kind, string $encrypted, string $on): void
    {
        try {
            $this->sql->insert("sis_catalog_operations", [
                "id" => $id->value,
                "actor_id" => $actor->value,
                "kind" => $kind,
                "encrypted_content" => $encrypted,
                "created_at" => $on,
                "published_at" => null,
            ]);
        } catch (PDOException $error) {
            if (in_array((string) $error->getCode(), ["23000", "23505"], true)) {
                throw new ConcurrentModificationException(
                    "Check the saved catalog operation before retrying.",
                );
            }
            throw $error;
        }
    }

    public function published(EntityId $id, string $on, string $encrypted): void
    {
        $this->sql->update(
            "sis_catalog_operations",
            ["published_at" => $on, "encrypted_content" => $encrypted],
            ["id" => $id->value],
        );
    }

    public function faculty(EntityId $user): array
    {
        $rows = $this->sql->rows("sis_persons", ["owner_user_id" => $user->value], 1);
        $person = $rows[0] ?? throw new RecordNotFoundException("Choose a person with a sign-in account.");
        return ["id" => $user->value, "name" => trim($person["given_name"] . " " . $person["family_name"]),
            "person_id" => $person["id"]];
    }

    public function codeExists(string $kind, string $code, ?EntityId $term = null): bool
    {
        $table = match ($kind) {
            "courses" => "sis_courses",
            "sections" => "sis_sections",
            default => throw new InvalidArgumentException("Unknown catalog kind."),
        };
        $where = ["code" => $code];
        if ($kind === "sections") {
            $where["term_id"] = (
                $term ?? throw new InvalidArgumentException("A section term is required.")
            )->value;
        }
        return $this->sql->rows($table, $where, 1) !== [];
    }
}
