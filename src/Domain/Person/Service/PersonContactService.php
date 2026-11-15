<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use Domain\Person\Person;
use Domain\Person\Repository\PersonContactRepository;
use Domain\Person\Repository\PersonProfileRepository;
use Domain\Person\ValueObject\ContactDetails;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\ValueObject\DerivedIdentity;
use Domain\Shared\ValueObject\EntityId;
use Domain\Audit\Repository\AuditTrail;

/** Shared contact workflow with separate portal-owner and explicitly authorized staff entry points. */
final readonly class PersonContactService
{
    public function __construct(
        private PersonContactRepository $contacts,
        private PersonProfileRepository $rows,
        private ProfileLedger $history,
        private PersonProfileAccess $authorization,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
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
        $actor = $this->authorization->actor();
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
        $actor = $this->authorization->actor();
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
                $this->authorization->lockStaffRoles();
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
        return $this->authorization->person($actor, $expectedPerson, $staff);
    }
}
