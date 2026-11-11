<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\DegreeAudit\Repository\CourseGroupRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use JsonException;
use PDOException;

final readonly class SqlCourseGroupRepository implements CourseGroupRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    /**
     * @throws JsonException
     */
    public function get(EntityId $id): array
    {
        $r = $this->sql->one("sis_course_groups", ["id" => $id->value]);
        $r["definition"] = json_decode($r["draft_json"], true, 16, JSON_THROW_ON_ERROR);
        unset($r["draft_json"]);
        $r["revision"] = (int) $r["revision"];
        $r["publication_position"] = (int) $r["publication_position"];
        return $r;
    }
    public function lock(EntityId $id): void
    {
        $this->sql->lock("sis_course_groups", $id->value, "id");
    }
    public function page(string $search, bool $publishedOnly, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        $where = [];
        $params = [];
        if ($publishedOnly) {
            $where[] = $q("published_version_id") . " IS NOT NULL";
        }
        if ($after !== null) {
            $where[] = $q("id") . " < ?";
            $params[] = $after->value;
        }
        if ($search !== "") {
            $column = $publishedOnly ? "published_name" : "name";
            $where[] = "LOWER(" . $q($column) . ") LIKE ? ESCAPE '!'";
            $params[] = "%" . str_replace(["!", "%", "_"], ["!!", "!%", "!_"], mb_strtolower($search)) . "%";
        }
        return $this->sql->fetch(
            "SELECT " .
                implode(
                    ", ",
                    array_map($q, [
                        "id",
                        "name",
                        "revision",
                        "published_version_id",
                        "published_name",
                        "publication_position",
                    ]),
                ) .
                " FROM " .
                $q("sis_course_groups") .
                ($where === [] ? "" : " WHERE " . implode(" AND ", $where)) .
                " ORDER BY " .
                $q("id") .
                " DESC",
            $params,
            51,
        );
    }
    /**
     * @throws JsonException
     */
    public function create(EntityId $id, array $definition): void
    {
        try {
            $this->sql->insert("sis_course_groups", [
                "id" => $id->value,
                "name" => $definition["name"],
                "creation_hash" => hash("sha256", json_encode($definition, JSON_THROW_ON_ERROR)),
                "draft_json" => json_encode($definition, JSON_THROW_ON_ERROR),
                "revision" => 0,
                "published_version_id" => null,
                "published_name" => null,
                "publication_position" => 0,
            ]);
        } catch (PDOException $error) {
            if (in_array((string) $error->getCode(), ["23000", "23505"], true)) {
                throw new ConcurrentModificationException(
                    "This group creation conflicted. Review the library before retrying.",
                );
            }
            throw $error;
        }
    }
    /**
     * @throws JsonException
     */
    public function save(EntityId $id, int $revision, array $definition): void
    {
        if (
            $this->sql->update(
                "sis_course_groups",
                [
                    "name" => $definition["name"],
                    "draft_json" => json_encode($definition, JSON_THROW_ON_ERROR),
                    "revision" => $revision + 1,
                ],
                ["id" => $id->value, "revision" => $revision],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("The course group changed. Reload it before saving.");
        }
    }
    public function published(EntityId $id, EntityId $version, int $position, string $name): void
    {
        $this->sql->update(
            "sis_course_groups",
            [
                "published_version_id" => $version->value,
                "publication_position" => $position,
                "published_name" => $name,
            ],
            ["id" => $id->value],
        );
    }
}
