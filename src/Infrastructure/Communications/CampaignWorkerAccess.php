<?php

declare(strict_types=1);

namespace Infrastructure\Communications;

use Domain\Access\Repository\AccessRepository;
use Domain\Communications\Dto\AuthorizedCampaignDelivery;
use Domain\Communications\Repository\CampaignDispatchRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

/** Restricted worker authority from a confirmed job, without creating or changing a login session. */
final readonly class CampaignWorkerAccess implements StudentAccess
{
    public function __construct(
        private AuthorizedCampaignDelivery $delivery,
        private CampaignDispatchRepository $jobs,
        private AccessRepository $permissions
    ) {
    }

    public function authenticatedUserId(): EntityId
    {
        $job = $this->jobs->get($this->delivery->campaign);
        $actor = $this->delivery->actor;
        if (
            $job === null || $job['status'] !== 'active'
            || $job['actor_id'] !== $actor->value
            || $job['operation_id'] !== $this->delivery->operation->value
            || (int) $job['version'] !== $this->delivery->authorizationVersion
            || !$this->permissions->allows($actor->value, 'sis:communications:manage')
            || !$this->permissions->allows($actor->value, 'sis:person:manage')
        ) {
            throw new AccessDeniedException('Background delivery authorization is no longer available.');
        }
        return $actor;
    }

    public function requirePermission(string $permission): EntityId
    {
        $actor = $this->authenticatedUserId();
        if ($permission !== 'sis:communications:manage') {
            throw new AccessDeniedException('This worker may only deliver the authorized campaign.');
        }
        return $actor;
    }

    public function requireOwnerOrPermission(?EntityId $ownerUserId, string $permission): EntityId
    {
        $actor = $this->authenticatedUserId();
        if (
            $permission !== 'sis:communications:admin' || ($ownerUserId?->value !== $actor->value
            && !$this->permissions->allows($actor->value, $permission))
        ) {
            throw new AccessDeniedException('Campaign ownership or administration access is required.');
        }
        return $actor;
    }
}
