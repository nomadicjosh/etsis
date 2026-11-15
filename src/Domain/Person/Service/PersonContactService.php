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

/** Owner-only contact editing, independent of student/employment status or staff override permissions. */
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
    ) {
    }

    /** @return array{preferred_name:string,phone:string,address:string,version:int} */
    public function view(EntityId $expectedPerson): array
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->owner($actor, $expectedPerson);
        $current = $this->contacts->current($person->id);
        $this->history->verifyContact($person, $current);
        $this->owner($actor, $expectedPerson);
        return $current?->facts() ?? ['preferred_name' => '', 'phone' => '', 'address' => '', 'version' => 0];
    }

    public function save(string $preferredName, string $phone, string $address, int $expectedVersion): void
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->owner($actor);
        if ($expectedVersion < 0 || $expectedVersion === PHP_INT_MAX) {
            throw new ValidationException([['field' => 'version', 'message' => 'Reload your contact information.']]);
        }
        $next = new ContactDetails(trim($preferredName), trim($phone), trim($address), $expectedVersion + 1);
        $intent = ProfileLedger::intent([$expectedVersion, $next->facts()]);
        $operation = DerivedIdentity::from($person->id, 'person.contact:' . $actor->value . ':' . $intent);
        $this->transactions->run(function () use ($actor, $person, $next, $expectedVersion, $intent, $operation): void {
            $this->rows->lock($person->id);
            $currentPerson = $this->owner($actor, $person->id);
            if ($this->history->contactReceipt($person->id, $operation, $actor, $intent) !== null) {
                $this->owner($actor, $person->id);
                return;
            }
            $before = $this->contacts->current($person->id);
            if (!$this->history->recordContact($currentPerson, $before, $next, $operation, $actor, $intent)) {
                $this->owner($actor, $person->id);
                return;
            }
            $this->contacts->save($person->id, $next, $expectedVersion);
            $this->audit->record($actor, 'person.contact_updated', $person->id);
            $this->owner($actor, $person->id);
        });
    }

    private function owner(EntityId $actor, ?EntityId $expectedPerson = null): Person
    {
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException('The signed-in account changed.');
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
