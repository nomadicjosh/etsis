<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Student\Event\StandingProposalPolicyWasPublished;
use Domain\Student\StandingProposalPolicy;
use Domain\Student\ValueObject\StandingProposalPolicyId;
use Domain\Student\ValueObject\StandingProposalRules;
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

final readonly class StandingProposalPolicyService
{
    public const string PERMISSION = StudentStandingService::CONFIGURE;
    public function __construct(
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private AccessRepository $roles,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs,
        private \Domain\Student\Repository\StandingProposalPolicyState $state,
        private \Domain\Student\Repository\StudentStandingRepository $definitions,
    ) {
    }
    public function view(): array
    {
        $actor = $this->authorize();
        $result = $this->current();
        $result["policy_reference"] = StandingProposalPolicy::ID;
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
                \Codefy\Framework\Helpers\trans_html(
                    "The standing proposal policy changed or its source disagrees. Refresh it.",
                ),
            );
        }
        $rows = $root->records();
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        return [
            "publication_id" => $last["publication_id"] ?? null,
            "version" => $root->playhead(),
            "rules" => $last["rules"] ?? StandingProposalRules::DEFAULT,
        ];
    }
    public function review(array $rules, int $version, #[\SensitiveParameter] string $reason): array
    {
        $actor = $this->authorize();
        $rules = new StandingProposalRules($rules);
        $reason = StandingProposalRules::reason($reason);
        $current = $this->current();
        $this->validateDefinitions($rules->facts);
        if ($current["version"] !== $version) {
            throw new ConcurrentModificationException(
                \Codefy\Framework\Helpers\trans_html(
                    "The standing proposal policy changed. Reload it.",
                ),
            );
        }
        $operation = EntityId::generate();
        $intent = StandingProposalRules::hash([$rules->facts, $reason]);
        $this->authorize($actor);
        return [
            "publication_id" => $operation->value,
            "version" => $version,
            "rules" => $rules->facts,
            "reason" => $reason,
            "review_token" => $this->proofs->issue(
                "standing-proposal-policy",
                "publish",
                new EntityId(StandingProposalPolicy::ID),
                $actor,
                $operation,
                StandingProposalRules::hash([$intent, $version]),
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
        $rules = new StandingProposalRules($rules);
        $reason = StandingProposalRules::reason($reason);
        $intent = StandingProposalRules::hash([$rules->facts, $reason]);
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
            $ids = array_column($rules->facts['bands'], 'definition_id');
            sort($ids, SORT_STRING);
            foreach (array_unique($ids) as $id) {
                $this->definitions->lockDefinition(new EntityId($id));
            }
            $counter = $this->state->current();
            $root = $this->restore($this->budget());
            if ($root->playhead() !== $counter) {
                throw new ConcurrentModificationException(
                    \Codefy\Framework\Helpers\trans_html(
                        "The standing proposal policy source changed. Refresh it.",
                    ),
                );
            }
            $known = array_find(
                $root->records(),
                static fn(array $r): bool => $r["publication_id"] === $operation->value,
            );
            $revision = StandingProposalRules::hash([$intent, $version]);
            $this->proofs->validate(
                $token,
                "standing-proposal-policy",
                "publish",
                new EntityId(StandingProposalPolicy::ID),
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
                        "message" => \Codefy\Framework\Helpers\trans_html(
                            "The policy publication history reached its bounded review allowance.",
                        ),
                    ],
                ]);
            }
            if ($root->playhead() !== $version) {
                throw new ConcurrentModificationException(
                    \Codefy\Framework\Helpers\trans_html(
                        "The standing proposal policy changed. Review again.",
                    ),
                );
            }
            $this->audit->record($actor, "standing.proposal_policy_published", $operation);
            $this->authorize($actor);
            $this->proofs->validate(
                $token,
                "standing-proposal-policy",
                "publish",
                new EntityId(StandingProposalPolicy::ID),
                $actor,
                $operation,
                $revision,
            );
            $this->validateDefinitions($rules->facts);
            $event = StandingProposalPolicyWasPublished::occur(
                new StandingProposalPolicyId(StandingProposalPolicy::ID),
                [
                    "publication_id" => $operation->value,
                    "rules" => $rules->facts,
                    "intent" => $intent,
                ],
                ["__aggregate_type" => "standing_proposal_policy"],
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
                StandingProposalRules::hash($accepted["rules"]) !==
                    StandingProposalRules::hash($rules->facts)
            ) {
                throw new CorruptHistoryException();
            }
            $this->authorize($actor);
            $this->proofs->validate(
                $token,
                "standing-proposal-policy",
                "publish",
                new EntityId(StandingProposalPolicy::ID),
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

    public function verifyEvidence(
        #[\SensitiveParameter] \Domain\Student\ValueObject\StandingProposalEvidence $proof,
        HistoryReadBudget $budget,
    ): void {
        $published = $this->recorded(new EntityId($proof->data['policy_publication_id']), $budget);
        if (
            $published['version'] !== $proof->data['policy_version']
            || StandingProposalRules::hash($published['rules']) !== StandingProposalRules::hash($proof->data['rules'])
        ) {
            throw new CorruptHistoryException();
        }
    }

    private function validateDefinitions(array $rules): void
    {
        if (!$rules['enabled']) {
            return;
        }
        foreach ($rules['bands'] as $band) {
            $row = $this->definitions->definition(new EntityId($band['definition_id']));
            if (!$row['active'] || $row['code'] !== $band['code'] || $row['title'] !== $band['title']) {
                throw new \Domain\Shared\Exception\ValidationException([['field' => 'bands',
                    'message' => \Codefy\Framework\Helpers\trans_html(
                        'Choose current active standing definitions before publishing GPA bands.'
                    )]]);
            }
        }
    }

    private function restore(HistoryReadBudget $budget): StandingProposalPolicy
    {
        $root = StandingProposalPolicy::root(
            new StandingProposalPolicyId(StandingProposalPolicy::ID),
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
            $this->access->requirePermission("sis:student:read")->value !== $actor->value ||
            ($expected !== null && $actor->value !== $expected->value)
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }
    private function stream(): StreamReference
    {
        return new StreamReference(
            new StandingProposalPolicyId(StandingProposalPolicy::ID),
            "standing_proposal_policy",
        );
    }
    private function budget(): HistoryReadBudget
    {
        return new HistoryReadBudget(maximumBytes: 4_194_304, maximumMilliseconds: 5000);
    }
}
