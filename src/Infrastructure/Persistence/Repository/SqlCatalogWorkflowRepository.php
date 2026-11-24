<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Catalog\Repository\CatalogWorkflowRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Catalog\ValueObject\PrerequisiteGroups;
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

    public function prerequisiteGroups(EntityId $course): ?PrerequisiteGroups
    {
        $row = $this->sql->rows('sis_course_prerequisite_groups', ['id' => $course->value], 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        if (!is_string($row['groups_json']) || strlen($row['groups_json']) > 16384) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        if ($row['minimums_json'] !== null && strlen($row['minimums_json']) > 2048) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        $choices = json_decode($row['groups_json'], true, 8, JSON_THROW_ON_ERROR);
        $minimums = $row['minimums_json'] === null ? null :
        json_decode($row['minimums_json'], true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($choices) || ($row['minimums_json'] !== null && !is_array($minimums))) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        $rawFloors = $row['grade_points_json'];
        if ($rawFloors !== null && (!is_string($rawFloors) || strlen($rawFloors) > 2048)) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        $floors = $rawFloors === null ? null : json_decode($rawFloors, true, 4, JSON_THROW_ON_ERROR);
        if ($rawFloors !== null && !is_array($floors)) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        $raw = $row['corequisites_json'];
        if ($raw !== null && (!is_string($raw) || strlen($raw) > 1024)) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        $corequisites = $raw === null ? null : json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        if ($raw !== null && !is_array($corequisites)) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        $minimum = $row['requirement_minimum'];
        if ($minimum !== null) {
            $minimum = filter_var($minimum, FILTER_VALIDATE_INT);
            if ($minimum === false) {
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            }
        }
        $rawRule = $row['rule_json'];
        if ($rawRule !== null && (!is_string($rawRule) || strlen($rawRule) > 16384)) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        $rule = $rawRule === null ? null : json_decode($rawRule, true, 16, JSON_THROW_ON_ERROR);
        if ($rawRule !== null && !is_array($rule)) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        if (
            $row['prerequisite_credit_origin'] !== null
            && !in_array($row['prerequisite_credit_origin'], ['either', 'institutional', 'transfer'], true)
        ) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        $rawOrigins = $row['requirement_origins_json'];
        if ($rawOrigins !== null && (!is_string($rawOrigins) || strlen($rawOrigins) > 2048)) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        $origins = $rawOrigins === null ? null : json_decode($rawOrigins, true, 4, JSON_THROW_ON_ERROR);
        if ($rawOrigins !== null && !is_array($origins)) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        $groups = new PrerequisiteGroups(
            $choices,
            $minimums,
            $floors,
            $corequisites,
            $minimum,
            $rule,
            $row['prerequisite_credit_origin'],
            $origins,
        );
        if ($groups->courses() !== array_column($this->prerequisites($course), 'id')) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        return $groups;
    }

    public function savePrerequisiteGroups(EntityId $course, PrerequisiteGroups $groups): void
    {
        $this->sql->execute('DELETE FROM ' . $this->sql->quote('sis_prerequisites') . ' WHERE ' .
            $this->sql->quote('course_id') . ' = ?', [$course->value]);
        foreach ($groups->courses() as $id) {
            $this->sql->insert('sis_prerequisites', ['id' => EntityId::generate()->value,
                'course_id' => $course->value, 'required_course_id' => $id]);
        }
        $data = ['requirement_minimum' => $groups->minimumRequirements,
            'requirement_origins_json' => $groups->requirementCreditOrigins === null ? null :
                json_encode($groups->requirementCreditOrigins, JSON_THROW_ON_ERROR),
            'prerequisite_credit_origin' => $groups->creditOrigin,
            'rule_json' => $groups->rule === null ? null : json_encode($groups->rule->definition, JSON_THROW_ON_ERROR),
            'corequisites_json' => $groups->corequisites === null ? null :
            json_encode($groups->corequisites, JSON_THROW_ON_ERROR),
            'grade_points_json' => $groups->minimumGradePointsMilli === null ? null :
            json_encode($groups->minimumGradePointsMilli, JSON_THROW_ON_ERROR),
            'groups_json' => json_encode($groups->groups, JSON_THROW_ON_ERROR),
            'minimums_json' => $groups->minimums === null ? null : json_encode($groups->minimums, JSON_THROW_ON_ERROR)];
        if ($this->sql->rows('sis_course_prerequisite_groups', ['id' => $course->value], 1) === []) {
            $this->sql->insert('sis_course_prerequisite_groups', ['id' => $course->value] + $data);
        } else {
            $this->sql->update('sis_course_prerequisite_groups', $data, ['id' => $course->value]);
        }
    }

    public function prerequisiteLabels(array $ids): array
    {
        if (!array_is_list($ids) || count($ids) > 100) {
            throw new InvalidArgumentException('Use a bounded prerequisite course selection.');
        }
        if ($ids === []) {
            return [];
        }
        foreach ($ids as $id) {
            if (!is_string($id)) {
                throw new InvalidArgumentException('Use course identities for prerequisite labels.');
            }
            new EntityId($id);
        }
        $q = $this->sql->quote(...);
        return $this->sql->fetch('SELECT ' . implode(', ', array_map($q, ['id', 'code', 'title', 'active'])) .
            ' FROM ' . $q('sis_courses') . ' WHERE ' . $q('id') . ' IN (' .
            implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY ' . $q('id'), $ids, 100);
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
