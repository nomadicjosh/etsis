<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use Domain\Person\Person;
use Domain\Person\Repository\EmergencyContactsRepository;
use Domain\Person\Repository\PersonProfileRepository;
use Domain\Person\ValueObject\EmergencyContacts;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\ValueObject\DerivedIdentity;
use Domain\Shared\ValueObject\EntityId;
use Domain\Audit\Repository\AuditTrail;

/** Shared contact workflow with separate portal-owner and explicitly authorized staff entry points. */
final readonly class EmergencyContactsService
{
    public function __construct(
        private EmergencyContactsRepository $contacts,
        private PersonProfileRepository $rows,
        private ProfileLedger $history,
        private PersonProfileAccess $authorization,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    /** @return array{contacts:list<array{name:string,relationship:string,phone:string,email:string}>,version:int} */
    public function view(EntityId $expectedPerson): array
    {
        return $this->viewAs($expectedPerson, false);
    }

    /** @return array{contacts:list<array{name:string,relationship:string,phone:string,email:string}>,version:int} */
    public function staffView(EntityId $person): array
    {
        return $this->viewAs($person, true);
    }

    /** @return array{contacts:list<array{name:string,relationship:string,phone:string,email:string}>,version:int} */
    private function viewAs(EntityId $expectedPerson, bool $staff): array
    {
        $actor = $this->authorization->actor();
        $person = $this->personFor($actor, $expectedPerson, $staff);
        $current = $this->contacts->current($person->id);
        $this->history->verifyEmergency($person, $current);
        $this->personFor($actor, $expectedPerson, $staff);
        return $current?->facts() ?? ['contacts' => [], 'version' => 0];
    }

    /** @param array<mixed> $contacts */
    public function save(#[\SensitiveParameter] array $contacts, int $expectedVersion): void
    {
        $this->saveAs($contacts, $expectedVersion);
    }

    /** @param array<mixed> $contacts */
    public function staffSave(
        EntityId $person,
        #[\SensitiveParameter] array $contacts,
        int $expectedVersion
    ): void {
        $this->saveAs($contacts, $expectedVersion, $person);
    }

    /** @param array<mixed> $contacts */
    private function saveAs(
        #[\SensitiveParameter] array $contacts,
        int $expectedVersion,
        ?EntityId $staffPerson = null
    ): void {
        $actor = $this->authorization->actor();
        $staff = $staffPerson !== null;
        $person = $this->personFor($actor, $staffPerson, $staff);
        if ($expectedVersion < 0 || $expectedVersion === PHP_INT_MAX) {
            throw new ValidationException([['field' => 'version', 'message' => 'Reload the emergency contacts.']]);
        }
        $next = EmergencyContacts::fromInput($contacts, $expectedVersion + 1);
        $intent = ProfileLedger::intent([$expectedVersion, $next->facts()]);
        $operation = DerivedIdentity::from($person->id, 'person.emergency:' . $actor->value . ':' . $intent);
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
                $this->authorization->lockStaffRoles();
            }
            $this->rows->lock($person->id);
            $currentPerson = $this->personFor($actor, $person->id, $staff);
            if ($this->history->emergencyReceipt($person->id, $operation, $actor, $intent) !== null) {
                $this->personFor($actor, $person->id, $staff);
                return;
            }
            $before = $this->contacts->current($person->id);
            if (!$this->history->recordEmergency($currentPerson, $before, $next, $operation, $actor, $intent)) {
                $this->personFor($actor, $person->id, $staff);
                return;
            }
            $this->contacts->save($person->id, $next, $expectedVersion);
            $this->audit->record($actor, 'person.emergency_contacts_updated', $person->id);
            $this->personFor($actor, $person->id, $staff);
        });
    }

    private function personFor(EntityId $actor, ?EntityId $expectedPerson, bool $staff): Person
    {
        return $this->authorization->person($actor, $expectedPerson, $staff);
    }
}
