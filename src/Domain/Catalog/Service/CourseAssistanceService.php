<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use Domain\Assistance\Exception\AssistanceUnavailableException;
use Domain\Assistance\Service\AuthoringAssistant;
use Domain\Assistance\ValueObject\AuthoringTask;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\ValueObject\CourseDefinition;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Throwable;

/** Optional saved-course authoring advice. No authority to change drafts or historical publications. */
final readonly class CourseAssistanceService
{
    public function __construct(
        private CoursePublicationService $catalog,
        private AuthoringAssistant $assistant,
        private StudentAccess $access,
        private AuditTrail $audit,
    ) {
    }
    /** Exact minimized input for staff inspection and consent. No provider call.
     * @return array<string,mixed>
     */
    public function preview(EntityId $course): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $definition = $this->catalog->workingDefinition($course);
        $ready = $this->assistant->availability();
        $tasks = [];
        foreach (AuthoringTask::cases() as $task) {
            if ($task === AuthoringTask::SectionReadiness) {
                continue;
            }
            if ($task->requiresExistingDescription() && $definition->text->description === null) {
                continue;
            }
            try {
                $this->actor($actor, $task);
                $tasks[] = $task->value;
            } catch (AccessDeniedException) {
                // A catalog editor may have access to only selected assistance actions.
            }
        }
        $this->actor($actor);
        return [
            "enabled" => $ready["enabled"],
            "label" => $ready["label"],
            "tasks" => $tasks,
            "draft" => $this->input($definition),
            "revision" => $this->revision($definition, $ready["fingerprint"]),
        ];
    }
    /** One explicit request. Network work runs after the short snapshot transaction has ended.
     * @return array<string,mixed>
     */
    public function suggest(EntityId $course, AuthoringTask $task, bool $consent, string $revision): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        if ($task === AuthoringTask::SectionReadiness) {
            throw new InvalidArgumentException("Use section assistance for section readiness.");
        }
        $this->actor($actor, $task);
        if (!$consent) {
            throw new InvalidArgumentException(
                "Inspect the saved draft and explicitly consent before requesting assistance.",
            );
        }
        $ready = $this->assistant->availability();
        if (!$ready["enabled"]) {
            throw new AssistanceUnavailableException();
        }
        $definition = $this->catalog->workingDefinition($course);
        if (!hash_equals($this->revision($definition, $ready["fingerprint"]), $revision)) {
            throw new ConcurrentModificationException(
                "The saved course changed. Review its assistance preview again.",
            );
        }
        if ($task->requiresExistingDescription() && $definition->text->description === null) {
            throw new InvalidArgumentException("Save a course description before rewriting or expanding it.");
        }
        // Metadata-only consent evidence: no prompt, response, reason, credentials or model payload.
        $this->audit->record($actor, "catalog.assistance_requested." . $task->value, $course);
        $this->actor($actor, $task);
        if ($this->assistant->availability()["fingerprint"] !== $ready["fingerprint"]) {
            throw new ConcurrentModificationException(
                "The assistance provider changed. Review consent again.",
            );
        }
        try {
            $advice = $this->assistant->suggest($task, $this->input($definition));
            if ($task === AuthoringTask::Readiness && $advice->description !== null) {
                throw new AssistanceUnavailableException();
            }
        } catch (Throwable) {
            $this->actor($actor, $task);
            throw new AssistanceUnavailableException();
        }
        $this->actor($actor, $task);
        $current = $this->catalog->workingDefinition($course);
        if (
            !hash_equals(
                $revision,
                $this->revision($current, $this->assistant->availability()["fingerprint"]),
            )
        ) {
            throw new ConcurrentModificationException(
                "The saved course changed while assistance was running. Reload before requesting new suggestions.",
            );
        }
        $this->actor($actor, $task);
        return ["task" => $task->value, "revision" => $revision, "advice" => $advice->describe()];
    }
    /** The allowlist excludes internal IDs, staff/student facts, publication reasons and history.
     * @return array<string,mixed>
     */
    private function input(CourseDefinition $definition): array
    {
        $labels = static fn(?array $value): ?array => $value === null
            ? null
            : ["code" => $value["code"], "title" => $value["title"]];
        return [
            "code" => $definition->code,
            "title" => $definition->title,
            "transcript_title" => $definition->text->transcriptTitle,
            "description" => $definition->text->description,
            "credits_milli" => $definition->creditsMilli,
            "prerequisite_count" => count($definition->prerequisites),
            "subject" => $labels($definition->subject?->describe()),
            "classifications" => array_map($labels, $definition->classifications->describe()),
        ];
    }
    private function revision(CourseDefinition $definition, string $provider): string
    {
        return hash("sha256", json_encode([$definition->describe(), $provider], JSON_THROW_ON_ERROR));
    }
    private function actor(EntityId $actor, ?AuthoringTask $task = null): void
    {
        if (
            $task !== null &&
            $this->access->requirePermission("sis:ai:course:" . $task->value)->value !== $actor->value
        ) {
            throw new AccessDeniedException("The assistance author changed. Reload before continuing.");
        }
        if ($this->access->requirePermission("sis:catalog:manage")->value !== $actor->value) {
            throw new AccessDeniedException("The catalog author changed. Reload before continuing.");
        }
    }
}
