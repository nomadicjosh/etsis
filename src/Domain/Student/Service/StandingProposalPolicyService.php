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
        private \Domain\Student\Repository\StandingPolicyTargetRepository $targets,
        private \Domain\Catalog\Service\PublishedProgramReader $programs,
        private \Domain\Student\Repository\StudentRecordRepository $students,
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
        $this->validateTargets($rules->facts, $current["rules"]);
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
            $scopes = $rules->facts['overrides'] ?? [];
            $order = ['program' => 0, 'academic_level' => 1];
            usort($scopes, static fn(array $a, array $b): int =>
                ($order[$a['kind']] <=> $order[$b['kind']]) ?: strcmp($a['target']['id'], $b['target']['id']));
            foreach ($scopes as $scope) {
                $this->targets->lock($scope['kind'], new EntityId($scope['target']['id']));
            }
            $ids = array_column($rules->facts['bands'], 'definition_id');
            foreach ($scopes as $scope) {
                array_push($ids, ...array_column($scope['rules']['bands'], 'definition_id'));
            }
            sort($ids, SORT_STRING);
            foreach (array_unique($ids) as $id) {
                $this->definitions->lockDefinition(new EntityId($id));
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
            $records = $root->records();
            $previous = $records === [] ? StandingProposalRules::DEFAULT : $records[array_key_last($records)]['rules'];
            $this->validateTargets($rules->facts, $previous);
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
        if (isset($proof->data['scope'])) {
            $this->verifyPursuitScope($proof, $budget);
        }
        $source = $proof->data['scope']['level_source'] ?? null;
        if ($source !== null) {
            $program = $this->programs->version(
                new EntityId($proof->data['scope']['program_id']),
                new EntityId($source['academic_year_id']),
                new EntityId($source['program_version_id']),
                $budget
            );
            if (
                $program === null || !array_key_exists('academic_level', $program['definition']['classifications'])
                || StandingProposalRules::hash([$program['definition']['classifications']['academic_level']])
                    !== StandingProposalRules::hash([$source['academic_level']])
            ) {
                throw new CorruptHistoryException();
            }
        }
    }

    /** Authenticate the captured pursuit position without replacing it with today's cohort assignment. */
    private function verifyPursuitScope(
        #[\SensitiveParameter] \Domain\Student\ValueObject\StandingProposalEvidence $proof,
        HistoryReadBudget $budget,
    ): void {
        $scope = $proof->data['scope'];
        if ($scope['pursuit_version'] === 0) {
            // Verify the immutable pursuit/program identity without inventing its earlier journal or cohort.
            $pursuit = $this->students->assignment(new EntityId($proof->data['pursuit_id']));
            if (
                $pursuit->studentId->value !== $proof->data['student_id']
                || $pursuit->programId->value !== $scope['program_id']
            ) {
                throw new CorruptHistoryException();
            }
            return;
        }
        $stream = new StreamReference(new \Domain\Student\ValueObject\StudentProgramStatusId(
            $proof->data['pursuit_id']
        ), 'student_program_status');
        $root = \Domain\Student\StudentProgramStatus::root($stream->aggregateId);
        $found = false;
        $cursor = null;
        do {
            $page = $budget->page($this->history, $stream, $cursor);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                if ($root->playhead() !== $scope['pursuit_version']) {
                    continue;
                }
                $facts = $root->facts();
                if (
                    $facts === null || $facts['student_id'] !== $proof->data['student_id']
                    || $facts['program_id'] !== $scope['program_id'] || $facts['status'] !== 'active'
                ) {
                    throw new CorruptHistoryException();
                }
                if ($scope['level_source'] !== null) {
                    $cohort = $root->cohort() ?? ($root->assignmentSource()['cohort'] ?? null);
                    if (
                        ($cohort['catalog_id'] ?? null) !== $scope['level_source']['program_version_id']
                        || ($cohort['year_id'] ?? null) !== $scope['level_source']['academic_year_id']
                    ) {
                        throw new CorruptHistoryException();
                    }
                }
                $found = true;
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if (!$found) {
            throw new CorruptHistoryException();
        }
    }

    public function targets(string $kind, string $search = '', ?EntityId $after = null): array
    {
        $actor = $this->authorize();
        if (
            !in_array($kind, ['program', 'academic_level'], true) || !mb_check_encoding($search, 'UTF-8')
            || mb_strlen($search) > 80 || preg_match('/[\x00-\x1F\x7F]/', $search)
        ) {
            throw new \Domain\Shared\Exception\ValidationException([['field' => 'search',
                'message' => \Codefy\Framework\Helpers\trans_html(
                    'Choose program or academic level and use a search of at most eighty characters.'
                )]]);
        }
        $rows = $this->targets->page($kind, trim($search), $after);
        $this->authorize($actor);
        return ['items' => array_slice($rows, 0, 20), 'next_after' => count($rows) > 20 ? $rows[19]['id'] : null];
    }

    private function validateTargets(array $rules, array $previous): void
    {
        foreach ($rules['overrides'] ?? [] as $scope) {
            $row = $this->targets->get($scope['kind'], new EntityId($scope['target']['id']));
            $old = array_find($previous['overrides'] ?? [], static fn(array $r): bool =>
                $r['kind'] === $scope['kind'] && $r['target']['id'] === $scope['target']['id']);
            $retainedDisabled = !$scope['rules']['enabled'] && $old !== null
            && StandingProposalRules::hash($old['target']) === StandingProposalRules::hash($scope['target']);
            if ($retainedDisabled) {
                continue;
            }
            if (
                ($scope['rules']['enabled'] && !$row['active']) || $row['code'] !== $scope['target']['code']
                || $row['title'] !== $scope['target']['title']
            ) {
                throw new \Domain\Shared\Exception\ValidationException([['field' => 'overrides',
                    'message' => \Codefy\Framework\Helpers\trans_html(
                        'Choose current active policy targets; an existing retired target may be disabled or removed.'
                    )]]);
            }
        }
    }

    private function validateDefinitions(array $rules): void
    {
        $sets = [$rules, ...array_column($rules['overrides'] ?? [], 'rules')];
        foreach ($sets as $set) {
            if (!$set['enabled']) {
                continue;
            }
            foreach ($set['bands'] as $band) {
                $row = $this->definitions->definition(new EntityId($band['definition_id']));
                if (!$row['active'] || $row['code'] !== $band['code'] || $row['title'] !== $band['title']) {
                    throw new \Domain\Shared\Exception\ValidationException([['field' => 'bands',
                        'message' => \Codefy\Framework\Helpers\trans_html(
                            'Choose current active standing definitions before publishing GPA bands.'
                        )]]);
                }
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
