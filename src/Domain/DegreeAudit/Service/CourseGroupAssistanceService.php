<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\Assistance\Exception\AssistanceUnavailableException;
use Domain\Assistance\Service\AuthoringAssistant;
use Domain\Assistance\ValueObject\AuthoringTask;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Service\PublishedCourseReader;
use Domain\DegreeAudit\Repository\CourseGroupRepository;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Throwable;

/** Saved reusable-group advice; no authority to save, publish or evaluate student work. */
final readonly class CourseGroupAssistanceService
{
    public function __construct(
        private CourseGroupRepository $groups,
        private PublishedCourseReader $courses,
        private AuthoringAssistant $assistant,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    /** Local consent preview; course labels come from the exact selected publications.
     * @return array<string,mixed>
     */
    public function preview(EntityId $group): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $this->actor($actor);
        $saved = $this->snapshot($group, $actor);
        $provider = $this->assistant->availability();
        $this->actor($actor);
        return [
            "enabled" => $provider["enabled"],
            "label" => $provider["label"],
            "saved_revision" => $saved["saved_revision"],
            "draft" => $saved["draft"],
            "revision" => $this->revision($saved["revision"], $provider["fingerprint"]),
        ];
    }

    /** @return array<string,mixed> */
    public function suggest(EntityId $group, bool $consent, string $revision): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $this->actor($actor);
        if (!$consent) {
            throw new InvalidArgumentException("Inspect the saved group and consent to one request first.");
        }
        $provider = $this->assistant->availability();
        if (!$provider["enabled"]) {
            throw new AssistanceUnavailableException();
        }
        $saved = $this->snapshot($group, $actor);
        if (!hash_equals($this->revision($saved["revision"], $provider["fingerprint"]), $revision)) {
            throw new ConcurrentModificationException("The saved group changed. Inspect a fresh preview.");
        }
        $this->audit->record($actor, "course_group.assistance_requested", $group);
        $this->actor($actor);
        if ($this->assistant->availability()["fingerprint"] !== $provider["fingerprint"]) {
            throw new ConcurrentModificationException("The provider changed. Inspect a fresh preview.");
        }
        // Transactions and replay have ended before any network work begins.
        try {
            $advice = $this->assistant->suggest(AuthoringTask::GroupReadiness, $saved["draft"]);
            if ($advice->description !== null) {
                throw new AssistanceUnavailableException();
            }
        } catch (AssistanceUnavailableException $exception) {
            $this->actor($actor);
            throw $exception;
        } catch (Throwable) {
            $this->actor($actor);
            throw new AssistanceUnavailableException();
        }
        $this->actor($actor);
        $current = $this->snapshot($group, $actor);
        if (
            !hash_equals(
                $revision,
                $this->revision($current["revision"], $this->assistant->availability()["fingerprint"]),
            )
        ) {
            throw new ConcurrentModificationException(
                "The saved group or provider changed. Suggestions were discarded.",
            );
        }
        $this->actor($actor);
        return [
            "task" => AuthoringTask::GroupReadiness->value,
            "revision" => $revision,
            "advice" => $advice->describe(),
        ];
    }

    /** Snapshot one saved group with a shared bound across all selected course streams.
     * @return array{draft:array<string,mixed>,revision:string,saved_revision:int}
     */
    private function snapshot(EntityId $group, EntityId $actor): array
    {
        try {
            return $this->transactions->run(function () use ($group, $actor): array {
                $this->groups->lock($group);
                $this->actor($actor);
                $saved = $this->groups->get($group);
                $definition = CourseGroupDefinitionV2::draft($saved["definition"]);
                if (!($definition instanceof CourseGroupDefinitionV2)) {
                    throw new InvalidArgumentException(
                        "Update the group to credit/course settings before requesting assistance.",
                    );
                }
                $d = $definition->definition;
                $draft = array_intersect_key(
                    $d,
                    array_flip([
                        "name",
                        "requirement_type",
                        "min_credits_milli",
                        "min_courses",
                        "min_course_grade_points_milli",
                        "max_course_grade_points_milli",
                    ]),
                );
                $draft["program_grading_context_selected"] = $d["grade_scale_program_id"] !== null;
                $draft["courses"] = [];
                $positions = [];
                $budget = new HistoryReadBudget(maximumPages: 200, maximumEvents: 2000);
                foreach ($d["courses"] as $index => $reference) {
                    $course = $this->courses->version(
                        new EntityId($reference["course_id"]),
                        new EntityId($reference["version_id"]),
                        $budget,
                    )->definition;
                    $position = $index + 1;
                    $positions[$reference["course_id"]] = $position;
                    $draft["courses"][] = [
                        "position" => $position,
                        "code" => $course->code,
                        "title" => $course->title,
                        "credits_milli" => $course->creditsMilli,
                    ];
                }
                $draft["rules"] = [];
                foreach ($d["rules"] as $rule) {
                    $draft["rules"][] = [
                        "mode" => $rule["mode"],
                        "minimum_courses" => $rule["minimum_courses"],
                        "course_positions" => array_map(
                            static fn(string $id): int => $positions[$id],
                            $rule["course_ids"],
                        ),
                    ];
                }
                if (strlen(json_encode($draft, JSON_THROW_ON_ERROR)) > 65536) {
                    throw new AssistanceUnavailableException();
                }
                $this->actor($actor);
                return [
                    "draft" => $draft,
                    "saved_revision" => $saved["revision"],
                    "revision" => hash("sha256", json_encode([$saved, $draft], JSON_THROW_ON_ERROR)),
                ];
            });
        } catch (HistoryReadLimitException) {
            // Never send a partial group after exhausting verification work.
            throw new AssistanceUnavailableException();
        }
    }

    private function revision(string $snapshot, string $provider): string
    {
        return hash("sha256", json_encode([$snapshot, $provider], JSON_THROW_ON_ERROR));
    }

    private function actor(EntityId $actor): void
    {
        if (
            $this->access->requirePermission("sis:catalog:manage")->value !== $actor->value ||
            $this->access->requirePermission("sis:ai:group:readiness")->value !== $actor->value
        ) {
            throw new AccessDeniedException("The group author changed. Reload before continuing.");
        }
    }
}
