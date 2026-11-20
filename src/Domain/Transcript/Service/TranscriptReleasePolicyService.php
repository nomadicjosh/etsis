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
        return $policy->describe();
    }

    /** @param array<mixed> $checks
     * @return array<string,mixed> */
    public function save(int $revision, array $checks, bool $includeStanding = false): array
    {
        $actor = $this->authorize(write: true);
        $next = new TranscriptReleasePolicy($revision + 1, $checks, $includeStanding);
        return $this->transactions->run(function () use ($revision, $next, $actor): array {
            $this->permissions->lock();
            $this->records->lock();
            $this->authorize($actor, true);
            $current = $this->records->current();
            if ($current->revision !== $revision) {
                throw new ConcurrentModificationException("Reload release-check setup before saving.");
            }
            $this->records->assertCurrent($current);
            if ($current->checks === $next->checks && $current->includeStanding === $next->includeStanding) {
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
            return $next->describe();
        });
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
