<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\EnrollmentLoadPolicy;
use Domain\Student\Event\EnrollmentLoadPolicyWasPublished;
use Domain\Student\Repository\EnrollmentLoadPolicyRepository;
use Domain\Student\ValueObject\EnrollmentLoadBands;
use Domain\Student\ValueObject\EnrollmentLoadPolicyId;

final readonly class EnrollmentLoadPolicyService
{
    public const string PERMISSION = "sis:load:policy";

    public function __construct(
        private EnrollmentLoadPolicyRepository $records,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs,
    ) {
    }

    public function programs(string $search = "", ?EntityId $after = null): array
    {
        $actor = $this->authorize();
        if (
            !mb_check_encoding($search, "UTF-8") ||
            mb_strlen($search) > 80 ||
            preg_match('/[\x00-\x1F\x7F]/', $search)
        ) {
            throw new ValidationException([
                ["field" => "search", "message" => "Use a search of up to eighty characters."],
            ]);
        }
        $page = $this->records->programs(trim($search), $after);
        $this->authorize($actor);
        return $page;
    }

    public function view(?EntityId $program = null): array
    {
        $actor = $this->authorize();
        $result = $this->context($program);
        $this->authorize($actor);
        return $result;
    }

    /** Internal resolved read; the calling student workflow must authorize its scope first. */
    public function resolve(EntityId $program): array
    {
        return $this->context($program)["effective"];
    }

    public function review(
        ?EntityId $program,
        ?array $definition,
        string $revision,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize();
        $definition = $definition === null ? null : new EnrollmentLoadBands($definition)->definition;
        $this->reason($reason);
        return $this->transactions->run(function () use (
            $program,
            $definition,
            $revision,
            $reason,
            $actor,
        ): array {
            $this->records->lockScope($program);
            $root = $this->load($program);
            $context = $this->context($program, $root->state());
            $this->check($context["revision"], $revision);
            if ($root->state()["definition"] === $definition) {
                throw new ValidationException([
                    [
                        "field" => "definition",
                        "message" => "Change the credit bands or inheritance before reviewing.",
                    ],
                ]);
            }
            $operation = EntityId::generate();
            $intent = $this->intent($program, $definition, $revision, trim($reason));
            $this->authorize($actor);
            return [
                "program_id" => $program?->value,
                "program" => $context["program"],
                "operation_id" => $operation->value,
                "definition" => $definition,
                "revision" => $revision,
                "before" => $root->state()["definition"],
                "after" => $definition,
                "fallback" => $context["global"],
                "reason" => trim($reason),
                "review_token" => $this->proofs->issue(
                    "enrollment-load",
                    "publish",
                    $this->scopeId($program),
                    $actor,
                    $operation,
                    $intent,
                ),
            ];
        });
    }

    public function publish(
        ?EntityId $program,
        EntityId $operation,
        ?array $definition,
        string $revision,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->authorize();
        $definition = $definition === null ? null : new EnrollmentLoadBands($definition)->definition;
        $this->reason($reason);
        $intent = $this->intent($program, $definition, $revision, trim($reason));
        return $this->transactions->run(function () use (
            $program,
            $operation,
            $definition,
            $revision,
            $reason,
            $token,
            $actor,
            $intent,
        ): array {
            $this->records->lockScope($program);
            $root = $this->load($program);
            $receipt = $root->receipt($operation, $actor, $intent);
            $this->proofs->validate(
                $token,
                "enrollment-load",
                "publish",
                $this->scopeId($program),
                $actor,
                $operation,
                $intent,
                $receipt !== null,
            );
            $this->authorize($actor);
            if ($receipt !== null) {
                return $receipt;
            }
            $this->check($this->context($program, $root->state())["revision"], $revision);
            if ($root->state()["definition"] === $definition) {
                throw new ConcurrentModificationException("Review the current load policy again.");
            }
            $event = EnrollmentLoadPolicyWasPublished::occur(
                $this->stream($program)->aggregateId,
                [
                    "program_id" => $program?->value,
                    "operation_id" => $operation->value,
                    "previous_operation_id" => $root->state()["operation_id"],
                    "before_hash" => EnrollmentLoadPolicy::hash($root->state()["definition"]),
                    "definition" => $definition,
                    "intent" => $intent,
                ],
                ["__aggregate_type" => "enrollment_load_policy"],
            )->withPlayhead($root->playhead() + 1);
            $this->history->append(
                $this->stream($program),
                $operation,
                $root->playhead(),
                new EventContext($actor, $this->clock->now(), $operation, reason: trim($reason)),
                [$event],
            );
            $this->records->project($program, [
                "position" => $event->playhead(),
                "operation_id" => $operation->value,
                "definition" => $definition,
            ]);
            $this->audit->record($actor, "enrollment_load.policy_published", $this->scopeId($program));
            $this->authorize($actor);
            $this->check($this->context($program, $root->state())["revision"], $revision);
            $this->proofs->validate(
                $token,
                "enrollment-load",
                "publish",
                $this->scopeId($program),
                $actor,
                $operation,
                $intent,
            );
            $head = $this->history->latest($this->stream($program));
            if ($head === null || $head->event->payload()["operation_id"] !== $operation->value) {
                throw new CorruptHistoryException();
            }
            $root->accept($head);
            if ($root->state() !== $this->head($program)) {
                throw new CorruptHistoryException();
            }
            return $root->receipt($operation, $actor, $intent) ?? throw new CorruptHistoryException();
        });
    }

    public function timeline(?EntityId $program, ?string $cursor = null): array
    {
        $actor = $this->authorize();
        if ($this->access->requirePermission("sis:audit:read")->value !== $actor->value) {
            throw new AccessDeniedException();
        }
        $page = $this->history->page($this->stream($program), $cursor, 20);
        $items = [];
        foreach ($page->events as $stored) {
            $items[] = [
                "version" => $stored->event->playhead(),
                "definition" => $stored->event->payload()["definition"],
                "published_at" => $stored->context->effectiveAt?->format(DATE_ATOM),
                "reason" => $stored->context->reason,
            ];
        }
        $this->authorize($actor);
        $this->access->requirePermission("sis:audit:read");
        return ["items" => $items, "next_cursor" => $page->nextCursor];
    }

    private function context(?EntityId $program, ?array $state = null): array
    {
        $label = $program === null ? null : $this->records->program($program);
        $own = $state ?? $this->head($program);
        $global = $program === null ? $own : $this->head(null);
        $effective = $program !== null && $own["definition"] !== null ? $own : $global;
        return [
            "program_id" => $program?->value,
            "program" => $label,
            "state" => $own,
            "global" => $global,
            "revision" => hash("sha256", json_encode([$program?->value, $own, $global], JSON_THROW_ON_ERROR)),
            "effective" => [
                "source" =>
                    $effective["definition"] === null
                        ? "not_configured"
                        : ($program !== null && $own["definition"] !== null
                            ? "program"
                            : "global"),
                "version_id" => $effective["operation_id"],
                "definition" => $effective["definition"],
            ],
        ];
    }

    private function head(?EntityId $program): array
    {
        $stored = $this->history->latest($this->stream($program));
        $expected = ["position" => 0, "operation_id" => null, "definition" => null];
        if ($stored !== null) {
            EnrollmentLoadPolicy::validate($data = $stored->event->payload());
            if (
                $data["program_id"] !== $program?->value ||
                $stored->context->actor === null ||
                $stored->context->reason === null ||
                $stored->context->effectiveAt === null ||
                $stored->context->origin !== "command" ||
                $stored->context->correlation?->value !== $data["operation_id"]
            ) {
                throw new CorruptHistoryException();
            }
            $expected = [
                "position" => $stored->event->playhead(),
                "operation_id" => $data["operation_id"],
                "definition" =>
                    $data["definition"] === null
                        ? null
                        : new EnrollmentLoadBands($data["definition"])->definition,
            ];
        }
        if ($expected !== $this->records->current($program)) {
            throw new CorruptHistoryException();
        }
        return $expected;
    }

    private function load(?EntityId $program): EnrollmentLoadPolicy
    {
        $root = EnrollmentLoadPolicy::root($this->stream($program)->aggregateId);
        $budget = new HistoryReadBudget();
        $cursor = null;
        do {
            $page = $budget->page($this->history, $this->stream($program), $cursor);
            foreach ($page->events as $stored) {
                $root->accept($stored);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if ($root->state() !== $this->records->current($program)) {
            throw new CorruptHistoryException();
        }
        return $root;
    }

    private function authorize(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission(self::PERMISSION);
        if (
            $actor->value !== $this->access->authenticatedUserId()->value ||
            ($expected !== null && $expected->value !== $actor->value)
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    private function reason(#[\SensitiveParameter] string $reason): void
    {
        if (
            !mb_check_encoding($reason, "UTF-8") ||
            trim($reason) === "" ||
            mb_strlen($reason) > 1000 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            throw new ValidationException([
                [
                    "field" => "reason",
                    "message" => "Enter a reason for publishing this load policy, up to 1,000 characters.",
                ],
            ]);
        }
    }

    private function check(string $actual, string $expected): void
    {
        if (!hash_equals($actual, $expected)) {
            throw new ConcurrentModificationException("The load policy changed. Reload and review it again.");
        }
    }

    private function stream(?EntityId $program): StreamReference
    {
        return new StreamReference(EnrollmentLoadPolicyId::scope($program), "enrollment_load_policy");
    }

    private function scopeId(?EntityId $program): EntityId
    {
        return new EntityId((string) EnrollmentLoadPolicyId::scope($program));
    }

    private function intent(
        ?EntityId $program,
        ?array $definition,
        string $revision,
        #[\SensitiveParameter] string $reason,
    ): string {
        return hash(
            "sha256",
            json_encode([$program?->value, $definition, $revision, $reason], JSON_THROW_ON_ERROR),
        );
    }
}
