<?php

declare(strict_types=1);

namespace Infrastructure\Lms;

use Domain\Lms\Repository\MoodleCourseRequestRepository;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\ConcurrentModificationException;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlMoodleCourseRequestRepository implements MoodleCourseRequestRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function forSection(EntityId $binding, EntityId $section): ?array
    {
        return $this->sql->rows(
            "sis_moodle_course_requests",
            ["binding_id" => $binding->value, "section_id" => $section->value],
            1,
        )[0] ?? null;
    }
    public function add(array $row): void
    {
        $this->sql->insert("sis_moodle_course_requests", $row);
    }
    public function claim(int $now): ?array
    {
        $q = $this->sql->quote(...);
        $r =
        $this->sql->fetch(
            "SELECT * FROM " .
                    $q("sis_moodle_course_requests") .
                    " WHERE " .
                    $q("state") .
                    " IN (?, ?) AND " .
                    $q("lease_until") .
                    " <= ? ORDER BY " .
                    $q("id"),
            ["queued", "processing", $now],
            1,
        )[0] ?? null;
        if ($r === null) {
            return null;
        }
        $v = (int) $r["version"];
        if ($v < 0 || $v >= 2147483646) {
            throw new ConcurrentModificationException("Course request version needs review.");
        }
        if (
            $this->sql->update(
                "sis_moodle_course_requests",
                ["version" => $v + 1, "state" => "processing", "lease_until" => $now + 120],
                ["id" => $r["id"], "version" => $v],
            ) !== 1
        ) {
            return null;
        }
        return array_replace($r, ["version" => $v + 1]);
    }
    public function finish(EntityId $id, int $version, string $state, string $issue, int $lease): void
    {
        if (
            !in_array($state, ["queued", "processing", "completed", "review_required"], true) ||
            !in_array(
                $issue,
                ["none", "selection_required",
                "category_required", "source_changed", "connection_uncertain", "attempt_limit"],
                true,
            )
        ) {
            throw new \InvalidArgumentException("Invalid course request transition.");
        }
        // Only actual remote attempts consume the retry budget.
        $r = $this->sql->one("sis_moodle_course_requests", ["id" => $id->value]);
        $attempts = (int) $r["attempts"] + ($issue === "connection_uncertain" ? 1 : 0);
        if ($attempts >= 5 && $issue === "connection_uncertain") {
            $state = "review_required";
            $issue = "attempt_limit";
        }
        if (
            $this->sql->update(
                "sis_moodle_course_requests",
                ["state" => $state, "issue_code" => $issue, "lease_until" => $lease, "attempts" => $attempts],
                ["id" => $id->value, "version" => $version],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("Course request claim changed.");
        }
    }
}
