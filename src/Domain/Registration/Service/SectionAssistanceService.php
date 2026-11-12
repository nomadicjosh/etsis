<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Assistance\Exception\AssistanceUnavailableException;
use Domain\Assistance\Service\AuthoringAssistant;
use Domain\Assistance\ValueObject\AuthoringTask;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Throwable;

/** Optional saved-section readiness advice. No authority to change drafts or historical publications. */
final readonly class SectionAssistanceService
{
    public function __construct(
        private SectionPublicationService $catalog,
        private AuthoringAssistant $assistant,
        private StudentAccess $access,
        private AuditTrail $audit,
    ) {
    }
    /** Exact minimized input for staff inspection and consent. No provider call.
     * @return array<string,mixed>
     */
    public function preview(EntityId $section): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $this->actor($actor);
        $definition = $this->catalog->assistanceSnapshot($section);
        $ready = $this->assistant->availability();
        $this->actor($actor);
        return [
            "enabled" => $ready["enabled"],
            "label" => $ready["label"],
            "draft" => $definition["draft"],
            "revision" => $this->revision($definition["revision"], $ready["fingerprint"]),
        ];
    }
    /** One explicit request. Network work runs after the short snapshot transaction has ended.
     * @return array<string,mixed>
     */
    public function suggest(EntityId $section, bool $consent, string $revision): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $this->actor($actor);
        if (!$consent) {
            throw new InvalidArgumentException(
                "Inspect the saved draft and explicitly consent before requesting assistance.",
            );
        }
        $ready = $this->assistant->availability();
        if (!$ready["enabled"]) {
            throw new AssistanceUnavailableException();
        }
        $definition = $this->catalog->assistanceSnapshot($section);
        if (!hash_equals($this->revision($definition["revision"], $ready["fingerprint"]), $revision)) {
            throw new ConcurrentModificationException(
                "The saved section changed. Review its assistance preview again.",
            );
        }
        // Metadata-only consent evidence: no prompt, response, reason, credentials or model payload.
        $this->audit->record($actor, "catalog.assistance_requested.section_readiness", $section);
        $this->actor($actor);
        if ($this->assistant->availability()["fingerprint"] !== $ready["fingerprint"]) {
            throw new ConcurrentModificationException(
                "The assistance provider changed. Review consent again.",
            );
        }
        try {
            $advice = $this->assistant->suggest(AuthoringTask::SectionReadiness, $definition["draft"]);
            if ($advice->description !== null) {
                throw new AssistanceUnavailableException();
            }
        } catch (Throwable) {
            $this->actor($actor);
            throw new AssistanceUnavailableException();
        }
        $this->actor($actor);
        $current = $this->catalog->assistanceSnapshot($section);
        if (
            !hash_equals(
                $revision,
                $this->revision($current["revision"], $this->assistant->availability()["fingerprint"]),
            )
        ) {
            throw new ConcurrentModificationException(
                "The saved section changed while assistance was running. Reload before requesting new suggestions.",
            );
        }
        $this->actor($actor);
        return [
            "task" => AuthoringTask::SectionReadiness->value,
            "revision" => $revision,
            "advice" => $advice->describe(),
        ];
    }
    private function revision(string $snapshot, string $provider): string
    {
        return hash("sha256", json_encode([$snapshot, $provider], JSON_THROW_ON_ERROR));
    }
    private function actor(EntityId $actor): void
    {
        if (
            $this->access->requirePermission("sis:catalog:manage")->value !== $actor->value ||
            $this->access->requirePermission("sis:ai:section:readiness")->value !== $actor->value
        ) {
            throw new AccessDeniedException("The section author changed. Reload before continuing.");
        }
    }
}
