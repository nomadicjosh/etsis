<?php

declare(strict_types=1);

namespace Domain\Gradebook\ValueObject;

use DateTimeImmutable;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Gradebook\ValueObject\ExternalCourseGrade;

/** A minimized captured LMS observation; the SIS grade remains an independent decision. */
final readonly class ExternalGradeEvidence
{
    public array $facts;

    public function __construct(#[\SensitiveParameter] array $facts)
    {
        $keys = [
            "kind",
            "candidate_id",
            "binding_id",
            "section_id",
            "section_link_id",
            "person_id",
            "person_link_id",
            "student_id",
            "enrollment_id",
            "moodle_course_id",
            "moodle_user_id",
            "source_hash",
            "observed_at",
            "grade",
        ];
        if (
            count($facts) !== count($keys) ||
            array_diff(array_keys($facts), $keys) !== [] ||
            ($facts["kind"] ?? null) !== "moodle_course_total"
        ) {
            throw new CorruptHistoryException();
        }
        foreach (array_slice($keys, 1, 8) as $key) {
            if (!is_string($facts[$key])) {
                throw new CorruptHistoryException();
            }
            new EntityId($facts[$key]);
        }
        foreach (["moodle_course_id", "moodle_user_id"] as $key) {
            if (
                !is_string($facts[$key]) ||
                preg_match('/^[1-9][0-9]{0,18}$/D', $facts[$key]) !== 1 ||
                (string) (int) $facts[$key] !== $facts[$key]
            ) {
                throw new CorruptHistoryException();
            }
        }
        if (
            !is_string($facts["source_hash"]) ||
            preg_match('/^[a-f0-9]{64}$/D', $facts["source_hash"]) !== 1 ||
            !is_string($facts["observed_at"])
        ) {
            throw new CorruptHistoryException();
        }
        $date = DateTimeImmutable::createFromFormat(
            "!Y-m-d\TH:i:s\Z",
            $facts["observed_at"],
        );
        if (
            !$date ||
            $date->format("Y-m-d\TH:i:s\Z") !== $facts["observed_at"]
        ) {
            throw new CorruptHistoryException();
        }
        if (
            !is_array($facts["grade"]) ||
            new ExternalCourseGrade($facts["grade"])->facts["state"] !==
                "available"
        ) {
            throw new CorruptHistoryException();
        }
        $this->facts = $facts;
    }

    public function summary(): array
    {
        return [
            "kind" => $this->facts["kind"],
            "observed_at" => $this->facts["observed_at"],
            "grade" => array_intersect_key(
                $this->facts["grade"],
                array_flip(["state", "grade", "minimum", "maximum"]),
            ),
        ];
    }
}
