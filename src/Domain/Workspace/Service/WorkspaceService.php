<?php

declare(strict_types=1);

namespace Domain\Workspace\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Service\AdmissionsService;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\Workspace\Repository\WorkspaceRepository;
use InvalidArgumentException;

final readonly class WorkspaceService
{
    public const array DATASETS = [
        "people" => "sis:person:manage",
        "applications" => "sis:admissions:manage",
        "registration" => "sis:registration:manage",
        "registrationstudents" => "sis:registration:manage",
        "transferstudents" => "sis:transfer:manage",
        "students" => "sis:student:read",
        "programs" => "",
        "terms" => "",
        "courses" => "sis:catalog:manage",
        "sections" => "sis:registration:manage",
        "rooms" => "sis:scheduling:manage",
        "buildings" => "sis:scheduling:manage",
        "bookingcategories" => "sis:scheduling:manage",
        "activebookingcategories" => "sis:scheduling:manage",
        "bookingsections" => "sis:scheduling:manage",
        "bookings" => "sis:scheduling:manage",
        "employees" => "sis:hr:manage",
        "timesheets" => "sis:hr:manage",
        "accounts" => "sis:finance:read",
        "journals" => "sis:finance:ledger",
        "transcripts" => "sis:transcript:manage",
        "reports" => "sis:reporting:manage",
        "sharedreports" => "sis:reporting:run",
        "portal" => "sis:portal:manage",
        "campaigns" => "sis:communications:manage",
        "rules" => "sis:rules:manage",
        "grades" => "",
        "teaching" => "",
        "faculty" => "sis:catalog:manage",
        "billing" => "sis:finance:read",
        "ledger" => "sis:finance:ledger",
        "institutions" => "sis:transfer:manage",
        "external" => "sis:transfer:manage",
        "equivalencies" => "sis:transfer:manage",
    ];
    public const array PERMISSIONS = [
        'sis:portal:publish',
        'sis:registration:prerequisite-exception',
        'sis:load:policy',
        'sis:load:determine',
        'sis:standing:correct',
        'sis:standing:configure',
        'sis:standing:manage',
        'sis:advising:manage',
        'sis:advising:checkin',
        'sis:restrictions:manage',
        'sis:restrictions:handoff',
        "sis:moodle:manage",
        "sis:moodle:link-person",
        'sis:moodle:create-person',
        "sis:person:manage",
        "sis:person:assign-id",
        "sis:admissions:manage",
        "sis:admissions:correct",
        "sis:admissions:withdraw",
        "sis:admissions:amend",
        "sis:admissions:defer",
        "sis:admissions:offers",
        "sis:admissions:policy",
        "sis:student:read",
        "sis:catalog:manage",
        "sis:registration:reinstate",
        "sis:gradebook:closed",
        "sis:registration:waitlist",
        "sis:registration:policy",
        "sis:registration:manage",
        "sis:gradebook:certify",
        "sis:gradebook:manage",
        "sis:gradebook:amend",
        "sis:academic-work:scope",
        "sis:scheduling:manage",
        "sis:hr:time-policy",
        "sis:hr:time-exception",
        "sis:hr:manage",
        "sis:finance:read",
        "sis:finance:post",
        "sis:finance:ledger",
        "sis:reporting:manage",
        "sis:reporting:run",
        "sis:communications:manage",
        "sis:communications:admin",
        "sis:portal:manage",
        "sis:import:manage",
        "sis:forms:manage",
        "sis:forms:publish",
        "sis:rules:manage",
        "sis:rules:run",
        "admin:access:manage",
        "sis:transfer:manage",
        "sis:audit:read",
        "sis:history:verify",
        "admin:create:user",
        "admin:edit:user",
        "sis:transcript:manage",
        "sis:transcript:policy",
        "vihzhuo:manage",
        "sis:degree-audit:assign",
        "sis:degree-audit:adjust",
    ];

    public function __construct(
        private WorkspaceRepository $repository,
        private StudentAccess $access,
        private AccessRepository $permissions,
        private AdmissionsService $admissions,
    ) {
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        $actor = $this->access->requirePermission("admin:dashboard");

        return array_values(
            array_filter(
                self::PERMISSIONS,
                fn(string $permission): bool => $this->permissions->allows($actor->value, $permission),
            ),
        );
    }

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        return $this->repository->metrics($this->capabilities());
    }

    /** @return array{items: list<array<string, mixed>>, next_after: ?string} */
    public function records(string $dataset, string $search = "", ?EntityId $after = null): array
    {
        $actor = $this->access->requirePermission("admin:dashboard");
        if (
            !array_key_exists($dataset, self::DATASETS) ||
            mb_strlen($search) > 80 ||
            preg_match('/[\x00-\x1f\x7f]/', $search)
        ) {
            throw new InvalidArgumentException("Invalid workspace search.");
        }
        $catalogRead =
        ($dataset === "sections" && $this->permissions->allows($actor->value, "sis:catalog:manage")) ||
        ($dataset === "courses" && $this->permissions->allows($actor->value, "sis:transfer:manage"));
        if (self::DATASETS[$dataset] !== "" && !$catalogRead) {
            $this->access->requirePermission(self::DATASETS[$dataset]);
        }
        // Personal directories are search-first, including direct API callers. Authorization above is mandatory.
        if (in_array($dataset, ['people', 'students'], true) && trim($search) === '') {
            return ['items' => [], 'next_after' => null, 'search_required' => true];
        }
        $owner = in_array(
            $dataset,
            ["reports", "sharedreports", "campaigns", "portal", "rules", "teaching"],
            true,
        )
        ? $actor
        : null;
        $admin = match ($dataset) {
            "reports" => "sis:reporting:admin",
            "campaigns" => "sis:communications:admin",
            "portal" => "sis:portal:admin",
            default => null,
        };
        if (
            ($admin !== null && $this->permissions->allows($actor->value, $admin)) ||
            ($dataset === 'portal' && $this->permissions->allows($actor->value, 'sis:portal:publish'))
        ) {
            $owner = null;
        }

        $result = $this->repository->records($dataset, $owner, trim($search), $after);
        if ($dataset === 'portal') {
            if (
                $this->access->requirePermission('sis:portal:manage')->value !== $actor->value ||
                $this->access->authenticatedUserId()->value !== $actor->value ||
                ($owner === null && !$this->permissions->allows($actor->value, 'sis:portal:admin') &&
                    !$this->permissions->allows($actor->value, 'sis:portal:publish'))
            ) {
                throw new \Domain\Shared\Exception\AccessDeniedException('The content reader changed.');
            }
        }
        if ($dataset === 'billing') {
            if (
                $this->access->requirePermission('admin:dashboard')->value !== $actor->value
                || $this->access->requirePermission('sis:finance:read')->value !== $actor->value
            ) {
                throw new \Domain\Shared\Exception\AccessDeniedException('The financial reader changed.');
            }
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public function me(): array
    {
        return $this->repository->ownPerson($this->access->authenticatedUserId());
    }

    /** @return array<string, mixed> */
    public function person(EntityId $id): array
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->admissions->person($id);
        $data = ["person" => $person];
        // A directory grant does not imply access to academic, employment or financial records.
        $sections = [];
        foreach (["students", "employees", "applications", "accounts"] as $section) {
            $permission = match ($section) {
                "students" => "sis:student:read",
                "employees" => "sis:hr:manage",
                "applications" => "sis:admissions:manage",
                "accounts" => "sis:finance:read",
            };
            if (
                $person->ownerUserId?->value === $actor->value ||
                $this->permissions->allows($actor->value, $permission)
            ) {
                $sections[] = $section;
            }
        }

        return $data + $this->repository->personWorkspace($id, $sections);
    }
}
