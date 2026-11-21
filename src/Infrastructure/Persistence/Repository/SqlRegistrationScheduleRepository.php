<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Registration\Repository\RegistrationScheduleRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlRegistrationScheduleRepository implements RegistrationScheduleRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function lockPolicy(): void
    {
        $this->sql->lock("sis_registration_policy", self::POLICY_ID, "id");
    }
    public function policy(): array
    {
        // Normal registrations do not take a shared institution-wide write lock.
        $row =
        $this->sql->rows("sis_registration_policy", ["id" => self::POLICY_ID], 1)[0] ??
        throw new CorruptHistoryException();
        $version = filter_var($row["revision"], FILTER_VALIDATE_INT);
        if (
            !in_array($row["schedule_mode"], ["block", "warn"], true) ||
            $version === false ||
            $version < 0 ||
            $version > 2000000000
        ) {
            throw new CorruptHistoryException();
        }
        return ["mode" => $row["schedule_mode"], "revision" => $version];
    }
    public function facultyPolicy(): array
    {
        $row =
        $this->sql->rows("sis_registration_policy", ["id" => self::POLICY_ID], 1)[0] ??
        throw new CorruptHistoryException();
        $revision = filter_var($row["revision"], FILTER_VALIDATE_INT);
        if (
            !in_array($row["faculty_schedule_mode"] ?? null, ["block", "warn"], true) ||
            $revision === false ||
            $revision < 0 ||
            $revision > 2000000000
        ) {
            throw new CorruptHistoryException();
        }
        return ["mode" => $row["faculty_schedule_mode"], "revision" => $revision];
    }
    public function savePolicy(string $mode, int $revision, ?string $facultyMode = null): void
    {
        if (
            !in_array($mode, ["block", "warn"], true) ||
            ($facultyMode !== null && !in_array($facultyMode, ["block", "warn"], true)) ||
            $revision < 0 ||
            $revision >= 2000000000
        ) {
            throw new \InvalidArgumentException("Choose a valid timetable policy.");
        }
        if (
            $this->sql->update(
                "sis_registration_policy",
                ["schedule_mode" => $mode, "revision" => $revision + 1] +
                    ($facultyMode === null ? [] : ["faculty_schedule_mode" => $facultyMode]),
                ["id" => self::POLICY_ID, "revision" => $revision],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("The registration policy changed. Reload it.");
        }
    }
    public function enrollments(EntityId $student, EntityId $target): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT e." .
                $q("id") .
                ", e." .
                $q("section_id") .
                ", e." .
                $q("version") .
                ", p." .
                $q("publication_id") .
                ", h." .
                $q("version") .
                " AS " .
                $q("history_version") .
                " FROM " .
                $q("sis_enrollments") .
                " e" .
                " LEFT JOIN " .
                $q("sis_section_meeting_plans") .
                " p ON p." .
                $q("id") .
                " = e." .
                $q("section_id") .
                " LEFT JOIN " .
                $q("sis_history_streams") .
                " h ON h." .
                $q("aggregate_id") .
                " = e." .
                $q("section_id") .
                " AND h." .
                $q("stream_type") .
                " = ? WHERE e." .
                $q("student_id") .
                " = ? AND e." .
                $q("section_id") .
                " <> ? AND e." .
                $q("status") .
                " IN (?, ?) ORDER BY e." .
                $q("section_id"),
            ["section_meeting_plan", $student->value, $target->value, "A", "N"],
            1000,
        );
    }
}
