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
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Restriction;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlRegistrationRepository implements RegistrationRepository
{
    public function __construct(private SqlRecords $sql, private Clock $clock)
    {
    }

    public function course(EntityId $id): Course
    {
        $r = $this->sql->one('sis_courses', ['id' => $id->value]);

        return new Course($id, $r['code'], $r['title'], (int) $r['credits_milli'], (bool) $r['active']);
    }

    public function section(EntityId $id): Section
    {
        $r = $this->sql->one('sis_sections', ['id' => $id->value]);

        return new Section(
            $id,
            new EntityId($r['course_id']),
            new EntityId($r['term_id']),
            $r['faculty_user_id'] === null ? null : new EntityId($r['faculty_user_id']),
            $r['code'],
            (int) $r['capacity'],
            (bool) $r['active'],
            (bool) $r['web_registration'],
            new Money((int) $r['fee_minor'], $r['currency'])
        );
    }

    public function term(EntityId $id): Term
    {
        $r = $this->sql->one('sis_terms', ['id' => $id->value]);

        return new Term(
            $id,
            new DateRange(
                new DateTimeImmutable($r['starts_on'], $this->clock->now()->getTimezone()),
                new DateTimeImmutable($r['ends_on'], $this->clock->now()->getTimezone())
            ),
            new DateTimeImmutable($r['add_drop_ends_on'], $this->clock->now()->getTimezone())
        );
    }

    public function window(EntityId $termId): RegistrationWindow
    {
        $r = $this->sql->one('sis_reg_windows', ['term_id' => $termId->value]);

        return new RegistrationWindow(new DateRange(
            new DateTimeImmutable($r['opens_at'], new DateTimeZone('UTC')),
            new DateTimeImmutable($r['closes_at'], new DateTimeZone('UTC'))
        ), (int) $r['course_limit']);
    }

    public function enrollment(EntityId $student, EntityId $section): ?Enrollment
    {
        $rows = $this->sql->rows('sis_enrollments', ['student_id' => $student->value, 'section_id' => $section->value]);
        if ($rows === []) {
            return null;
        }
        $r = $rows[0];

        return new Enrollment(
            new EntityId($r['id']),
            $student,
            $section,
            new EntityId($r['term_id']),
            RegistrationStatus::from($r['status']),
            new DateTimeImmutable($r['registered_at'], new DateTimeZone('UTC')),
            new DateTimeImmutable($r['status_at'], new DateTimeZone('UTC')),
            (int) $r['version']
        );
    }

    public function lockStudent(EntityId $id): void
    {
        $this->sql->lock('sis_students', $id->value, 'active');
    }

    public function lockSection(EntityId $id): void
    {
        $this->sql->lock('sis_sections', $id->value, 'active');
    }

    public function enrolledCount(EntityId $student, EntityId $term): int
    {
        return count(array_filter($this->sql->rows('sis_enrollments', [
            'student_id' => $student->value, 'term_id' => $term->value,
        ]), static fn (array $r): bool => in_array($r['status'], ['A', 'N'], true)));
    }

    public function sectionCount(EntityId $section): int
    {
        return count(array_filter(
            $this->sql->rows('sis_enrollments', ['section_id' => $section->value]),
            static fn (array $r): bool => in_array($r['status'], ['A', 'N'], true)
        ));
    }

    public function prerequisitesMet(EntityId $student, EntityId $course): bool
    {
        $credits = $this->sql->rows('sis_academic_credits', ['student_id' => $student->value]);
        foreach ($this->sql->rows('sis_prerequisites', ['course_id' => $course->value]) as $required) {
            $met = false;
            foreach ($credits as $credit) {
                if (
                    $credit['course_id'] === $required['required_course_id'] && $credit['grade_id'] !== null
                    && (bool) $this->sql->one('sis_grades', ['id' => $credit['grade_id']])['passes']
                    && in_array($credit['status'], ['A', 'N'], true)
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

    public function restrictions(EntityId $person): array
    {
        $result = [];
        foreach ($this->sql->rows('sis_restrictions', ['person_id' => $person->value]) as $r) {
            $result[] = new Restriction(
                new EntityId($r['id']),
                $person,
                $r['code'],
                (int) $r['severity'],
                new DateRange(
                    new DateTimeImmutable($r['starts_on'], $this->clock->now()->getTimezone()),
                    $r['ends_on'] === null ? null
                    : new DateTimeImmutable($r['ends_on'] . ' 23:59:59.999999', $this->clock->now()->getTimezone())
                ),
                (bool) $r['blocks_staff']
            );
        }

        return $result;
    }

    public function addCourse(Course $course): void
    {
        $this->sql->insert('sis_courses', ['id' => $course->id->value, 'code' => $course->code,
            'title' => $course->title,
            'credits_milli' => $course->creditsMilli, 'active' => (int) $course->active]);
    }

    public function addSection(Section $section): void
    {
        $this->sql->insert('sis_sections', ['id' => $section->id->value, 'course_id' => $section->courseId->value,
            'term_id' => $section->termId->value, 'faculty_user_id' => $section->facultyUserId?->value,
            'code' => $section->code, 'capacity' => $section->capacity, 'active' => (int) $section->active,
            'web_registration' => (int) $section->webRegistration, 'fee_minor' => $section->fee->minor,
            'currency' => $section->fee->currency]);
    }

    public function configureWindow(EntityId $termId, RegistrationWindow $window): void
    {
        $this->sql->lock('sis_terms', $termId->value, 'code');
        $data = ['opens_at' => $window->dates->start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'closes_at' => ($window->dates->end ?? throw new \LogicException('Missing window end.'))
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'course_limit' => $window->courseLimit];
        if ($this->sql->rows('sis_reg_windows', ['term_id' => $termId->value]) === []) {
            $this->sql->insert('sis_reg_windows', ['id' => EntityId::generate()->value,
                'term_id' => $termId->value] + $data);
        } else {
            $this->sql->update('sis_reg_windows', $data, ['term_id' => $termId->value]);
        }
    }

    public function removePrerequisite(EntityId $course, EntityId $required): void
    {
        $q = $this->sql->quote(...);
        $this->sql->execute('DELETE FROM ' . $q('sis_prerequisites') . ' WHERE ' . $q('course_id')
            . ' = ? AND ' . $q('required_course_id') . ' = ?', [$course->value, $required->value]);
    }

    public function addPrerequisite(EntityId $course, EntityId $required): void
    {
        $this->sql->insert('sis_prerequisites', ['id' => EntityId::generate()->value,
            'course_id' => $course->value, 'required_course_id' => $required->value]);
    }

    public function addRestriction(Restriction $restriction): void
    {
        $this->sql->insert('sis_restrictions', ['id' => $restriction->id->value,
            'person_id' => $restriction->personId->value,
            'code' => $restriction->code, 'severity' => $restriction->severity,
            'starts_on' => $restriction->dates->start->format('Y-m-d'),
            'ends_on' => $restriction->dates->end?->format('Y-m-d'),
                'blocks_staff' => (int) $restriction->blocksStaffRegistration]);
    }

    public function endRestriction(EntityId $id, DateTimeImmutable $on): EntityId
    {
        $this->sql->lock('sis_restrictions', $id->value, 'severity');
        $row = $this->sql->one('sis_restrictions', ['id' => $id->value]);
        $date = $on->format('Y-m-d');
        if ($date < $row['starts_on'] || ($row['ends_on'] !== null && $row['ends_on'] !== $date)) {
            throw new \DomainException('A restriction must end after it starts; an ended restriction is historical.');
        }
        $this->sql->update('sis_restrictions', ['ends_on' => $date], ['id' => $id->value]);

        return new EntityId($row['person_id']);
    }

    public function addEnrollment(Enrollment $enrollment, Course $course): void
    {
        $this->sql->insert('sis_enrollments', ['id' => $enrollment->id->value,
            'student_id' => $enrollment->studentId->value,
            'section_id' => $enrollment->sectionId->value, 'term_id' => $enrollment->termId->value,
            'status' => $enrollment->status->value, 'registered_at' => $enrollment->registeredAt
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'status_at' => $enrollment->statusAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'version' => 0]);
        $this->sql->insert('sis_academic_credits', ['id' => EntityId::generate()->value,
            'student_id' => $enrollment->studentId->value, 'course_id' => $course->id->value,
            'term_id' => $enrollment->termId->value, 'source_id' => $enrollment->id->value,
                'credit_type' => 'institutional',
            'course_code' => $course->code, 'course_title' => $course->title,
                'attempted_milli' => $course->creditsMilli,
            'completed_milli' => 0, 'grade_points_milli' => 0, 'grade_id' => null,
            'status' => $enrollment->status->value, 'version' => 0]);
    }

    public function saveDrop(Enrollment $enrollment, int $expectedVersion): void
    {
        if (
            $this->sql->update('sis_enrollments', ['status' => $enrollment->status->value,
            'status_at' => $enrollment->statusAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'version' => $enrollment->version], ['id' => $enrollment->id->value, 'version' => $expectedVersion]) !== 1
        ) {
            throw new ConcurrentModificationException('Enrollment changed.');
        }
        $credit = $this->sql->one('sis_academic_credits', ['source_id' => $enrollment->id->value]);
        $this->sql->update(
            'sis_academic_credits',
            ['status' => $enrollment->status->value, 'grade_id' => null,
            'completed_milli' => 0, 'grade_points_milli' => 0, 'version' => (int) $credit['version'] + 1,
            'attempted_milli' => $enrollment->status === RegistrationStatus::Dropped
                ? 0 : (int) $credit['attempted_milli']],
            ['id' => $credit['id']]
        );
    }
}
