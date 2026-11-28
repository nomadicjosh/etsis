<?php

declare(strict_types=1);

namespace Infrastructure\Lms;

use Domain\Lms\Repository\MoodleGradeImportRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

use function Codefy\Framework\Helpers\trans;

final readonly class SqlMoodleGradeImportRepository implements MoodleGradeImportRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function job(EntityId $section): ?array
    {
        return $this->sql->rows(
            "sis_moodle_grade_imports",
            ["id" => $section->value],
            1,
        )[0] ?? null;
    }

    public function saveJob(
        EntityId $section,
        int $revision,
        array $values,
    ): void {
        $values["revision"] = $revision + 1;
        if ($revision === 0) {
            $this->sql->insert(
                "sis_moodle_grade_imports",
                ["id" => $section->value] + $values,
            );
        } elseif (
            $this->sql->update("sis_moodle_grade_imports", $values, [
                "id" => $section->value,
                "revision" => $revision,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException(
                trans("The Moodle intake changed. Refresh its status."),
            );
        }
    }

    public function pending(int $now): ?array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_moodle_grade_imports") .
                " WHERE " .
                $q("state") .
                " IN (?, ?) AND " .
                $q("lease_until") .
                " <= ? ORDER BY " .
                $q("lease_until") .
                ", " .
                $q("id"),
            ["queued", "running", $now],
            1,
        )[0] ?? null;
    }

    public function boundary(EntityId $section): ?string
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT " .
                $q("id") .
                " FROM " .
                $q("sis_enrollments") .
                " WHERE " .
                $q("section_id") .
                " = ? AND " .
                $q("status") .
                " IN (?, ?) ORDER BY " .
                $q("id") .
                " DESC",
            [$section->value, "A", "N"],
            1,
        )[0]["id"] ?? null;
    }

    public function nextEnrollment(
        EntityId $section,
        ?string $after,
        string $through,
    ): ?array {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_enrollments") .
                " WHERE " .
                $q("section_id") .
                " = ? AND " .
                $q("status") .
                " IN (?, ?) AND " .
                $q("id") .
                " <= ?" .
                ($after === null ? "" : " AND " . $q("id") . " > ?") .
                " ORDER BY " .
                $q("id"),
            [
                $section->value,
                "A",
                "N",
                $through,
                ...$after === null ? [] : [$after],
            ],
            1,
        )[0] ?? null;
    }

    public function candidate(EntityId $id): ?array
    {
        return $this->sql->rows(
            "sis_moodle_grade_candidates",
            ["id" => $id->value],
            1,
        )[0] ?? null;
    }

    public function source(string $key): ?array
    {
        return $this->sql->rows(
            "sis_moodle_grade_candidates",
            ["source_key" => $key],
            1,
        )[0] ?? null;
    }

    public function add(array $values): void
    {
        $this->sql->insert(
            "sis_moodle_grade_candidates",
            $values + ["revision" => 1],
        );
    }

    public function change(EntityId $id, int $revision, array $values): void
    {
        if (
            $this->sql->update(
                "sis_moodle_grade_candidates",
                $values + ["revision" => $revision + 1],
                ["id" => $id->value, "revision" => $revision],
            ) !== 1
        ) {
            throw new ConcurrentModificationException(
                trans("The imported grade changed. Review it again."),
            );
        }
    }

    public function supersede(EntityId $enrollment, string $except): void
    {
        $q = $this->sql->quote(...);
        $this->sql->execute(
            "UPDATE " .
                $q("sis_moodle_grade_candidates") .
                " SET " .
                $q("state") .
                " = ?, " .
                $q("revision") .
                " = " .
                $q("revision") .
                " + 1 WHERE " .
                $q("enrollment_id") .
                " = ? AND " .
                $q("source_key") .
                " <> ? AND " .
                $q("state") .
                " IN (?, ?)",
            [
                "superseded",
                $enrollment->value,
                $except,
                "pending",
                "unavailable",
            ],
        );
    }

    public function page(EntityId $section, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_moodle_grade_candidates") .
                " WHERE " .
                $q("section_id") .
                " = ?" .
                ($after === null ? "" : " AND " . $q("id") . " > ?") .
                " ORDER BY " .
                $q("id"),
            [$section->value, ...$after === null ? [] : [$after->value]],
            11,
        );
    }

    public function notify(array $notice): void
    {
        $this->sql->insert(
            "sis_moodle_grade_notices",
            $notice + ["revision" => 1],
        );
    }

    public function notices(
        EntityId $owner,
        bool $unread,
        ?EntityId $after = null,
    ): array {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_moodle_grade_notices") .
                " WHERE " .
                $q("owner_id") .
                " = ?" .
                ($unread ? " AND " . $q("read_at") . " IS NULL" : "") .
                ($after === null ? "" : " AND " . $q("id") . " < ?") .
                " ORDER BY " .
                $q("id") .
                " DESC",
            [$owner->value, ...$after === null ? [] : [$after->value]],
            21,
        );
    }

    public function notice(EntityId $owner, EntityId $notice): ?array
    {
        return $this->sql->rows(
            "sis_moodle_grade_notices",
            ["id" => $notice->value, "owner_id" => $owner->value],
            1,
        )[0] ?? null;
    }

    public function clearNotice(
        EntityId $owner,
        EntityId $notice,
        int $revision,
        ?string $readAt,
    ): void {
        if ($readAt === null) {
            $q = $this->sql->quote(...);
            $count = $this->sql->execute(
                "DELETE FROM " .
                    $q("sis_moodle_grade_notices") .
                    " WHERE " .
                    $q("id") .
                    " = ? AND " .
                    $q("owner_id") .
                    " = ? AND " .
                    $q("revision") .
                    " = ?",
                [$notice->value, $owner->value, $revision],
            );
        } else {
            $count = $this->sql->update(
                "sis_moodle_grade_notices",
                ["read_at" => $readAt, "revision" => $revision + 1],
                [
                    "id" => $notice->value,
                    "owner_id" => $owner->value,
                    "revision" => $revision,
                ],
            );
        }
        if ($count !== 1) {
            throw new ConcurrentModificationException(
                trans(
                    "The intake notification changed. Refresh notifications.",
                ),
            );
        }
    }
}
