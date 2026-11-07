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
use Domain\Registration\Term;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

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
        $this->access->requirePermission('sis:catalog:manage');
        $row = $this->repository->get($kind, $id);

        return $row + ['revision' => $this->revision($row)];
    }

    public function course(Course $course, string $revision): void
    {
        $this->save('courses', $course->id, $revision, ['code' => $course->code, 'title' => $course->title,
            'credits_milli' => $course->creditsMilli, 'active' => (int) $course->active]);
    }

    public function program(Program $program, string $revision): void
    {
        $this->save('programs', $program->id, $revision, ['code' => $program->code, 'title' => $program->title]);
    }

    public function term(string $code, Term $term, string $revision): void
    {
        $code = strtoupper(trim($code));
        if (preg_match('~^[A-Z0-9][A-Z0-9._/-]{0,21}$~D', $code) !== 1) {
            throw new InvalidArgumentException('A valid term code is required.');
        }
        $this->save('terms', $term->id, $revision, ['code' => $code,
            'starts_on' => $term->dates->start->format('Y-m-d'),
            'ends_on' => $term->dates->end?->format('Y-m-d'),
            'add_drop_ends_on' => $term->addDropEndsOn->format('Y-m-d')]);
    }

    public function section(Section $section, string $revision): void
    {
        $this->save('sections', $section->id, $revision, ['code' => $section->code,
            'faculty_user_id' => $section->facultyUserId?->value, 'capacity' => $section->capacity,
            'active' => (int) $section->active, 'web_registration' => (int) $section->webRegistration,
            'fee_minor' => $section->fee->minor, 'currency' => $section->fee->currency]);
    }

    /** @param array<string, scalar|null> $values */
    private function save(string $kind, EntityId $id, string $revision, array $values): void
    {
        $actor = $this->access->requirePermission('sis:catalog:manage');
        $this->transactions->run(function () use ($kind, $id, $revision, $values, $actor): void {
            $this->repository->lock($kind, $id);
            if (!hash_equals($this->revision($this->repository->get($kind, $id)), $revision)) {
                throw new ConcurrentModificationException('This catalog record changed. Reload before saving.');
            }
            if ($kind === 'sections' && (int) $values['capacity'] < $this->registration->sectionCount($id)) {
                throw new DomainException('Capacity cannot be lower than the active enrollment count.');
            }
            $this->repository->save($kind, $id, $values);
            $this->audit->record($actor, 'catalog.' . $kind . '.updated', $id);
        });
    }

    /** @param array<string, scalar|null> $values */
    private function revision(array $values): string
    {
        ksort($values);
        // Normalize PDO integer/string differences across the supported databases.
        return hash('sha256', json_encode(array_map(
            static fn (mixed $value): ?string => $value === null ? null : (string) $value,
            $values
        ), JSON_THROW_ON_ERROR));
    }
}
