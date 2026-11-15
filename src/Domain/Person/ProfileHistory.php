<?php

declare(strict_types=1);

namespace Domain\Person;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Person\Event\PersonProfileWasRecorded;
use Domain\Person\Event\PersonContactWasRecorded;
use Domain\Person\Event\PersonEmergencyContactsWereRecorded;
use Domain\Person\ValueObject\EmergencyContacts;
use Domain\Person\ValueObject\ContactDetails;
use Domain\Person\ValueObject\ProfileFacts;
use Domain\Shared\EventSourcing\CorruptHistoryException;

final class ProfileHistory extends EventSourcedAggregate
{
    private ?Person $person = null;
    private ?ContactDetails $contact = null;
    private ?EmergencyContacts $emergency = null;
    private ?string $operation = null;
    public function acceptRecorded(\Domain\Shared\EventSourcing\StoredEvent $stored): void
    {
        $payload = $stored->event->payload();
        if (
            $stored->context->actor === null || $stored->context->effectiveAt === null
            || $stored->context->correlation?->value !== ($payload['operation_id'] ?? null)
        ) {
            throw new CorruptHistoryException();
        }
        $this->acceptHistorical($stored->event);
    }
    public function acceptHistorical(#[\SensitiveParameter] DomainEvent $e): void
    {
        if (
            (!$e instanceof PersonProfileWasRecorded
            && !$e instanceof PersonContactWasRecorded
                && !$e instanceof PersonEmergencyContactsWereRecorded)
            || !$e->aggregateId()->equals($this->aggregateId())
            || $e->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        if ($e instanceof PersonEmergencyContactsWereRecorded) {
            EmergencyContacts::validateEvent($e->payload());
        } elseif ($e instanceof PersonContactWasRecorded) {
            ContactDetails::validateEvent($e->payload());
        } else {
            ProfileFacts::validate($e->payload());
        }
        $this->applyThat($e);
        $this->playhead = $e->playhead();
    }
    public function whenPersonProfileWasRecorded(PersonProfileWasRecorded $e): void
    {
        $p = $e->payload();
        $next = ProfileFacts::person($p['facts']);
        if (
            $next->id->value !== (string) $this->aggregateId() || $p['previous_operation_id'] !== $this->operation ||
            $p['operation_id'] === $this->operation || (($this->person === null) !== ($p['provenance'] !== 'changed'))
        ) {
            throw new CorruptHistoryException();
        }
        if ($this->person !== null) {
            $before = $this->person;
            $idChanged = $before->institutionalId->value !== $next->institutionalId->value;
            if (
                $before->ownerUserId?->value !== $next->ownerUserId?->value ||
                $next->identityVersion !== $before->identityVersion + (int) $idChanged ||
                ProfileFacts::of($before) === ProfileFacts::of($next)
            ) {
                throw new CorruptHistoryException();
            }
        }
        $this->person = $next;
        $this->operation = $p['operation_id'];
    }
    public function verifyPerson(Person $projection): void
    {
        if ($this->person !== null && ProfileFacts::of($this->person) !== ProfileFacts::of($projection)) {
            throw new CorruptHistoryException();
        }
    }
    public function person(): ?Person
    {
        return $this->person;
    }
    public function whenPersonContactWasRecorded(PersonContactWasRecorded $event): void
    {
        $payload = $event->payload();
        $next = ContactDetails::restore($payload['facts']);
        if (
            $this->person === null || $payload['previous_operation_id'] !== $this->operation
            || $payload['operation_id'] === $this->operation
            || (($this->contact === null) !== ($payload['provenance'] !== 'changed'))
            || ($payload['provenance'] === 'created' && $next->version !== 1)
        ) {
            throw new CorruptHistoryException();
        }
        if (
            $this->contact !== null && ($next->version !== $this->contact->version + 1
            || [$next->preferredName, $next->phone, $next->address]
                === [$this->contact->preferredName, $this->contact->phone, $this->contact->address])
        ) {
            throw new CorruptHistoryException();
        }
        $this->contact = $next;
        $this->operation = $payload['operation_id'];
    }
    public function whenPersonEmergencyContactsWereRecorded(PersonEmergencyContactsWereRecorded $event): void
    {
        $payload = $event->payload();
        $next = EmergencyContacts::restore($payload['facts']);
        if (
            $this->person === null || $payload['previous_operation_id'] !== $this->operation
            || $payload['operation_id'] === $this->operation
            || $next->version !== ($this->emergency === null ? 1 : $this->emergency->version + 1)
            || ($this->emergency !== null && $next->contacts === $this->emergency->contacts)
        ) {
            throw new CorruptHistoryException();
        }
        $this->emergency = $next;
        $this->operation = $payload['operation_id'];
    }
    public function emergency(): ?EmergencyContacts
    {
        return $this->emergency;
    }
    public function contact(): ?ContactDetails
    {
        return $this->contact;
    }
    public function operation(): ?string
    {
        return $this->operation;
    }
}
