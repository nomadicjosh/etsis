<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use Domain\Person\Person;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Person\Repository\PersonContactRepository;
use Domain\Person\Repository\PersonProfileRepository;
use Domain\Person\ValueObject\ContactDetails;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\DerivedIdentity;
use Domain\Shared\ValueObject\EntityId;
use Domain\Audit\Repository\AuditTrail;
use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionsRepository;

/** Shared contact workflow with separate portal-owner and explicitly authorized staff entry points. */
final readonly class PersonContactService
{
    public function __construct(
        private PersonAccountRepository $people,
        private PersonContactRepository $contacts,
        private PersonProfileRepository $rows,
        private ProfileLedger $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private AdmissionsRepository $records,
        private AccessRepository $roles,
    ) {
    }

    /** @return array{preferred_name:string,phone:string,address:string,version:int} */
    public function view(EntityId $expectedPerson): array
    {
        return $this->viewAs($expectedPerson, false);
    }

    /** @return array{preferred_name:string,phone:string,address:string,version:int} */
    public function staffView(EntityId $person): array
    {
        return $this->viewAs($person, true);
    }

    /** @return array{preferred_name:string,phone:string,address:string,version:int} */
    private function viewAs(EntityId $expectedPerson, bool $staff): array
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->personFor($actor, $expectedPerson, $staff);
        $current = $this->contacts->current($person->id);
        $this->history->verifyContact($person, $current);
        $this->personFor($actor, $expectedPerson, $staff);
        return $current?->facts() ?? ['preferred_name' => '', 'phone' => '', 'address' => '', 'version' => 0];
    }

    public function save(string $preferredName, string $phone, string $address, int $expectedVersion): void
    {
        $this->saveAs($preferredName, $phone, $address, $expectedVersion);
    }

    public function staffSave(
        EntityId $person,
        string $preferredName,
        string $phone,
        string $address,
        int $expectedVersion
    ): void {
        $this->saveAs($preferredName, $phone, $address, $expectedVersion, $person);
    }

    private function saveAs(
        string $preferredName,
        string $phone,
        string $address,
        int $expectedVersion,
        ?EntityId $staffPerson = null
    ): void {
        $actor = $this->access->authenticatedUserId();
        $staff = $staffPerson !== null;
        $person = $this->personFor($actor, $staffPerson, $staff);
        if ($expectedVersion < 0 || $expectedVersion === PHP_INT_MAX) {
            throw new ValidationException([['field' => 'version', 'message' => 'Reload your contact information.']]);
        }
        $next = new ContactDetails(trim($preferredName), trim($phone), trim($address), $expectedVersion + 1);
        $intent = ProfileLedger::intent([$expectedVersion, $next->facts()]);
        $operation = DerivedIdentity::from($person->id, 'person.contact:' . $actor->value . ':' . $intent);
        $this->transactions->run(function () use (
            $actor,
            $person,
            $next,
            $expectedVersion,
            $intent,
            $operation,
            $staff
        ): void {
            if ($staff) {
                // Keep role changes serialized before the person lock, as in account administration.
                $this->roles->lock();
            }
            $this->rows->lock($person->id);
            $currentPerson = $this->personFor($actor, $person->id, $staff);
            if ($this->history->contactReceipt($person->id, $operation, $actor, $intent) !== null) {
                $this->personFor($actor, $person->id, $staff);
                return;
            }
            $before = $this->contacts->current($person->id);
            if (!$this->history->recordContact($currentPerson, $before, $next, $operation, $actor, $intent)) {
                $this->personFor($actor, $person->id, $staff);
                return;
            }
            $this->contacts->save($person->id, $next, $expectedVersion);
            $this->audit->record($actor, 'person.contact_updated', $person->id);
            $this->personFor($actor, $person->id, $staff);
        });
    }

    private function personFor(EntityId $actor, ?EntityId $expectedPerson, bool $staff): Person
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
