<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\ClassificationRepository;
use Domain\Catalog\Repository\ReportingHierarchyRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans_html;

final readonly class ReportingHierarchyService
{
    public function __construct(
        private ReportingHierarchyRepository $hierarchy,
        private ClassificationRepository $references,
        private ReportingHierarchyReader $reader,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function show(EntityId $group): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $result = $this->describe($group);
        $this->authorize($actor);
        return $result;
    }

    /** @return array<string,mixed> */
    public function save(EntityId $group, ?EntityId $parent, string $revision): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        return $this->transactions->run(function () use ($group, $parent, $revision, $actor): array {
            $this->hierarchy->lock();
            $version = $this->hierarchy->version();
            if ($this->references->peek($group)['kind'] !== 'reporting_group') {
                throw new ValidationException([
                    ['field' => 'group_id', 'message' => trans_html('Choose a reporting group.')],
                ]);
            }
            $ids = array_column($this->reader->snapshot($group), "id");
            if ($parent !== null) {
                if ($this->references->peek($parent)["kind"] !== "reporting_group") {
                    throw new ValidationException([
                        ["field" => "parent_id", "message" => trans_html("Choose a reporting group.")],
                    ]);
                }
                $ids = array_merge($ids, array_column($this->reader->snapshot($parent), "id"));
            }
            sort($ids, SORT_STRING);
            foreach (array_unique($ids) as $id) {
                $this->references->lock(new EntityId($id));
            }
            $old = $this->describe($group);
            if (!hash_equals($old["revision"], $revision)) {
                throw new ConcurrentModificationException(
                    trans_html("Reload the reporting hierarchy before saving."),
                );
            }
            $parentSource = $parent === null ? null : $this->references->get($parent);
            if (
                $parentSource !== null &&
                (!$parentSource["active"] && ($old["parent"]["id"] ?? null) !== $parent->value)
            ) {
                throw new ValidationException([
                    ["field" => "parent_id", "message" => trans_html("Choose an active reporting group.")],
                ]);
            }
            $relations = $this->hierarchy->relations();
            $relations[$group->value] = $parent?->value;
            ksort($relations, SORT_STRING);
            $this->validate($relations);
            $this->authorize($actor);
            $this->hierarchy->assign($group, $parent);
            $this->hierarchy->advance($version);
            $saved = $this->describe($group);
            $this->audit->record($actor, "reporting_group.hierarchy_saved", $group);
            $this->authorize($actor);
            if (
                $this->describe($group) !== $saved ||
                $this->hierarchy->relations() !== $relations ||
                ($parent !== null && $this->references->get($parent) !== $parentSource)
            ) {
                throw new ConcurrentModificationException(
                    trans_html("The reporting hierarchy changed before saving completed."),
                );
            }
            return $saved;
        });
    }

    /** @param array<string,?string> $relations */
    private function validate(array $relations): void
    {
        if (count($relations) > 500) {
            throw new ValidationException([
                [
                    "field" => "parent_id",
                    "message" => trans_html("Use at most 500 reporting hierarchy entries."),
                ],
            ]);
        }
        foreach (array_keys($relations) as $start) {
            $seen = [];
            for ($id = $start; $id !== null; $id = $relations[$id] ?? null) {
                if (isset($seen[$id]) || count($seen) >= 8) {
                    throw new ValidationException([
                        [
                            "field" => "parent_id",
                            "message" => trans_html(
                                "Use an acyclic reporting hierarchy with at most eight groups in each path.",
                            ),
                        ],
                    ]);
                }
                $seen[$id] = true;
            }
        }
    }

    /** @return array<string,mixed> */
    private function describe(EntityId $group): array
    {
        $source = $this->references->get($group);
        if ($source["kind"] !== "reporting_group") {
            throw new ValidationException([
                ["field" => "group_id", "message" => trans_html("Choose a reporting group.")],
            ]);
        }
        $path = $this->reader->path($group);
        $parent = $this->hierarchy->parent($group);
        $parentSource = $parent === null ? null : $this->references->get($parent);
        return [
            "group" => $path[0],
            "parent" =>
                $parentSource === null
                    ? null
                    : array_intersect_key($parentSource, array_flip(["id", "code", "title", "active"])),
            "path" => $path,
            "revision" => hash(
                "sha256",
                json_encode(
                    [$source, $path, $parentSource, $this->hierarchy->version()],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        ];
    }

    private function authorize(EntityId $actor): void
    {
        if ($this->access->requirePermission("sis:catalog:manage")->value !== $actor->value) {
            throw new AccessDeniedException("Access denied.");
        }
    }
}
