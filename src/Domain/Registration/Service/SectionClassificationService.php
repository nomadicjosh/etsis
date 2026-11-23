<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Catalog\Repository\ClassificationRepository;
use Domain\Registration\Repository\SectionClassificationRepository;
use Domain\Registration\ValueObject\SectionClassifications;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

/** Draft reference assignments are audited CRUD; publication retains the historical definition. */
final readonly class SectionClassificationService
{
    public function __construct(
        private SectionClassificationRepository $sections,
        private ClassificationRepository $references,
        private CatalogRevisionRepository $catalog,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function show(EntityId $section): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $row = $this->catalog->get("sections", $section);
        $values = $this->sections->current($section)->describe();
        $this->authorize($actor);
        return [
            "section" => ["id" => $section->value, "code" => $row["code"]],
            "classifications" => $values,
            "revision" => $this->revision($values),
        ];
    }

    public function assign(
        EntityId $section,
        ?EntityId $method,
        ?EntityId $campus,
        ?EntityId $reportingGroup,
        string $revision,
    ): void {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $ids = ["instructional_method" => $method, "campus" => $campus, "reporting_group" => $reportingGroup];
        $this->transactions->run(function () use (
            $section,
            $ids,
            $revision,
            $actor,
        ): void {
            $this->catalog->lock("sections", $section);
            $this->sections->lockReferences(
                $section,
                array_values(array_filter($ids)),
            );
            $old = $this->sections->current($section)->describe();
            $this->authorize($actor);
            if (!hash_equals($this->revision($old), $revision)) {
                throw new ConcurrentModificationException(
                    "Reload the section classifications before saving.",
                );
            }
            $values = $sources = [];
            foreach ($ids as $kind => $id) {
                $row = $id === null ? null : $this->references->get($id);
                if (
                    $row !== null &&
                    ($row["kind"] !== $kind ||
                        (!$row["active"] &&
                            ($old[$kind]["id"] ?? null) !== $id->value))
                ) {
                    throw new ValidationException([
                        [
                            "field" => $kind . "_id",
                            "message" =>
                                "Choose an active definition from the matching category.",
                        ],
                    ]);
                }
                $sources[$kind] = $row;
                $values[$kind] =
                    $row === null
                        ? null
                        : array_intersect_key(
                            $row,
                            array_flip(["id", "code", "title"]),
                        );
            }
            $this->sections->assign(
                $section,
                SectionClassifications::fromArray($values),
            );
            $this->audit->record(
                $actor,
                "section.classifications_assigned",
                $section,
            );
            $this->authorize($actor);
            if ($this->sections->current($section)->describe() !== $values) {
                throw new ConcurrentModificationException('The section references changed before saving completed.');
            }
            foreach ($ids as $kind => $id) {
                if ($id !== null && $this->references->get($id) !== $sources[$kind]) {
                    throw new ConcurrentModificationException('A selected definition changed before saving completed.');
                }
            }
        });
    }

    /** @param array<string,mixed> $values */
    private function revision(array $values): string
    {
        return hash("sha256", json_encode($values, JSON_THROW_ON_ERROR));
    }

    private function authorize(EntityId $actor): void
    {
        if (
            $this->access->requirePermission("sis:catalog:manage")->value !==
            $actor->value
        ) {
            throw new AccessDeniedException("Access denied.");
        }
    }
}
