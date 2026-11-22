<?php

declare(strict_types=1);

namespace Domain\Gradebook\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Gradebook\Event\GradeComparisonPolicyWasPublished;
use Domain\Gradebook\GradeComparisonPolicy;
use Domain\Gradebook\ValueObject\GradeComparisonPolicyId;
use Domain\Gradebook\ValueObject\CourseworkGradePolicy;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;

final readonly class GradeComparisonPolicyService
{
    public const string PERMISSION = "sis:gradebook:calculation-policy:publish";
    public function __construct(
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private AccessRepository $roles,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs,
        private \Domain\Gradebook\Repository\GradeComparisonPolicyState $state,
    ) {
    }
    public function view(): array
    {
        $actor = $this->authorize();
        $result = $this->current();
        $this->authorize($actor);
        return $result;
    }
    /** Internal institutional rules; the caller authorizes the employee or maintenance context. */
    public function current(?HistoryReadBudget $budget = null): array
    {
        $counter = $this->state->current();
        $root = $this->restore($budget ?? $this->budget());
        if ($root->playhead() !== $counter) {
            throw new ConcurrentModificationException(
                \Codefy\Framework\Helpers\trans(
                    "The grade-comparison policy changed or its source disagrees. Refresh it.",
                ),
            );
        }
        $rows = $root->records();
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        return [
            "publication_id" => $last["publication_id"] ?? null,
            "version" => $root->playhead(),
            "rules" => $last["rules"] ?? CourseworkGradePolicy::DEFAULT,
        ];
    }
    /** Authorizing gradebook/academic callers retain the applied rules and reference together. */
    public function resolve(array $settings, bool $includeInstitution = false): array
    {
        $inherited = $settings["grade_policy"] === null;
        $institution = $inherited || $includeInstitution ? $this->current() : null;
        $settings["grade_policy_scope"] = $inherited ? "institution" : "section";
        $settings["grade_policy_reference"] = [
            "scope" => $settings["grade_policy_scope"],
            "publication_id" => $inherited ? $institution["publication_id"] : null,
            "version" => $inherited ? $institution["version"] : null,
        ];
        if ($inherited) {
            $settings["grade_policy"] = $institution["rules"];
        }
        if ($includeInstitution) {
            $settings["institutional_grade_policy"] = $institution;
        }
        return $settings;
    }
    public function review(array $rules, int $version, #[\SensitiveParameter] string $reason): array
    {
        $actor = $this->authorize();
        $rules = new CourseworkGradePolicy($rules);
        $reason = CourseworkGradePolicy::reason($reason);
        $current = $this->current();
        if ($current["version"] !== $version) {
            throw new ConcurrentModificationException(
                \Codefy\Framework\Helpers\trans("The grade-comparison policy changed. Reload it."),
            );
        }
        $operation = EntityId::generate();
        $intent = CourseworkGradePolicy::hash([$rules->facts, $reason]);
        $this->authorize($actor);
        return [
            "publication_id" => $operation->value,
            "version" => $version,
            "rules" => $rules->facts,
            "reason" => $reason,
            "review_token" => $this->proofs->issue(
                "grade-comparison-policy",
                "publish",
                new EntityId(GradeComparisonPolicy::ID),
                $actor,
                $operation,
                CourseworkGradePolicy::hash([$intent, $version]),
            ),
        ];
    }
    public function publish(
        EntityId $operation,
        array $rules,
        int $version,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->authorize();
        $rules = new CourseworkGradePolicy($rules);
        $reason = CourseworkGradePolicy::reason($reason);
        $intent = CourseworkGradePolicy::hash([$rules->facts, $reason]);
        return $this->transactions->run(function () use (
            $operation,
            $rules,
            $version,
            $reason,
            $token,
            $actor,
            $intent,
        ): array {
            $this->roles->lock();
            $counter = $this->state->current();
            $root = $this->restore($this->budget());
            if ($root->playhead() !== $counter) {
                throw new ConcurrentModificationException(
                    \Codefy\Framework\Helpers\trans(
                        "The grade-comparison policy source changed. Refresh it.",
                    ),
                );
            }
            $known = array_find(
                $root->records(),
                static fn(array $r): bool => $r["publication_id"] === $operation->value,
            );
            $revision = CourseworkGradePolicy::hash([$intent, $version]);
            $this->proofs->validate(
                $token,
                "grade-comparison-policy",
                "publish",
                new EntityId(GradeComparisonPolicy::ID),
                $actor,
                $operation,
                $revision,
                $known !== null,
            );
            if ($known !== null) {
                if (
                    $known["actor_id"] !== $actor->value ||
                    $known["intent"] !== $intent ||
                    $known["version"] !== $version + 1
                ) {
                    throw new AccessDeniedException();
                }
                $this->authorize($actor);
                return [
                    "status" => "published",
                    "publication_id" => $operation->value,
                    "version" => $known["version"],
                ];
            }
            if ($version >= 2000) {
                throw new \Domain\Shared\Exception\ValidationException([
                    [
                        "field" => "policy",
                        "message" =>
                            "The policy publication history reached its bounded review allowance.",
                    ],
                ]);
            }
            if ($root->playhead() !== $version) {
                throw new ConcurrentModificationException(
                    \Codefy\Framework\Helpers\trans(
                        "The grade-comparison policy changed. Review again.",
                    ),
                );
            }
            $this->audit->record($actor, "gradebook.comparison_policy_published", $operation);
            $this->authorize($actor);
            $this->proofs->validate(
                $token,
                "grade-comparison-policy",
                "publish",
                new EntityId(GradeComparisonPolicy::ID),
                $actor,
                $operation,
                $revision,
            );
            $event = GradeComparisonPolicyWasPublished::occur(
                new GradeComparisonPolicyId(GradeComparisonPolicy::ID),
                [
                    "publication_id" => $operation->value,
                    "rules" => $rules->facts,
                    "intent" => $intent,
                ],
                ["__aggregate_type" => "grade_comparison_policy"],
            )->withPlayhead($version + 1);
            $this->history->append(
                $this->stream(),
                $operation,
                $version,
                new EventContext($actor, $this->clock->now(), $operation, reason: $reason),
                [$event],
            );
            $this->state->advance($version);
            $this->authorize($actor);
            $this->proofs->validate(
                $token,
                "grade-comparison-policy",
                "publish",
                new EntityId(GradeComparisonPolicy::ID),
                $actor,
                $operation,
                $revision,
            );
            return [
                "status" => "published",
                "publication_id" => $operation->value,
                "version" => $version + 1,
            ];
        });
    }
    private function restore(HistoryReadBudget $budget): GradeComparisonPolicy
    {
        $root = GradeComparisonPolicy::root(new GradeComparisonPolicyId(GradeComparisonPolicy::ID));
        $cursor = null;
        do {
            $page = $budget->page($this->history, $this->stream(), $cursor);
            foreach ($page->events as $stored) {
                $root->accept($stored);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if ($root->playhead() !== $page->throughVersion) {
            throw new CorruptHistoryException();
        }
        return $root;
    }
    private function authorize(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission(self::PERMISSION);
        if (
            $this->access->requirePermission("sis:gradebook:manage")->value !== $actor->value ||
            ($expected !== null && $actor->value !== $expected->value)
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }
    private function stream(): StreamReference
    {
        return new StreamReference(
            new GradeComparisonPolicyId(GradeComparisonPolicy::ID),
            "grade_comparison_policy",
        );
    }
    private function budget(): HistoryReadBudget
    {
        return new HistoryReadBudget(maximumBytes: 4_194_304, maximumMilliseconds: 5000);
    }
}
