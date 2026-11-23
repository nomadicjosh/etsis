<?php

declare(strict_types=1);

namespace Domain\Transcript\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentStandingRepository;
use Domain\Transcript\Repository\TranscriptReleasePolicyRepository;
use Domain\Transcript\ValueObject\TranscriptReleasePolicy;

final readonly class TranscriptReleasePolicyService
{
    public function __construct(
        private TranscriptReleasePolicyRepository $records,
        private StudentAccess $access,
        private AccessRepository $permissions,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private StudentStandingRepository $standings,
    ) {
    }

    public function checkId(): EntityId
    {
        $actor = $this->authorize(write: true);
        $id = EntityId::generate();
        $this->authorize($actor, true);
        return $id;
    }

    /** @return array<string,mixed> */
    public function current(): array
    {
        $actor = $this->authorize();
        $policy = $this->records->current();
        $this->authorize($actor);
        $labels = $this->standings->definitionLabels($policy->standingDefinitions ?? []);
        $this->authorize($actor);
        return $policy->describe() + ($policy->standingDefinitions === null ? [] : ['standing_choices' => $labels]);
    }

    /** @param array<mixed> $checks
     * @return array<string,mixed> */
    public function save(int $revision, array $checks, bool $includeStanding, ?array $standingDefinitions): array
    {
        $actor = $this->authorize(write: true);
        $next = new TranscriptReleasePolicy($revision + 1, $checks, $includeStanding, $standingDefinitions);
        return $this->transactions->run(function () use ($revision, $next, $actor): array {
            $this->permissions->lock();
            $this->records->lock();
            $this->authorize($actor, true);
            $current = $this->records->current();
            if ($current->revision !== $revision) {
                throw new ConcurrentModificationException("Reload release-check setup before saving.");
            }
            $this->records->assertCurrent($current);
            $this->selected($next->standingDefinitions);
            if (
                $current->checks === $next->checks && $current->includeStanding === $next->includeStanding
                && $current->standingDefinitions === $next->standingDefinitions
            ) {
                $this->authorize($actor, true);
                return $current->describe();
            }
            $this->records->save($next, $revision);
            $this->audit->record(
                $actor,
                "transcript.release_policy_changed",
                new EntityId(TranscriptReleasePolicyRepository::POLICY_ID),
            );
            $this->authorize($actor, true);
            $this->records->assertCurrent($next);
            $this->selected($next->standingDefinitions);
            return $next->describe();
        });
    }

    /** Bounded institution reference lookup; it grants no standing/student decision authority. */
    public function standingChoices(string $search = '', ?EntityId $after = null): array
    {
        $actor = $this->authorize(write: true);
        if (
            !mb_check_encoding($search, 'UTF-8') || mb_strlen($search) > 80
            || preg_match('/[\x00-\x1F\x7F]/', $search)
        ) {
            throw new \Domain\Shared\Exception\ValidationException([
                ['field' => 'search', 'message' => 'Use a search of up to eighty characters.'],
            ]);
        }
        $result = $this->standings->definitions(trim($search), $after, false);
        $this->authorize($actor, true);
        return $result;
    }

    private function selected(?array $ids): void
    {
        foreach ($ids ?? [] as $id) {
            $this->standings->lockDefinition(new EntityId($id));
            if ($this->standings->definition(new EntityId($id))['id'] !== $id) {
                throw new ConcurrentModificationException('Reload the selected standing definitions.');
            }
        }
    }

    private function authorize(?EntityId $expected = null, bool $write = false): EntityId
    {
        $actor = $this->access->requirePermission("sis:transcript:manage");
        if (
            $this->access->requirePermission("sis:student:read")->value !== $actor->value ||
            ($write && $this->access->requirePermission("sis:transcript:policy")->value !== $actor->value) ||
            ($expected !== null && $actor->value !== $expected->value)
        ) {
            throw new AccessDeniedException("Transcript policy authority changed.");
        }
        return $actor;
    }
}
