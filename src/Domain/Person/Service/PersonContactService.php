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
        private \Domain\Person\Repository\AddressBookRepository $addresses,
        private \Domain\Shared\Service\Clock $clock,
    ) {
    }

    /** @return array{preferred_name:string,phone:string,address:string,version:int,mailing_managed:bool} */
    public function view(EntityId $expectedPerson): array
    {
        return $this->viewAs($expectedPerson, false);
    }

    /** @return array{preferred_name:string,phone:string,address:string,version:int,mailing_managed:bool} */
    public function staffView(EntityId $person): array
    {
        return $this->viewAs($person, true);
    }

    /** @return array{preferred_name:string,phone:string,address:string,version:int,mailing_managed:bool} */
    private function viewAs(EntityId $expectedPerson, bool $staff): array
    {
        $actor = $this->authorization->actor();
        $person = $this->personFor($actor, $expectedPerson, $staff);
        $current = $this->contacts->current($person->id);
        $book = $this->addresses->current($person->id);
        $this->history->verifyContact($person, $current, $book);
        $this->personFor($actor, $expectedPerson, $staff);
        $view = $current?->facts() ?? ['preferred_name' => '', 'phone' => '', 'address' => '', 'version' => 0];
        $view['mailing_managed'] = $book->mailingManaged ?? false;
        if ($book?->mailingManaged) {
            $view['address'] = $book->mailingOn($this->clock->now()->format('Y-m-d'));
        }
        return $view;
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
        $next = new ContactDetails(trim($preferredName), trim($phone), '', $expectedVersion + 1);
        $addressInput = trim($address);
        if (
            !mb_check_encoding($addressInput, 'UTF-8') || mb_strlen($addressInput) > 2048
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $addressInput)
        ) {
            throw new ValidationException([['field' => 'address', 'message' => 'Enter a shorter valid address.']]);
        }
        $requestedFacts = $next->facts();
        $requestedFacts['address'] = $addressInput;
        $intent = ProfileLedger::intent([$expectedVersion, $requestedFacts]);
        $operation = DerivedIdentity::from($person->id, 'person.contact:' . $actor->value . ':' . $intent);
        $this->transactions->run(function () use (
            $actor,
            $person,
            $next,
            $addressInput,
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
            $book = $this->addresses->current($person->id);
            if ($book?->mailingManaged) {
                if ($addressInput !== $book->mailingOn($this->clock->now()->format('Y-m-d'))) {
                    throw new ValidationException([['field' => 'address',
                        'message' => 'Use Manage addresses to change the dated mailing address.']]);
                }
                $next = new ContactDetails($next->preferredName, $next->phone, $before->address ?? '', $next->version);
            } else {
                $next = new ContactDetails($next->preferredName, $next->phone, $addressInput, $next->version);
            }
            if (!$this->history->recordContact($currentPerson, $before, $next, $operation, $actor, $intent, $book)) {
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
