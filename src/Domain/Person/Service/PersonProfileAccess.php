<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use Domain\Person\Person;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Access\Repository\AccessRepository;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;

/** Shared profile authorization; callers keep owner and staff entry points separate. */
final readonly class PersonProfileAccess
{
    public function __construct(
        private PersonAccountRepository $people,
        private AdmissionsRepository $records,
        private AccessRepository $roles,
        private StudentAccess $access,
    ) {
    }
    public function actor(): EntityId
    {
        return $this->access->authenticatedUserId();
    }
    public function forAccess(StudentAccess $access): self
    {
        return new self($this->people, $this->records, $this->roles, $access);
    }
    public function lockStaffRoles(): void
    {
        $this->roles->lock();
    }
    public function person(EntityId $actor, ?EntityId $expectedPerson, bool $staff): Person
    {
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException('The signed-in account changed.');
        }
        if ($staff) {
            if (
                $expectedPerson === null
                || $this->access->requirePermission('sis:person:manage')->value !== $actor->value
            ) {
                throw new AccessDeniedException('Person-management permission is required.');
            }
            $person = $this->records->person($expectedPerson);
            if (
                $person->ownerUserId === null || ($this->roles->isSuper($person->ownerUserId->value)
                && !$this->roles->isSuper($actor->value))
            ) {
                throw new AccessDeniedException('This person requires a super administrator.');
            }
            return $person;
        }
        $person = $this->people->findByUser($actor);
        if (
            $person === null || $person->ownerUserId?->value !== $actor->value
            || ($expectedPerson !== null && $person->id->value !== $expectedPerson->value)
        ) {
            throw new AccessDeniedException('The current account does not own this person.');
        }
        return $person;
    }
}
