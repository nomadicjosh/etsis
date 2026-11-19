<?php

declare(strict_types=1);

namespace Domain\Workspace\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Communications\Service\CampaignService;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\Workspace\Repository\OperationsRepository;
use InvalidArgumentException;

final readonly class OperationsService
{
    public function __construct(
        private OperationsRepository $repository,
        private StudentAccess $access,
        private AccessRepository $permissions,
        private RegistrationRepository $registration,
        private AdmissionsRepository $admissions,
        private CampaignService $campaigns,
    ) {
    }

    /** @return array<string, mixed> */
    public function page(
        string $context,
        EntityId $id,
        ?EntityId $after = null,
        string $search = "",
        string $gradeStatus = "all",
    ): array {
        $actor = $this->access->requirePermission("admin:dashboard");
        switch ($context) {
            case "roster":
            case "assignments":
                $section = $this->registration->section($id);
                if (
                    $context !== "roster" ||
                    !$this->permissions->allows($actor->value, "sis:registration:manage")
                ) {
                    $this->access->requireOwnerOrPermission($section->facultyUserId, "sis:gradebook:manage");
                }
                break;
            case "enrollments":
                $this->access->requirePermission("sis:registration:manage");
                $this->admissions->student($id);
                break;
            case "prerequisites":
            case "window":
                $this->access->requirePermission("sis:catalog:manage");
                break;
            case "audit":
                $this->access->requirePermission("sis:audit:read");
                break;
            case "deliveries":
                $this->campaigns->show($id); // Verifies the campaign owner or explicit administrator.
                break;
            default:
                throw new InvalidArgumentException("Unknown operations context.");
        }

        $search = trim($search);
        if (
            !mb_check_encoding($search, "UTF-8") ||
            mb_strlen($search) > 80 ||
            preg_match('/[\x00-\x1F\x7F]/', $search) ||
            !in_array($gradeStatus, ["all", "active", "needs_grade", "graded"], true) ||
            ($context !== "roster" && ($search !== "" || $gradeStatus !== "all"))
        ) {
            throw new InvalidArgumentException("Use a valid roster search and filter.");
        }
        $canGrade =
        $context === "roster" &&
        ($section->facultyUserId?->value === $actor->value ||
        $this->permissions->allows($actor->value, "sis:gradebook:manage"));
        if (!$canGrade && in_array($gradeStatus, ["needs_grade", "graded"], true)) {
            throw new \Domain\Shared\Exception\AccessDeniedException(
                "Final-grade filtering requires grading access to this section.",
            );
        }
        $result = $this->repository->page($context, $id, $after, $search, $gradeStatus);
        // Authority can change while the query runs; do not return/export private rows to another actor.
        if ($this->access->requirePermission("admin:dashboard")->value !== $actor->value) {
            throw new \Domain\Shared\Exception\AccessDeniedException("The workspace account changed.");
        }
        if ($context === "roster") {
            $current = $this->registration->section($id);
            $stillGrades =
            $current->facultyUserId?->value === $actor->value ||
            $this->permissions->allows($actor->value, "sis:gradebook:manage");
            if (!$this->permissions->allows($actor->value, "sis:registration:manage") && !$stillGrades) {
                throw new \Domain\Shared\Exception\AccessDeniedException("Roster access changed.");
            }
            if ($canGrade && !$stillGrades) {
                throw new \Domain\Shared\Exception\AccessDeniedException(
                    "Grading access changed. Reload the roster.",
                );
            }
        }
        if ($context === "roster") {
            $section = $this->registration->section($id);
            $canGrade =
            $section->facultyUserId?->value === $actor->value ||
            $this->permissions->allows($actor->value, "sis:gradebook:manage");
            if (!$canGrade) {
                foreach ($result["items"] as &$row) {
                    unset($row["grade_code"], $row["grade_version"]);
                }
                unset($row);
            }
            $result["can_grade"] = $canGrade;
        }

        return $result;
    }

    public function operationId(): EntityId
    {
        $this->access->requirePermission("sis:finance:post");

        return EntityId::generate();
    }
}
