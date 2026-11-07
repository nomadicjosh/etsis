<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use DateTimeImmutable;
use DomainException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Repository\TransferRepository;
use Domain\Transfer\TransferEquivalency;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlTransferRepository implements TransferRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function addInstitution(EntityId $id, string $code, string $name): void
    {
        $this->sql->insert('sis_external_institutions', ['id' => $id->value, 'code' => $code, 'name' => $name]);
    }

    public function addExternalCourse(EntityId $id, EntityId $institution, string $code, string $title): void
    {
        $this->sql->insert('sis_external_courses', ['id' => $id->value, 'institution_id' => $institution->value,
            'code' => $code, 'title' => $title]);
    }

    public function addEquivalency(TransferEquivalency $equivalency): void
    {
        $course = $this->sql->one('sis_external_courses', ['id' => $equivalency->externalCourseId->value]);
        if ($course['institution_id'] !== $equivalency->externalInstitutionId->value) {
            throw new DomainException('The external institution does not match the course.');
        }
        $grade = $this->sql->one('sis_grades', ['code' => $equivalency->awardedGrade]);
        if (!(bool) $grade['passes']) {
            throw new DomainException('Transfer credit requires a passing awarded grade.');
        }
        $this->sql->insert('sis_transfer_equivalencies', ['id' => $equivalency->id->value,
            'external_course_id' => $equivalency->externalCourseId->value,
            'course_id' => $equivalency->institutionalCourseId->value,
            'starts_on' => $equivalency->effectiveDates->start->format('Y-m-d'),
            'ends_on' => $equivalency->effectiveDates->end?->format('Y-m-d'),
            'minimum_grade_rank' => $equivalency->minimumGradeRank,
            'credits_milli' => $equivalency->creditThousandths, 'grade_id' => $grade['id']]);
    }

    public function equivalency(EntityId $id): TransferEquivalency
    {
        $r = $this->sql->one('sis_transfer_equivalencies', ['id' => $id->value]);
        $external = $this->sql->one('sis_external_courses', ['id' => $r['external_course_id']]);
        $grade = $this->sql->one('sis_grades', ['id' => $r['grade_id']]);

        return new TransferEquivalency(
            $id,
            new EntityId($external['institution_id']),
            new EntityId($external['id']),
            new EntityId($r['course_id']),
            new DateRange(
                new DateTimeImmutable($r['starts_on']),
                $r['ends_on'] === null ? null : new DateTimeImmutable($r['ends_on'] . ' 23:59:59.999999')
            ),
            (int) $r['minimum_grade_rank'],
            (int) $r['credits_milli'],
            $grade['code']
        );
    }

    public function award(
        EntityId $id,
        EntityId $student,
        TransferEquivalency $equivalency,
        EntityId $term,
        DateTimeImmutable $completedOn
    ): EntityId {
        $criteria = ['student_id' => $student->value, 'equivalency_id' => $equivalency->id->value,
            'completed_on' => $completedOn->format('Y-m-d')];
        $existing = $this->sql->rows('sis_transfer_awards', $criteria);
        if ($existing !== []) {
            if ($existing[0]['term_id'] !== $term->value) {
                throw new DomainException('This transfer award already belongs to another term.');
            }
            return new EntityId($existing[0]['credit_id']);
        }
        $grade = $this->sql->one('sis_grades', ['code' => $equivalency->awardedGrade]);
        $course = $this->sql->one('sis_courses', ['id' => $equivalency->institutionalCourseId->value]);
        $creditId = EntityId::generate();
        $this->sql->insert('sis_academic_credits', ['id' => $creditId->value, 'student_id' => $student->value,
            'course_id' => $equivalency->institutionalCourseId->value, 'term_id' => $term->value,
            'source_id' => $id->value, 'credit_type' => 'transfer', 'course_code' => $course['code'],
            'course_title' => $course['title'], 'attempted_milli' => $equivalency->creditThousandths,
            'completed_milli' => $equivalency->creditThousandths,
            'grade_points_milli' => (bool) $grade['counts_gpa']
                ? intdiv($equivalency->creditThousandths * (int) $grade['points_milli'], 1000) : 0,
            'grade_id' => $grade['id'], 'status' => 'A', 'version' => 0]);
        $this->sql->insert('sis_transfer_awards', ['id' => $id->value] + $criteria
            + ['term_id' => $term->value, 'credit_id' => $creditId->value]);

        return $creditId;
    }

    public function transcript(EntityId $student): array
    {
        $personId = $this->sql->one('sis_students', ['id' => $student->value])['person_id'];
        $identity = $this->sql->one('sis_person_identifiers', ['person_id' => $personId]);
        $credits = $this->sql->rows('sis_academic_credits', ['student_id' => $student->value]);
        foreach ($credits as &$credit) {
            $credit['institutional_id'] = $identity['institutional_id'];
            $grade = $credit['grade_id'] === null ? null : $this->sql->one('sis_grades', ['id' => $credit['grade_id']]);
            $credit['grade_code'] = $grade['code'] ?? ($credit['status'] === 'W' ? 'W' : null);
            $credit['counts_gpa'] = (bool) ($grade['counts_gpa'] ?? false);
        }
        unset($credit);

        return $credits;
    }
}
