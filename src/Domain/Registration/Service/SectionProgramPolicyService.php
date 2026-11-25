<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Registration\Repository\SectionProgramPolicyRepository;
use Domain\Registration\ValueObject\SectionProgramPolicy;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

/** Audited catalog drafts only; publication/selection is a separate consequential decision. */
final readonly class SectionProgramPolicyService
{
    public function __construct(
        private SectionProgramPolicyRepository $policies,
        private CatalogRevisionRepository $catalog,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    private function authorize(?EntityId $actor = null): EntityId
    {
        $now = $this->access->requirePermission("sis:catalog:manage");
        if ($actor !== null && $now->value !== $actor->value) {
            throw new AccessDeniedException("Access denied.");
        }
        return $now;
    }

    public function current(EntityId $section): array
    {
        $actor = $this->authorize();
        return $this->transactions->run(function () use ($section, $actor): array {
            $this->catalog->lock("sections", $section);
            $row = $this->catalog->get("sections", $section);
            $policy = $this->policies->current($section);
            $programs = $this->policies->programs($section);
            $this->authorize($actor);
            return [
                "section_code" => $row["code"],
                "program_ids" => $policy->programIds,
                "programs" => $programs,
                "revision" => self::revision($policy),
            ];
        });
    }

    public function choices(EntityId $section, string $search, ?EntityId $after = null): array
    {
        $actor = $this->authorize();
        if (mb_strlen($search) > 80) {
            throw new ValidationException([
                [
                    "field" => "search",
                    "message" => \Codefy\Framework\Helpers\trans("Use a search of at most 80 characters."),
                ],
            ]);
        }
        $this->catalog->get("sections", $section);
        $rows = $this->policies->choices($search, $after);
        $this->authorize($actor);
        return [
            "items" => array_slice($rows, 0, 20),
            "next_after" => count($rows) > 20 ? $rows[19]["id"] : null,
        ];
    }

    public function save(EntityId $section, array $ids, string $revision): array
    {
        $actor = $this->authorize();
        try {
            if (!array_is_list($ids) || count($ids) > 20) {
                throw new \InvalidArgumentException();
            }
            $ids = array_map(static fn($id): string => new EntityId($id)->value, $ids);
            sort($ids, SORT_STRING);
            $policy = new SectionProgramPolicy($ids);
            if (preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable) {
            throw new ValidationException([
                [
                    "field" => "program_ids",
                    "message" => \Codefy\Framework\Helpers\trans(
                        "Choose up to twenty distinct programs and reload a valid saved revision.",
                    ),
                ],
            ]);
        }
        return $this->transactions->run(function () use ($section, $actor, $policy, $revision): array {
            $this->catalog->lock("sections", $section);
            $this->catalog->get("sections", $section);
            $this->policies->lockReferences($section, $policy->programIds);
            $this->authorize($actor);
            $old = $this->policies->current($section);
            if (!hash_equals(self::revision($old), $revision)) {
                throw new ConcurrentModificationException(
                    \Codefy\Framework\Helpers\trans(
                        "The eligible programs changed. Reload the saved policy.",
                    ),
                );
            }
            $this->validReferences($policy);
            $this->policies->assign($section, $policy);
            $this->audit->record($actor, "section.program_eligibility_saved", $section);
            $this->authorize($actor);
            $this->validReferences($policy);
            if ($this->policies->current($section)->programIds !== $policy->programIds) {
                throw new ConcurrentModificationException(
                    \Codefy\Framework\Helpers\trans("The section program policy changed while saving."),
                );
            }
            return ["program_ids" => $policy->programIds, "revision" => self::revision($policy)];
        });
    }

    private function validReferences(SectionProgramPolicy $policy): void
    {
        foreach ($policy->programIds as $id) {
            $this->policies->program(new EntityId($id));
        }
    }

    private static function revision(SectionProgramPolicy $policy): string
    {
        return hash("sha256", json_encode($policy->programIds, JSON_THROW_ON_ERROR));
    }
}
