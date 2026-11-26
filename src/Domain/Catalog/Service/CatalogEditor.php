<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use DomainException;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Course;
use Domain\Catalog\Program;
use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\Section;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

final readonly class CatalogEditor
{
    public function __construct(
        private CatalogRevisionRepository $repository,
        private RegistrationRepository $registration,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    /** @return array<string, scalar|null> */
    public function view(string $kind, EntityId $id): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $row = $this->repository->get($kind, $id);

        $this->sameActor($actor);
        return $row + ["revision" => $this->revision($row)];
    }

    public function course(Course $course, string $revision): void
    {
        $this->save("courses", $course->id, $revision, [
            "code" => $course->code,
            "title" => $course->title,
            "credits_milli" => $course->creditsMilli,
            "active" => (int) $course->active,
        ]);
    }

    public function creditPolicy(
        EntityId $course,
        int $defaultMilli,
        \Domain\Catalog\ValueObject\CreditPolicy $policy,
        string $revision,
    ): void {
        $policy->requireChoice($defaultMilli);
        $this->save('courses', $course, $revision, ['credits_milli' => $defaultMilli,
            'credit_policy' => json_encode($policy->describe(), JSON_THROW_ON_ERROR)]);
    }

    /** @return array<string, mixed> */
    public function creditSettings(string $kind, EntityId $id): array
    {
        $actor = $this->access->requirePermission('sis:catalog:manage');
        $row = $this->repository->get($kind, $id);
        if ($kind === 'courses') {
            $policy = \Domain\Catalog\ValueObject\CreditPolicy::stored($row['credit_policy'])
            ?? \Domain\Catalog\ValueObject\CreditPolicy::fixed((int) $row['credits_milli']);
            $result = ['credits_milli' => (int) $row['credits_milli'], 'credit_policy' => $policy->describe(),
                'revision' => $this->revision($row)];
        } elseif ($kind === 'sections') {
            $result = $this->sectionCreditContext($id, $row);
        } else {
            throw new \InvalidArgumentException('Choose a course or section credit policy.');
        }
        $this->sameActor($actor);
        return $result;
    }

    public function sectionCredits(
        EntityId $section,
        ?int $creditsMilli,
        string $revision,
        bool $studentSelectable = false
    ): void {
        $actor = $this->access->requirePermission('sis:catalog:manage');
        $this->transactions->run(function () use (
            $section,
            $creditsMilli,
            $revision,
            $actor,
            $studentSelectable,
        ): void {
            $this->repository->lock('sections', $section);
            $row = $this->repository->get('sections', $section);
            $this->repository->lock('courses', new EntityId($row['course_id']));
            $current = $this->sectionCreditContext($section, $row);
            $this->sameActor($actor);
            if (!hash_equals($current['revision'], $revision)) {
                throw new ConcurrentModificationException('The section or its course policy changed. Reload credits.');
            }
            $policy = \Domain\Catalog\ValueObject\CreditPolicy::fromArray($current['credit_policy']);
            if (
                $studentSelectable && ($policy->minimumMilli === $policy->maximumMilli
                || $current['course_version_id'] === null)
            ) {
                throw new \Domain\Shared\Exception\ValidationException([['field' => 'student_selectable',
                    'message' => \Codefy\Framework\Helpers\trans(
                        'Select a published variable-credit course policy before enabling student credit choices.'
                    )]]);
            }
            $policy->requireChoice($creditsMilli ?? $current['credits_milli'], 'offering_credits_milli');
            $this->repository->save('sections', $section, ['offering_credits_milli' => $creditsMilli,
                'student_selectable_credits' => (int) $studentSelectable]);
            $expected = $this->sectionCreditContext($section, $this->repository->get('sections', $section));
            $this->audit->record($actor, 'catalog.section_credits.updated', $section);
            $this->sameActor($actor);
            if ($this->sectionCreditContext($section, $this->repository->get('sections', $section)) !== $expected) {
                throw new ConcurrentModificationException(
                    'The section or its course policy changed before completion.',
                );
            }
        });
    }

    /** @param array<string, scalar|null> $row
     * @return array<string, mixed> */
    private function sectionCreditContext(EntityId $section, array $row): array
    {
        $course = $this->registration->courseForSection($this->registration->section($section));
        $policy = $course->creditPolicy ?? \Domain\Catalog\ValueObject\CreditPolicy::fixed($course->creditsMilli);
        return ['credits_milli' => $course->creditsMilli, 'credit_policy' => $policy->describe(),
            'student_selectable' => (bool) $row['student_selectable_credits'],
            'offering_credits_milli' => $row['offering_credits_milli'] === null
                ? null : (int) $row['offering_credits_milli'],
            'course_version_id' => $course->publicationVersionId?->value,
            'revision' => hash('sha256', json_encode([$this->revision($row), $course->publicationVersionId?->value,
                $course->creditsMilli, $policy->describe()], JSON_THROW_ON_ERROR))];
    }

    /** @return array<string, mixed> */
    public function continuingEducationSettings(string $kind, EntityId $id): array
    {
        $actor = $this->access->requirePermission('sis:catalog:manage');
        $row = $this->repository->get($kind, $id);
        $result = $this->continuingEducationContext($kind, $id, $row);
        $this->sameActor($actor);
        return $result;
    }

    public function continuingEducation(
        string $kind,
        EntityId $id,
        \Domain\Registration\ValueObject\OfferingCeuChoice $choice,
        string $revision,
    ): void {
        if ($kind === 'courses' && $choice->mode === 'inherit') {
            throw new \InvalidArgumentException('Choose declared or no course CEUs.');
        }
        $actor = $this->access->requirePermission('sis:catalog:manage');
        $this->transactions->run(function () use ($kind, $id, $choice, $revision, $actor): void {
            $this->repository->lock($kind, $id);
            $row = $this->repository->get($kind, $id);
            if ($kind === 'sections') {
                $this->repository->lock('courses', new EntityId($row['course_id']));
            }
            $current = $this->continuingEducationContext($kind, $id, $row);
            $this->sameActor($actor);
            if (!hash_equals($current['revision'], $revision)) {
                throw new ConcurrentModificationException('The CEU source changed. Reload before saving.');
            }
            $value = $kind === 'courses' ? $choice->resolve(null)->describe() : $choice->describe();
            $this->repository->save($kind, $id, [
                'continuing_education' => json_encode($value, JSON_THROW_ON_ERROR),
            ]);
            $expected = $this->continuingEducationSettings($kind, $id);
            $this->audit->record($actor, 'catalog.' . $kind . '.continuing_education.updated', $id);
            $this->sameActor($actor);
            if ($this->continuingEducationSettings($kind, $id) !== $expected) {
                throw new ConcurrentModificationException('The CEU source changed before completion.');
            }
        });
    }

    /** @param array<string, scalar|null> $row
     * @return array<string, mixed> */
    private function continuingEducationContext(string $kind, EntityId $id, array $row): array
    {
        if ($kind === 'courses') {
            $ceu = \Domain\Catalog\ValueObject\ContinuingEducationUnits::stored($row['continuing_education']);
            return ['mode' => $ceu?->milli === null ? 'none' : 'fixed', 'ceu_milli' => $ceu?->milli,
                'revision' => $this->revision($row)];
        }
        if ($kind !== 'sections') {
            throw new \InvalidArgumentException('Choose a course or section CEU draft.');
        }
        $course = $this->registration->courseForSection($this->registration->section($id));
        $choice = \Domain\Registration\ValueObject\OfferingCeuChoice::stored($row['continuing_education']);
        return $choice->describe() + ['course_continuing_education' => $course->continuingEducation?->describe(),
            'course_version_id' => $course->publicationVersionId?->value,
            'revision' => hash('sha256', json_encode([$this->revision($row), $course->publicationVersionId?->value,
                $course->continuingEducation?->describe()], JSON_THROW_ON_ERROR))];
    }

    public function courseText(EntityId $course, \Domain\Catalog\ValueObject\CourseText $text, string $revision): void
    {
        $this->save("courses", $course, $revision, $text->describe());
    }

    public function program(Program $program, string $revision): void
    {
        $this->save("programs", $program->id, $revision, ["code" => $program->code, "title" => $program->title]);
    }

    public function section(Section $section, string $revision): void
    {
        $this->save("sections", $section->id, $revision, [
            "code" => $section->code,
            "faculty_user_id" => $section->facultyUserId?->value,
            "capacity" => $section->capacity,
            "minimum_enrollment" => $section->minimumEnrollment,
            "active" => (int) $section->active,
            "web_registration" => (int) $section->webRegistration,
            "fee_minor" => $section->fee->minor,
            "currency" => $section->fee->currency,
            "delivery_mode" => $section->deliveryMode->value,
        ]);
    }

    /** @param array<string, scalar|null> $values */
    private function save(string $kind, EntityId $id, string $revision, array $values): void
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $this->transactions->run(function () use ($kind, $id, $revision, $values, $actor): void {
            $this->repository->lock($kind, $id);
            $this->sameActor($actor);
            $current = $this->repository->get($kind, $id);
            if (!hash_equals($this->revision($current), $revision)) {
                throw new ConcurrentModificationException("This catalog record changed. Reload before saving.");
            }
            if ($kind === 'courses') {
                $proposed = array_replace($current, $values);
                \Domain\Catalog\ValueObject\CreditPolicy::stored($proposed['credit_policy'])
                    ?->requireChoice((int) $proposed['credits_milli']);
            }
            if (
                $kind === "sections" && isset($values['capacity'])
                && (int) $values["capacity"] < $this->registration->sectionCount($id)
            ) {
                throw new DomainException("Capacity cannot be lower than the active enrollment count.");
            }
            $this->repository->save($kind, $id, $values);
            $saved = $this->revision($this->repository->get($kind, $id));
            $this->audit->record($actor, "catalog." . $kind . ".updated", $id);
            $this->sameActor($actor);
            if (!hash_equals($saved, $this->revision($this->repository->get($kind, $id)))) {
                throw new ConcurrentModificationException('The catalog record changed before completion.');
            }
        });
    }

    private function sameActor(EntityId $actor): void
    {
        if (
            $this->access->requirePermission('sis:catalog:manage')->value !== $actor->value ||
            $this->access->authenticatedUserId()->value !== $actor->value
        ) {
            throw new \Domain\Shared\Exception\AccessDeniedException();
        }
    }

    /** @param array<string, scalar|null> $values */
    private function revision(array $values): string
    {
        ksort($values);
        // Normalize PDO integer/string differences across the supported databases.
        return hash(
            "sha256",
            json_encode(
                array_map(static fn(mixed $value): ?string => $value === null ? null : (string) $value, $values),
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
