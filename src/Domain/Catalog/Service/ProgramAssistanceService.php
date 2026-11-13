<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use Domain\Assistance\Exception\AssistanceUnavailableException;
use Domain\Assistance\Service\AuthoringAssistant;
use Domain\Assistance\ValueObject\AuthoringTask;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\ValueObject\ProgramDefinition;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Throwable;

/** Optional saved-program authoring advice. No authority to change drafts or historical publications. */
final readonly class ProgramAssistanceService
{
    public function __construct(
        private ProgramPublicationService $catalog,
        private AuthoringAssistant $assistant,
        private StudentAccess $access,
        private AuditTrail $audit,
    ) {
    }
    /** Exact minimized input for staff inspection and consent. No provider call.
     * @return array<string,mixed>
     */
    public function preview(EntityId $program): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $saved = $this->catalog->draft($program);
        $definition = ProgramDefinition::fromArray($saved["definition"]);
        $ready = $this->assistant->availability();
        $tasks = [];
        foreach ([AuthoringTask::Description, AuthoringTask::Rewrite, AuthoringTask::Expand] as $task) {
            if ($task->requiresExistingDescription() && $definition->description === null) {
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
            "saved_revision" => $saved["revision"],
        ];
    }
    /** One explicit request. Network work runs after the short snapshot transaction has ended.
     * @return array<string,mixed>
     */
    public function suggest(EntityId $program, AuthoringTask $task, bool $consent, string $revision): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        if (
            !in_array(
                $task,
                [AuthoringTask::Description, AuthoringTask::Rewrite, AuthoringTask::Expand],
                true,
            )
        ) {
            throw new InvalidArgumentException("Choose a program description action.");
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
        $definition = ProgramDefinition::fromArray($this->catalog->draft($program)["definition"]);
        if (!hash_equals($this->revision($definition, $ready["fingerprint"]), $revision)) {
            throw new ConcurrentModificationException(
                "The saved program changed. Review its assistance preview again.",
            );
        }
        if ($task->requiresExistingDescription() && $definition->description === null) {
            throw new InvalidArgumentException(
                "Save a program description before rewriting or expanding it.",
            );
        }
        // Metadata-only consent evidence: no prompt, response, reason, credentials or model payload.
        $this->audit->record($actor, "catalog.program_assistance_requested." . $task->value, $program);
        $this->actor($actor, $task);
        if ($this->assistant->availability()["fingerprint"] !== $ready["fingerprint"]) {
            throw new ConcurrentModificationException(
                "The assistance provider changed. Review consent again.",
            );
        }
        try {
            $advice = $this->assistant->suggest($task, $this->input($definition));
        } catch (AssistanceUnavailableException $exception) {
            $this->actor($actor, $task);
            throw $exception;
        } catch (Throwable) {
            $this->actor($actor, $task);
            throw new AssistanceUnavailableException();
        }
        $this->actor($actor, $task);
        $current = ProgramDefinition::fromArray($this->catalog->draft($program)["definition"]);
        if (
            !hash_equals(
                $revision,
                $this->revision($current, $this->assistant->availability()["fingerprint"]),
            )
        ) {
            throw new ConcurrentModificationException(
                "The saved program changed while assistance was running. Reload before requesting new suggestions.",
            );
        }
        $this->actor($actor, $task);
        return [
            "task" => $task->value,
            "revision" => $revision,
            "advice" => $advice->describe(),
        ];
    }
    /** The allowlist excludes internal IDs, staff/student facts, publication reasons and history.
     * @return array<string,mixed>
     */
    private function input(ProgramDefinition $definition): array
    {
        $labels = static fn(?array $value): ?array => $value === null
            ? null
            : ["code" => $value["code"], "title" => $value["title"]];
        return [
            "kind" => "academic_program",
            "code" => $definition->code,
            "title" => $definition->title,
            "description" => $definition->description,
            "classifications" => array_map($labels, $definition->classifications->describe()),
        ];
    }
    private function revision(ProgramDefinition $definition, string $provider): string
    {
        return hash("sha256", json_encode([$definition->describe(), $provider], JSON_THROW_ON_ERROR));
    }
    private function actor(EntityId $actor, ?AuthoringTask $task = null): void
    {
        if (
            $task !== null &&
            $this->access->requirePermission("sis:ai:program:" . $task->value)->value !== $actor->value
        ) {
            throw new AccessDeniedException("The assistance author changed. Reload before continuing.");
        }
        if ($this->access->requirePermission("sis:catalog:manage")->value !== $actor->value) {
            throw new AccessDeniedException("The catalog author changed. Reload before continuing.");
        }
    }
}
