<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\ProgramSpecialization;
use Domain\Catalog\ValueObject\CurriculumComponentKind;
use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Catalog\Repository\ProgramSpecializationRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class ProgramSpecializationService
{
    public function __construct(
        private ProgramSpecializationRepository $specializations,
        private CatalogRevisionRepository $programs,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private \Domain\Catalog\Repository\ClassificationRepository $classifications,
    ) {
    }

    /** @return array<string,mixed> */
    public function page(
        EntityId $program,
        string $search = "",
        ?EntityId $after = null,
        ?CurriculumComponentKind $kind = null,
    ): array {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        if (
            mb_strlen($search) > 100 ||
            !mb_check_encoding($search, "UTF-8") ||
            preg_match('/[\x00-\x1F\x7F]/', $search)
        ) {
            throw new InvalidArgumentException("Use a shorter specialization search.");
        }
        $parent = $this->programs->get("programs", $program);
        $items = $this->specializations->page($program, trim($search), $after, $kind);
        $this->authorize($actor);
        return [
            "program" => [
                "id" => $program->value,
                "code" => $parent["code"],
                "title" => $parent["title"],
            ],
            "items" => array_slice($items, 0, 20),
            "next_after" => count($items) > 20 ? $items[19]["id"] : null,
        ];
    }

    /** @return array<string,mixed> */
    public function get(EntityId $program, EntityId $id): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $this->programs->get("programs", $program);
        $value = $this->specializations->get($program, $id);
        $result = $this->presentation($value);
        $this->authorize($actor);
        return $result;
    }

    /** Nullable identity/revision are required command inputs; null explicitly requests a new reference.
     * @return array<string,mixed> */
    public function save(
        EntityId $program,
        ?EntityId $id,
        ?int $expectedRevision,
        string $code,
        string $title,
        ?string $description,
        bool $active,
        CurriculumComponentKind $kind = CurriculumComponentKind::Specialization,
        ?EntityId $department = null,
        ?EntityId $parentMajor = null,
        array $additionalDepartments = [],
    ): array {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        if (
            ($id === null) !== ($expectedRevision === null) ||
            ($expectedRevision !== null &&
                ($expectedRevision < 1 || $expectedRevision >= 2000000000))
        ) {
            throw new InvalidArgumentException(
                "Supply the saved revision when editing a specialization.",
            );
        }
        foreach (
            ["code" => $code, "title" => $title, "description" => $description] as $field => $text
        ) {
            $pattern =
            $field === "description"
            ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'
            : '/[\x00-\x1F\x7F]/';
            if ($text !== null && preg_match($pattern, $text)) {
                throw new \Domain\Shared\Exception\ValidationException([
                    ["field" => $field, "message" => "Remove unsupported control characters."],
                ]);
            }
        }
        $value = new ProgramSpecialization(
            $id ?? EntityId::generate(),
            $program,
            strtoupper(trim($code)),
            trim($title),
            $description === null || trim($description) === "" ? null : trim($description),
            $active,
            ($expectedRevision ?? 0) + 1,
            $kind,
            $department,
            $parentMajor,
            $additionalDepartments,
        );
        return $this->transactions->run(function () use (
            $program,
            $id,
            $expectedRevision,
            $actor,
            $value,
        ): array {
            $this->programs->lock("programs", $program);
            $this->authorize($actor);
            if (
                $id !== null &&
                $this->specializations->get($program, $id)->revision !== $expectedRevision
            ) {
                throw new ConcurrentModificationException(
                    "Reload this specialization before saving your changes.",
                );
            }
            $old = $id === null ? null : $this->specializations->get($program, $id);
            $previous = $old?->departmentId;
            $previousOwners = array_filter([$previous, ...($old?->additionalDepartmentIds ?? [])]);
            $newOwners = array_filter([$value->departmentId, ...$value->additionalDepartmentIds]);
            $oldIds = array_map(static fn (EntityId $owner): string => $owner->value, $previousOwners);
            $references = [];
            foreach ([...$previousOwners, ...$newOwners] as $reference) {
                $references[] = $reference->value;
                $school = $this->classifications->get($reference)["school_id"];
                if ($school !== null) {
                    $references[] = $school;
                }
            }
            sort($references, SORT_STRING);
            foreach (array_unique($references) as $reference) {
                $this->classifications->lock(new EntityId($reference));
            }
            $ownersBefore = [];
            foreach ($newOwners as $owner) {
                $definition = $this->department($owner, in_array($owner->value, $oldIds, true) ? $owner : null);
                if ($definition['school'] !== null && !in_array($definition['school']['id'], $references, true)) {
                    throw new ConcurrentModificationException(
                        'A department moved schools while saving. Review its organization again.',
                    );
                }
                $ownersBefore[$owner->value] = $definition;
            }
            $oldParent =
                $id === null ? null : $this->specializations->get($program, $id)->parentMajorId;
            $majorBefore = $this->parent($value, $oldParent);
            $this->specializations->save($value, $id === null);
            $this->audit->record(
                $actor,
                $id === null ? "program.specialization_created" : "program.specialization_updated",
                $value->id,
            );
            $this->authorize($actor);
            if (
                $this->specializations->get($program, $value->id)->describe() !== $value->describe()
            ) {
                throw new ConcurrentModificationException(
                    "The component changed before saving completed.",
                );
            }
            foreach ($newOwners as $owner) {
                if (
                    $this->department($owner, in_array($owner->value, $oldIds, true) ? $owner : null)
                    !== $ownersBefore[$owner->value]
                ) {
                    throw new ConcurrentModificationException('Curriculum ownership changed before saving completed.');
                }
            }
            if ($this->parent($value, $oldParent) !== $majorBefore) {
                throw new ConcurrentModificationException(
                    "The required major changed before saving completed.",
                );
            }
            $result = $this->presentation($value);
            $this->authorize($actor);
            return $result;
        });
    }

    private function presentation(ProgramSpecialization $value): array
    {
        return $value->describe() + [
            "parent_major" =>
                $value->parentMajorId === null
                    ? null
                    : array_intersect_key(
                        $this->specializations
                            ->get($value->programId, $value->parentMajorId)
                            ->describe(),
                        array_flip(["id", "code", "title"]),
                    ),
            "department" => $this->department($value->departmentId, $value->departmentId),
            'additional_departments' => array_map(
                fn (EntityId $id): array => $this->department($id, $id),
                $value->additionalDepartmentIds
            ),
        ];
    }
    private function parent(ProgramSpecialization $value, ?EntityId $previous): ?array
    {
        if ($value->parentMajorId === null) {
            return null;
        }
        if (
            $value->parentMajorId->value === $value->id->value ||
            !in_array(
                $value->kind?->value,
                \Domain\Catalog\ValueObject\ComponentMajorRules::CHILD_KINDS,
                true,
            )
        ) {
            throw new \Domain\Shared\Exception\ValidationException([
                [
                    "field" => "parent_major_id",
                    "message" =>
                        "Only concentrations, tracks and specializations can require a different major.",
                ],
            ]);
        }
        $parent = $this->specializations->get($value->programId, $value->parentMajorId);
        if (
            $parent->kind !== CurriculumComponentKind::Major ||
            (!$parent->active && $parent->id->value !== $previous?->value)
        ) {
            throw new \Domain\Shared\Exception\ValidationException([
                [
                    "field" => "parent_major_id",
                    "message" => "Choose an active major within this program.",
                ],
            ]);
        }
        return $parent->describe();
    }

    private function department(?EntityId $id, ?EntityId $previous): ?array
    {
        if ($id === null) {
            return null;
        }
        $row = $this->classifications->get($id);
        if (
            $row["kind"] !== "department" ||
            (!$row["active"] && $id->value !== $previous?->value)
        ) {
            throw new \Domain\Shared\Exception\ValidationException([
                [
                    "field" => "department_id",
                    "message" => "Choose an active responsible department.",
                ],
            ]);
        }
        return \Domain\Catalog\ValueObject\DepartmentDefinition::fromClassification($row)->data;
    }

    private function authorize(EntityId $actor): void
    {
        if (
            $this->access->requirePermission("sis:catalog:manage")->value !== $actor->value
            || $this->access->authenticatedUserId()->value !== $actor->value
        ) {
            throw new AccessDeniedException("Access denied.");
        }
    }
}
