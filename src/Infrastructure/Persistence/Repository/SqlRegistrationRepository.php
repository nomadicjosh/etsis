<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Catalog\Course;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Enrollment;
use Domain\Registration\Enum\RegistrationStatus;
use Domain\Registration\RegistrationWindow;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\Section;
use Domain\Registration\Term;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Restriction;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlRegistrationRepository implements RegistrationRepository
{
    public function __construct(
        private SqlRecords $sql,
        private Clock $clock,
        private \Domain\Shared\Persistence\UnitOfWork $transactions,
    ) {
    }

    public function course(EntityId $id): Course
    {
        $q = $this->sql->quote(...);
        $r =
        $this->sql->fetch(
            "SELECT c.*, x." .
                    $q("published_transcript_title") .
                    " FROM " .
                    $q("sis_courses") .
                    " c LEFT JOIN " .
                    $q("sis_course_descriptions") .
                    " x ON x." .
                    $q("course_id") .
                    " = c." .
                    $q("id") .
                    " WHERE c." .
                    $q("id") .
                    " = ?",
            [$id->value],
            1,
        )[0] ?? throw new \Domain\Shared\Exception\RecordNotFoundException("Course not found.");

        return new Course(
            $id,
            $r["code"],
            $r["title"],
            (int) $r["credits_milli"],
            (bool) $r["active"],
            $r["published_transcript_title"],
        );
    }

    public function courseForSection(Section $section): Course
    {
        $current = $this->course($section->courseId);
        $bound = new SqlSectionCourseBindingRepository($this->sql)->bound($section->id);
        if ($bound === null) {
            return $current;
        }
        return new Course(
            $bound->courseId,
            $bound->core->code,
            $bound->core->title,
            $bound->core->creditsMilli,
            $current->active,
            $bound->text?->transcriptTitle,
            $bound->versionId,
            $bound->core->prerequisites,
        );
    }

    public function section(EntityId $id): Section
    {
        $q = $this->sql->quote(...);
        $r =
        $this->sql->fetch(
            "SELECT s.*, d." .
                    $q("mode") .
                    " AS " .
                    $q("delivery_mode") .
                    " FROM " .
                    $q("sis_sections") .
                    " s LEFT JOIN " .
                    $q("sis_section_delivery") .
                    " d ON d." .
                    $q("section_id") .
                    " = s." .
                    $q("id") .
                    " WHERE s." .
                    $q("id") .
                    " = ?",
            [$id->value],
            1,
        )[0] ?? throw new \Domain\Shared\Exception\RecordNotFoundException("Section not found.");

        return new Section(
            $id,
            new EntityId($r["course_id"]),
            new EntityId($r["term_id"]),
            $r["faculty_user_id"] === null ? null : new EntityId($r["faculty_user_id"]),
            $r["code"],
            (int) $r["capacity"],
            (bool) $r["active"],
            (bool) $r["web_registration"],
            new Money((int) $r["fee_minor"], $r["currency"]),
            \Domain\Registration\Enum\DeliveryMode::parse($r["delivery_mode"] ?? "unspecified"),
        );
    }

    public function term(EntityId $id): Term
    {
        $r = $this->sql->one("sis_terms", ["id" => $id->value]);

        return new Term(
            $id,
            new DateRange(
                new DateTimeImmutable($r["starts_on"], $this->clock->now()->getTimezone()),
                new DateTimeImmutable($r["ends_on"], $this->clock->now()->getTimezone()),
            ),
            new DateTimeImmutable($r["add_drop_ends_on"], $this->clock->now()->getTimezone()),
        );
    }

    public function window(EntityId $termId): RegistrationWindow
    {
        $r = $this->sql->one("sis_reg_windows", ["term_id" => $termId->value]);

        return new RegistrationWindow(
            new DateRange(
                new DateTimeImmutable($r["opens_at"], new DateTimeZone("UTC")),
                new DateTimeImmutable($r["closes_at"], new DateTimeZone("UTC")),
            ),
            (int) $r["course_limit"],
        );
    }

    public function enrollment(EntityId $student, EntityId $section): ?Enrollment
    {
        $rows = $this->sql->rows("sis_enrollments", [
            "student_id" => $student->value,
            "section_id" => $section->value,
        ]);
        if ($rows === []) {
            return null;
        }
        $r = $rows[0];

        return new Enrollment(
            new EntityId($r["id"]),
            $student,
            $section,
            new EntityId($r["term_id"]),
            RegistrationStatus::from($r["status"]),
            new DateTimeImmutable($r["registered_at"], new DateTimeZone("UTC")),
            new DateTimeImmutable($r["status_at"], new DateTimeZone("UTC")),
            (int) $r["version"],
        );
    }

    public function lockPerson(EntityId $id): void
    {
        $this->sql->lock("sis_persons", $id->value, "id");
    }

    public function lockStudent(EntityId $id): void
    {
        $this->sql->lock("sis_students", $id->value, "active");
    }

    public function lockCourse(EntityId $id): void
    {
        $this->sql->lock("sis_courses", $id->value, "id");
    }

    public function lockSection(EntityId $id): void
    {
        $this->sql->lock("sis_sections", $id->value, "active");
    }

    public function enrolledCount(EntityId $student, EntityId $term): int
    {
        $q = $this->sql->quote(...);
        return (int) $this->sql->fetch(
            "SELECT COUNT(*) AS " .
                $q("amount") .
                " FROM " .
                $q("sis_enrollments") .
                " WHERE " .
                $q("student_id") .
                " = ? AND " .
                $q("term_id") .
                " = ? AND " .
                $q("status") .
                " IN (?, ?)",
            [$student->value, $term->value, "A", "N"],
            1,
        )[0]["amount"];
    }
    public function sectionCount(EntityId $section): int
    {
        $q = $this->sql->quote(...);
        return (int) $this->sql->fetch(
            "SELECT COUNT(*) AS " .
                $q("amount") .
                " FROM " .
                $q("sis_enrollments") .
                " WHERE " .
                $q("section_id") .
                " = ? AND " .
                $q("status") .
                " IN (?, ?)",
            [$section->value, "A", "N"],
            1,
        )[0]["amount"];
    }
    public function requiredCourses(EntityId $course): array
    {
        $rows = $this->sql->rows("sis_prerequisites", ["course_id" => $course->value], 101);
        if (count($rows) > 100) {
            throw new \Domain\Shared\Exception\ValidationException([
                [
                    "field" => "prerequisites",
                    "message" => "Review this course’s prerequisite policy before registration.",
                ],
            ]);
        }
        $ids = array_column($rows, "required_course_id");
        sort($ids, SORT_STRING);
        return $ids;
    }

    public function prerequisitesMet(EntityId $student, EntityId $course, ?array $required = null): bool
    {
        $credits = $this->sql->rows("sis_academic_credits", ["student_id" => $student->value]);
        $requirements =
        $required === null
        ? $this->sql->rows("sis_prerequisites", ["course_id" => $course->value])
        : array_map(static fn(string $id): array => ["required_course_id" => $id], $required);
        foreach ($requirements as $required) {
            $met = false;
            foreach ($credits as $credit) {
                if (
                    $credit["course_id"] === $required["required_course_id"] &&
                    $credit["grade_id"] !== null &&
                    (bool) $this->sql->one("sis_grades", ["id" => $credit["grade_id"]])["passes"] &&
                    in_array($credit["status"], ["A", "N"], true)
                ) {
                    $met = true;
                    break;
                }
            }
            if (!$met) {
                return false;
            }
        }

        return true;
    }

    public function blockingRestriction(EntityId $person, DateTimeImmutable $on, bool $byStaff): ?Restriction
    {
        $q = $this->sql->quote(...);
        $date = $on->format("Y-m-d");
        $rows = $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_restrictions") .
                " WHERE " .
                $q("person_id") .
                " = ? AND " .
                $q("starts_on") .
                " <= ? AND (" .
                $q("ends_on") .
                " IS NULL OR " .
                $q("ends_on") .
                " >= ?) AND " .
                $q($byStaff ? "blocks_staff" : "severity") .
                ($byStaff ? " = ?" : " >= ?"),
            [$person->value, $date, $date, $byStaff ? 1 : Restriction::REGISTRATION_BLOCKING_SEVERITY],
            1,
        );
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];

        return new Restriction(
            new EntityId($row["id"]),
            $person,
            $row["code"],
            (int) $row["severity"],
            new DateRange(
                new DateTimeImmutable($row["starts_on"], $on->getTimezone()),
                $row["ends_on"] === null ? null : new DateTimeImmutable($row["ends_on"], $on->getTimezone()),
            ),
            (bool) $row["blocks_staff"],
        );
    }

    public function addCourse(Course $course): void
    {
        $this->sql->insert("sis_courses", [
            "id" => $course->id->value,
            "code" => $course->code,
            "title" => $course->title,
            "credits_milli" => $course->creditsMilli,
            "active" => (int) $course->active,
        ]);
    }

    public function addSection(Section $section): void
    {
        $this->transactions->run(function () use ($section): void {
            $this->sql->insert("sis_sections", [
                "id" => $section->id->value,
                "course_id" => $section->courseId->value,
                "term_id" => $section->termId->value,
                "faculty_user_id" => $section->facultyUserId?->value,
                "code" => $section->code,
                "capacity" => $section->capacity,
                "active" => (int) $section->active,
                "web_registration" => (int) $section->webRegistration,
                "fee_minor" => $section->fee->minor,
                "currency" => $section->fee->currency,
            ]);
            $this->sql->insert("sis_section_delivery", [
                "id" => EntityId::generate()->value,
                "section_id" => $section->id->value,
                "mode" => $section->deliveryMode->value,
            ]);
        });
    }

    public function configureWindow(EntityId $termId, RegistrationWindow $window): void
    {
        $this->sql->lock("sis_terms", $termId->value, "code");
        $data = [
            "opens_at" => $window->dates->start->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d H:i:s"),
            "closes_at" => ($window->dates->end ?? throw new \LogicException("Missing window end."))
                ->setTimezone(new DateTimeZone("UTC"))
                ->format("Y-m-d H:i:s"),
            "course_limit" => $window->courseLimit,
        ];
        if ($this->sql->rows("sis_reg_windows", ["term_id" => $termId->value]) === []) {
            $this->sql->insert(
                "sis_reg_windows",
                ["id" => EntityId::generate()->value, "term_id" => $termId->value] + $data,
            );
        } else {
            $this->sql->update("sis_reg_windows", $data, ["term_id" => $termId->value]);
        }
    }

    public function removePrerequisite(EntityId $course, EntityId $required): void
    {
        $q = $this->sql->quote(...);
        $this->sql->execute(
            "DELETE FROM " .
                $q("sis_prerequisites") .
                " WHERE " .
                $q("course_id") .
                " = ? AND " .
                $q("required_course_id") .
                " = ?",
            [$course->value, $required->value],
        );
    }

    public function addPrerequisite(EntityId $course, EntityId $required): void
    {
        $this->sql->insert("sis_prerequisites", [
            "id" => EntityId::generate()->value,
            "course_id" => $course->value,
            "required_course_id" => $required->value,
        ]);
    }
}
