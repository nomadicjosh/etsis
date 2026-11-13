<?php

declare(strict_types=1);

namespace Infrastructure\Lms;

use Domain\Lms\Repository\MoodleCategoryRepository;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\ConcurrentModificationException;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlMoodleCategoryRepository implements MoodleCategoryRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    private function table(string $source): string
    {
        return match ($source) {
            "subjects" => "sis_subjects",
            "departments" => "sis_catalog_classifications",
            default => throw new \InvalidArgumentException("Choose Subjects or Departments."),
        };
    }
    public function settings(EntityId $binding): ?array
    {
        return $this->sql->rows("sis_moodle_category_settings", ["binding_id" => $binding->value], 1)[0] ??
        null;
    }
    public function configure(
        EntityId $binding,
        string $source,
        int $expected,
        EntityId $actor,
        string $at,
    ): void {
        $this->table($source);
        $old = $this->settings($binding);
        $data = [
            "source" => $source,
            "version" => $expected + 1,
            "updated_by" => $actor->value,
            "updated_at" => $at,
        ];
        if ($old === null && $expected === 0) {
            $this->sql->insert(
                "sis_moodle_category_settings",
                ["id" => EntityId::generate()->value, "binding_id" => $binding->value] + $data,
            );
            return;
        }
        if (
            $old === null ||
            $this->sql->update("sis_moodle_category_settings", $data, [
                "id" => $old["id"],
                "version" => $expected,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException("Reload Moodle category settings before saving.");
        }
    }
    public function reference(string $source, EntityId $id): array
    {
        $r = $this->sql->one($this->table($source), ["id" => $id->value]);
        if ($source === "departments" && ($r["kind"] ?? null) !== "department") {
            throw new \InvalidArgumentException("Choose a department.");
        }
        return [
            "id" => $r["id"],
            "code" => $r["code"],
            "title" => $r["title"],
            "active" => (bool) $r["active"],
        ];
    }
    public function page(EntityId $binding, string $source, string $search): array
    {
        $q = $this->sql->quote(...);
        $params = [$binding->value, $source];
        $sql =
        "SELECT r.*, m." .
        $q("external_id") .
        ", m." .
        $q("issue_code") .
        " FROM " .
        $q($this->table($source)) .
        " r LEFT JOIN " .
        $q("sis_moodle_categories") .
        " m ON m." .
        $q("reference_id") .
        " = r." .
        $q("id") .
        " AND m." .
        $q("binding_id") .
        " = ? AND m." .
        $q("source") .
        " = ? WHERE ";
        if ($source === "departments") {
            $sql .= "r." . $q("kind") . " = ? AND ";
            $params[] = "department";
        }
        $sql .=
        "(LOWER(r." .
        $q("code") .
        ") LIKE ? OR LOWER(r." .
        $q("title") .
        ") LIKE ?) ORDER BY r." .
        $q("id");
        $like = "%" . mb_strtolower(str_replace(["%", "_"], "", $search)) . "%";
        return $this->sql->fetch($sql, [...$params, $like, $like], 20);
    }
    public function candidate(EntityId $binding, string $source, int $now): ?array
    {
        $q = $this->sql->quote(...);
        $params = [$binding->value, $source];
        $query =
        "SELECT r." .
        $q("id") .
        " FROM " .
        $q($this->table($source)) .
        " r LEFT JOIN " .
        $q("sis_moodle_categories") .
        " m ON m." .
        $q("reference_id") .
        " = r." .
        $q("id") .
        " AND m." .
        $q("binding_id") .
        " = ? AND m." .
        $q("source") .
        " = ? WHERE r." .
        $q("active") .
        " = ? AND ";
        $params[] = true;
        if ($source === "departments") {
            $query .= "r." . $q("kind") . " = ? AND ";
            $params[] = "department";
        }
        $priority = 'CASE WHEN m.' . $q('id') . ' IS NULL OR m.' . $q('source_code') .
        ' <> r.' . $q('code') . ' OR m.' . $q('source_title') . ' <> r.' . $q('title') . ' THEN 0 ELSE 1 END';
        $query .=
        "(m." .
        $q("id") .
        " IS NULL OR (m." .
        $q("lease_until") .
        " <= ? AND m." .
        $q("issue_code") .
        " <> ? AND (m." .
        $q("external_id") .
        " IS NULL OR m." .
        $q("source_code") .
        " <> r." .
        $q("code") .
        " OR m." .
        $q("source_title") .
        " <> r." .
        $q("title") .
        " OR m." .
        $q("checked_at") .
        " <= ?))) ORDER BY " . $priority . ", m." .
        $q("checked_at") .
        ", r." .
        $q("id");
        $r = $this->sql->fetch($query, [...$params, $now, "review_required", $now - 300], 1)[0] ?? null;
        return $r === null ? null : $this->reference($source, new EntityId($r["id"]));
    }
    public function mapping(EntityId $binding, string $source, EntityId $reference): ?array
    {
        return $this->sql->rows(
            "sis_moodle_categories",
            ["binding_id" => $binding->value, "source" => $source, "reference_id" => $reference->value],
            1,
        )[0] ?? null;
    }
    public function claim(EntityId $binding, string $source, EntityId $reference, int $now): array
    {
        $old = $this->mapping($binding, $source, $reference);
        if ($old === null) {
            $id = EntityId::generate();
            $this->sql->insert("sis_moodle_categories", [
                "id" => $id->value,
                "binding_id" => $binding->value,
                "source" => $source,
                "reference_id" => $reference->value,
                "version" => 1,
                "lease_until" => $now + 60,
            ]);
            return $this->sql->one("sis_moodle_categories", ["id" => $id->value]);
        }
        $v = (int) $old["version"];
        if ((int) $old["lease_until"] > $now || $v >= 2147483646) {
            throw new ConcurrentModificationException("Category synchronization is busy or needs review.");
        }
        if (
            $this->sql->update(
                "sis_moodle_categories",
                ["version" => $v + 1, "lease_until" => $now + 60],
                ["id" => $old["id"], "version" => $v],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("Category claim changed.");
        }
        return array_replace($old, ["version" => $v + 1]);
    }
    public function finish(
        EntityId $id,
        int $version,
        ?string $external,
        bool $owned,
        array $reference,
        string $issue,
        int $lease,
        int $checkedAt,
        ?string $decision = null,
    ): void {
        if (!in_array($issue, ["none", "source_changed", "retry_pending", "review_required"], true)) {
            throw new \InvalidArgumentException("Invalid category status.");
        }
        $r = $this->sql->one("sis_moodle_categories", ["id" => $id->value]);
        $attempts = (int) $r["attempts"] + ($issue === "retry_pending" ? 1 : 0);
        if ($attempts >= 5 && $issue === "retry_pending") {
            $issue = "review_required";
        }
        $data = [
            "issue_code" => $issue,
            "lease_until" => $lease,
            "attempts" => $attempts,
            "checked_at" => $checkedAt,
        ];
        if ($decision !== null) {
            $data["encrypted_link"] = $decision;
        }
        if ($issue === "none") {
            $data += [
                "external_id" => $external,
                "is_owned" => (int) $owned,
                "source_code" => $reference["code"],
                "source_title" => $reference["title"],
                "source_hash" => hash("sha256", json_encode($reference, JSON_THROW_ON_ERROR)),
            ];
        }
        if (
            $this->sql->update("sis_moodle_categories", $data, [
                "id" => $id->value,
                "version" => $version,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException("Category claim changed.");
        }
    }
}
