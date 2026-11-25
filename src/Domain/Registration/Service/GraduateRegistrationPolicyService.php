<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Event\GraduateRegistrationPolicyWasPublished;
use Domain\Registration\GraduateRegistrationPolicy;
use Domain\Registration\ValueObject\GraduateRegistrationPolicyId;
use Domain\Registration\ValueObject\GraduateRegistrationRules;
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

final readonly class GraduateRegistrationPolicyService
{
    public const string PERMISSION = "sis:registration:graduate-policy:publish";
    public function __construct(
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private AccessRepository $roles,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs,
        private \Domain\Registration\Repository\GraduateRegistrationPolicyState $state,
        private \Domain\Catalog\Repository\ClassificationRepository $levels,
    ) {
    }
    public function view(): array
    {
        $actor = $this->authorize();
        $result = $this->current();
        $result["levels"] = [];
        foreach (["undergraduate_level_id", "graduate_level_id"] as $key) {
            if ($result["rules"][$key] !== null) {
                $row = $this->levels->get(new EntityId($result["rules"][$key]));
                $result["levels"][] = array_intersect_key(
                    $row,
                    array_flip(["id", "code", "title", "active"]),
                );
            }
        }
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
                    "The graduate-course registration policy changed or its source disagrees. Refresh it.",
                ),
            );
        }
        $rows = $root->records();
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        return [
            "publication_id" => $last["publication_id"] ?? null,
            "version" => $root->playhead(),
            "rules" => $last["rules"] ?? GraduateRegistrationRules::DEFAULT,
        ];
    }
    public function review(array $rules, int $version, #[\SensitiveParameter] string $reason): array
    {
        $actor = $this->authorize();
        $rules = new GraduateRegistrationRules($rules);
        $reason = GraduateRegistrationRules::reason($reason);
        $current = $this->current();
        $this->validateLevels($rules->facts, $current["rules"]);
        if ($current["version"] !== $version) {
            throw new ConcurrentModificationException(
                \Codefy\Framework\Helpers\trans(
                    "The graduate-course registration policy changed. Reload it.",
                ),
            );
        }
        $operation = EntityId::generate();
        $intent = GraduateRegistrationRules::hash([$rules->facts, $reason]);
        $this->authorize($actor);
        return [
            "publication_id" => $operation->value,
            "version" => $version,
            "rules" => $rules->facts,
            "reason" => $reason,
            "review_token" => $this->proofs->issue(
                "graduate-registration-policy",
                "publish",
                new EntityId(GraduateRegistrationPolicy::ID),
                $actor,
                $operation,
                GraduateRegistrationRules::hash([$intent, $version]),
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
        $rules = new GraduateRegistrationRules($rules);
        $reason = GraduateRegistrationRules::reason($reason);
        $intent = GraduateRegistrationRules::hash([$rules->facts, $reason]);
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
            $ids = array_filter([
                $rules->facts["undergraduate_level_id"],
                $rules->facts["graduate_level_id"],
            ]);
            sort($ids, SORT_STRING);
            foreach ($ids as $id) {
                $this->levels->lock(new EntityId($id));
            }
            $counter = $this->state->current();
            $root = $this->restore($this->budget());
            if ($root->playhead() !== $counter) {
                throw new ConcurrentModificationException(
                    \Codefy\Framework\Helpers\trans(
                        "The graduate-course registration policy source changed. Refresh it.",
                    ),
                );
            }
            $known = array_find(
                $root->records(),
                static fn(array $r): bool => $r["publication_id"] === $operation->value,
            );
            $revision = GraduateRegistrationRules::hash([$intent, $version]);
            $this->proofs->validate(
                $token,
                "graduate-registration-policy",
                "publish",
                new EntityId(GraduateRegistrationPolicy::ID),
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
                        "message" => \Codefy\Framework\Helpers\trans(
                            "The policy publication history reached its bounded review allowance.",
                        ),
                    ],
                ]);
            }
            if ($root->playhead() !== $version) {
                throw new ConcurrentModificationException(
                    \Codefy\Framework\Helpers\trans(
                        "The graduate-course registration policy changed. Review again.",
                    ),
                );
            }
            $this->audit->record($actor, "registration.graduate_policy_published", $operation);
            $this->authorize($actor);
            $this->proofs->validate(
                $token,
                "graduate-registration-policy",
                "publish",
                new EntityId(GraduateRegistrationPolicy::ID),
                $actor,
                $operation,
                $revision,
            );
            $this->validateLevels($rules->facts, $this->current()["rules"]);
            $event = GraduateRegistrationPolicyWasPublished::occur(
                new GraduateRegistrationPolicyId(GraduateRegistrationPolicy::ID),
                [
                    "publication_id" => $operation->value,
                    "rules" => $rules->facts,
                    "intent" => $intent,
                ],
                ["__aggregate_type" => "graduate_registration_policy"],
            )->withPlayhead($version + 1);
            $this->history->append(
                $this->stream(),
                $operation,
                $version,
                new EventContext($actor, $this->clock->now(), $operation, reason: $reason),
                [$event],
            );
            $this->state->advance($version);
            $accepted = $this->current();
            if (
                $accepted["publication_id"] !== $operation->value ||
                $accepted["version"] !== $version + 1 ||
                GraduateRegistrationRules::hash($accepted["rules"]) !==
                    GraduateRegistrationRules::hash($rules->facts)
            ) {
                throw new CorruptHistoryException();
            }
            $this->authorize($actor);
            $this->proofs->validate(
                $token,
                "graduate-registration-policy",
                "publish",
                new EntityId(GraduateRegistrationPolicy::ID),
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
    public function recorded(EntityId $publication, HistoryReadBudget $budget): array
    {
        $root = $this->restore($budget);
        return array_find(
            $root->records(),
            static fn(array $r): bool => $r["publication_id"] === $publication->value,
        ) ?? throw new CorruptHistoryException();
    }

    public function levelChoices(string $search = "", ?EntityId $after = null): array
    {
        $actor = $this->authorize();
        if (mb_strlen($search) > 80) {
            throw new \InvalidArgumentException(
                \Codefy\Framework\Helpers\trans("Use at most eighty search characters."),
            );
        }
        $rows = $this->levels->page(\Domain\Catalog\Enum\ClassificationKind::AcademicLevel, $search, $after);
        $this->authorize($actor);
        return [
            "items" => array_slice($rows, 0, 20),
            "next_after" => count($rows) > 20 ? $rows[19]["id"] : null,
        ];
    }

    private function validateLevels(array $rules, array $previous): void
    {
        foreach (["undergraduate_level_id", "graduate_level_id"] as $key) {
            if ($rules[$key] === null) {
                continue;
            }
            $row = $this->levels->get(new EntityId($rules[$key]));
            if (
                $row["kind"] !== "academic_level" ||
                (!(bool) $row["active"] && $rules[$key] !== $previous[$key])
            ) {
                throw new \Domain\Shared\Exception\ValidationException([
                    [
                        "field" => $key,
                        "message" => \Codefy\Framework\Helpers\trans(
                            "Choose active academic levels; existing retired selections may remain.",
                        ),
                    ],
                ]);
            }
        }
    }

    private function restore(HistoryReadBudget $budget): GraduateRegistrationPolicy
    {
        $root = GraduateRegistrationPolicy::root(
            new GraduateRegistrationPolicyId(GraduateRegistrationPolicy::ID),
        );
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
            $this->access->requirePermission("sis:registration:manage")->value !== $actor->value ||
            ($expected !== null && $actor->value !== $expected->value)
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }
    private function stream(): StreamReference
    {
        return new StreamReference(
            new GraduateRegistrationPolicyId(GraduateRegistrationPolicy::ID),
            "graduate_registration_policy",
        );
    }
    private function budget(): HistoryReadBudget
    {
        return new HistoryReadBudget(maximumBytes: 4_194_304, maximumMilliseconds: 5000);
    }
}
