<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Enum\ClassificationKind;
use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Catalog\Repository\ClassificationRepository;
use Domain\Catalog\ValueObject\ClassificationSnapshot;
use Domain\Catalog\ValueObject\CourseClassifications;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class ClassificationService
{
    public function __construct(
        private ClassificationRepository $classifications,
        private CatalogRevisionRepository $catalog,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    /** @return array<string, mixed> */
    public function page(ClassificationKind $kind, string $search = "", ?EntityId $after = null): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        if (mb_strlen($search) > 100) {
            throw new InvalidArgumentException("Use a shorter classification search.");
        }
        $rows = $this->classifications->page($kind, trim($search), $after);
        return [
            "items" => array_map(
                fn(array $row): array => $row + ["revision" => $this->rowRevision($row)],
                array_slice($rows, 0, 50),
            ),
            "next_after" => count($rows) > 50 ? $rows[49]["id"] : null,
        ];
    }

    /** @return array<string, mixed> */
    public function save(
        ClassificationKind $kind,
        ?EntityId $id,
        string $code,
        string $title,
        bool $active,
        ?string $revision,
    ): array {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $value = new ClassificationSnapshot(
            $id ?? EntityId::generate(),
            strtoupper(trim($code)),
            trim($title),
        );
        return $this->transactions->run(function () use (
            $kind,
            $id,
            $value,
            $active,
            $revision,
            $actor,
        ): array {
            if ($id !== null) {
                $this->classifications->lock($id);
                $old = $this->classifications->get($id);
                if ($old["kind"] !== $kind->value || $revision !== $this->rowRevision($old)) {
                    throw new ConcurrentModificationException("Reload the classification before saving.");
                }
            }
            $this->authorize($actor);
            $this->classifications->save($kind, $value, $active, $id === null);
            $this->audit->record(
                $actor,
                $id === null ? "classification.created" : "classification.updated",
                $value->id,
            );
            $row = $this->classifications->get($value->id);
            return $row + ["revision" => $this->rowRevision($row)];
        });
    }

    /** @return array<string, mixed> */
    public function course(EntityId $course): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        $row = $this->catalog->get("courses", $course);
        $values = $this->classifications->course($course)->describe();
        return [
            "course" => ["id" => $course->value, "code" => $row["code"], "title" => $row["title"]],
            "classifications" => $values,
            "revision" => $this->revision($values),
        ];
    }

    public function assign(
        EntityId $course,
        ?EntityId $department,
        ?EntityId $academicLevel,
        ?EntityId $courseLevel,
        string $revision,
    ): void {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $ids = [
            "department" => $department,
            "academic_level" => $academicLevel,
            "course_level" => $courseLevel,
        ];
        $this->transactions->run(function () use ($course, $ids, $revision, $actor): void {
            $this->catalog->lock("courses", $course);
            $this->classifications->lockForCourse($course, array_values(array_filter($ids)));
            $this->authorize($actor);
            $old = $this->classifications->course($course)->describe();
            if ($revision !== $this->revision($old)) {
                throw new ConcurrentModificationException("Reload the course classifications before saving.");
            }
            $values = [];
            foreach ($ids as $kind => $id) {
                $row = $id === null ? null : $this->classifications->get($id);
                if (
                    $row !== null &&
                    ($row["kind"] !== $kind ||
                        (!$row["active"] && ($old[$kind]["id"] ?? null) !== $id->value))
                ) {
                    throw new ValidationException([
                        [
                            "field" => $kind . "_id",
                            "message" => "Choose an active classification from the matching category.",
                        ],
                    ]);
                }
                $values[$kind] =
                    $row === null ? null : array_intersect_key($row, array_flip(["id", "code", "title"]));
            }
            $this->classifications->assign($course, CourseClassifications::fromArray($values));
            $this->audit->record($actor, "course.classifications_assigned", $course);
        });
    }

    private function authorize(EntityId $actor): void
    {
        if ($this->access->requirePermission("sis:catalog:manage")->value !== $actor->value) {
            throw new AccessDeniedException("Access denied.");
        }
    }

    /** @param array<string, mixed> $row */
    private function rowRevision(array $row): string
    {
        return $this->revision([
            "id" => $row["id"],
            "kind" => $row["kind"],
            "code" => $row["code"],
            "title" => $row["title"],
            "active" => (bool) $row["active"],
        ]);
    }

    /** @param array<string, mixed> $values */
    private function revision(array $values): string
    {
        return hash("sha256", json_encode($values, JSON_THROW_ON_ERROR));
    }
}
