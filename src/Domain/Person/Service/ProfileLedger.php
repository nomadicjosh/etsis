<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use Domain\Shared\ValueObject\DerivedIdentity;
use Domain\Person\Event\PersonProfileWasRecorded;
use Domain\Person\Event\PersonContactWasRecorded;
use Domain\Person\Event\PersonEmergencyContactsWereRecorded;
use Domain\Person\ValueObject\EmergencyContacts;
use Domain\Person\ValueObject\AddressBook;
use Domain\Person\Event\PersonAddressesWereRecorded;
use Domain\Person\ValueObject\ContactDetails;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Person\Person;
use Domain\Person\ProfileHistory;
use Domain\Person\ValueObject\PersonProfileId;
use Domain\Person\ValueObject\ProfileFacts;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

/** Internal: authorized entry points own person locks, projection writes, audits and the enclosing transaction. */
final readonly class ProfileLedger
{
    public const int MAX_EVENTS = 2000;

    public function __construct(private HistoricalEventStore $history, private Clock $clock)
    {
    }
    public function accountContext(EntityId $actor, EntityId $operation): EventContext
    {
        return new EventContext($actor, $this->clock->now(), $operation);
    }
    /** @param array<array-key,mixed> $values */
    public static function intent(#[\SensitiveParameter] array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }
    public static function operation(EntityId $person, EntityId $actor, string $intent): EntityId
    {
        return DerivedIdentity::from($person, 'person.profile:' . $actor->value . ':' . $intent);
    }
    public function verify(Person $person): int
    {
        $root = $this->load($person->id)['root'];
        $root->verifyPerson($person);
        return $root->playhead();
    }
    public function receipt(EntityId $person, EntityId $operation, EntityId $actor, string $intent): ?Person
    {
        return $this->load($person, $operation, $actor, $intent)['receipt'];
    }
    public function contactReceipt(
        EntityId $person,
        EntityId $operation,
        EntityId $actor,
        string $intent
    ): ?ContactDetails {
        return $this->load($person, $operation, $actor, $intent)['contact_receipt'];
    }
    public function emergencyReceipt(
        EntityId $person,
        EntityId $operation,
        EntityId $actor,
        string $intent
    ): ?EmergencyContacts {
        return $this->load($person, $operation, $actor, $intent)['emergency_receipt'];
    }
    public function verifyEmergency(Person $person, ?EmergencyContacts $current): void
    {
        $this->checkEmergency($this->load($person->id)['root'], $person, $current);
    }
    private function checkEmergency(ProfileHistory $root, Person $person, ?EmergencyContacts $current): void
    {
        $root->verifyPerson($person);
        if ($root->emergency()?->facts() !== $current?->facts()) {
            throw new CorruptHistoryException();
        }
    }
    public function recordEmergency(
        Person $person,
        ?EmergencyContacts $before,
        EmergencyContacts $after,
        EntityId $operation,
        EntityId $actor,
        string $intent
    ): bool {
        $root = $this->load($person->id)['root'];
        $this->checkEmergency($root, $person, $before);
        if ($after->version !== ($before === null ? 1 : $before->version + 1)) {
            throw new ConcurrentModificationException('Reload the emergency contacts.');
        }
        if ($before !== null && $before->contacts === $after->contacts) {
            return false;
        }
        $this->observePerson($root, $person, $operation, $actor, $intent);
        $event = PersonEmergencyContactsWereRecorded::occur($root->aggregateId(), [
            'operation_id' => $operation->value, 'previous_operation_id' => $root->operation(),
            'facts' => $after->facts(), 'intent' => $intent,
        ], ['__aggregate_type' => 'person_profile'])->withPlayhead($root->playhead() + 1);
        $this->commit($root, $event, $operation, $actor, 'changed');
        return true;
    }
    public function addressReceipt(EntityId $person, EntityId $operation, EntityId $actor, string $intent): ?AddressBook
    {
        return $this->load($person, $operation, $actor, $intent)['address_receipt'];
    }
    public function verifyAddresses(Person $person, ?ContactDetails $contact, ?AddressBook $addresses): void
    {
        $root = $this->load($person->id)['root'];
        $this->checkContact($root, $person, $contact);
        $this->checkAddresses($root, $addresses);
    }
    private function checkAddresses(ProfileHistory $root, ?AddressBook $addresses): void
    {
        if ($root->addresses()?->facts() !== $addresses?->facts()) {
            throw new CorruptHistoryException();
        }
    }
    /** @return array{changed:bool,retired_contact:?ContactDetails} */
    public function recordAddresses(
        Person $person,
        ?ContactDetails $contact,
        ?AddressBook $before,
        AddressBook $after,
        EntityId $operation,
        EntityId $actor,
        string $intent
    ): array {
        $root = $this->load($person->id)['root'];
        $this->checkContact($root, $person, $contact);
        $this->checkAddresses($root, $before);
        if ($after->version !== ($before === null ? 1 : $before->version + 1)) {
            throw new ConcurrentModificationException('Reload the addresses.');
        }
        if (
            ($before === null && $after->addresses === [])
            || ($before !== null && $before->addresses === $after->addresses)
        ) {
            return ['changed' => false, 'retired_contact' => null];
        }
        $this->observePerson($root, $person, $operation, $actor, $intent);
        if ($contact !== null && $root->contact() === null) {
            $this->appendContact(
                $root,
                $contact,
                DerivedIdentity::from($operation, 'person.contact.observation'),
                $actor,
                $intent,
                'observed'
            );
        }
        $retired = null;
        if ($after->mailingManaged && $contact !== null && $contact->address !== '') {
            $retired = new ContactDetails($contact->preferredName, $contact->phone, '', $contact->version + 1);
            $this->appendContact(
                $root,
                $retired,
                DerivedIdentity::from($operation, 'person.mailing.source'),
                $actor,
                $intent,
                'changed'
            );
        }
        $event = PersonAddressesWereRecorded::occur($root->aggregateId(), [
            'operation_id' => $operation->value, 'previous_operation_id' => $root->operation(),
            'facts' => $after->facts(), 'intent' => $intent,
        ], ['__aggregate_type' => 'person_profile'])->withPlayhead($root->playhead() + 1);
        $this->commit($root, $event, $operation, $actor, 'changed');
        return ['changed' => true, 'retired_contact' => $retired];
    }
    public function verifyContact(Person $person, ?ContactDetails $current, ?AddressBook $addresses = null): int
    {
        $root = $this->load($person->id)['root'];
        $this->checkContact($root, $person, $current);
        $this->checkAddresses($root, $addresses);
        return $root->playhead();
    }
    private function checkContact(ProfileHistory $root, Person $person, ?ContactDetails $current): void
    {
        $root->verifyPerson($person);
        if ($root->contact() !== null && $root->contact()->facts() !== $current?->facts()) {
            throw new CorruptHistoryException();
        }
    }
    public function recordContact(
        Person $person,
        ?ContactDetails $before,
        ContactDetails $after,
        EntityId $operation,
        EntityId $actor,
        string $intent,
        ?AddressBook $addresses = null
    ): bool {
        $root = $this->load($person->id)['root'];
        $this->checkContact($root, $person, $before);
        $this->checkAddresses($root, $addresses);
        if ($after->version !== ($before === null ? 1 : $before->version + 1)) {
            throw new ConcurrentModificationException('Reload your current contact information.');
        }
        if (
            $before !== null && [$before->preferredName, $before->phone, $before->address]
            === [$after->preferredName, $after->phone, $after->address]
        ) {
            return false;
        }
        $this->observePerson($root, $person, $operation, $actor, $intent);
        if ($before !== null && $root->contact() === null) {
            $this->appendContact(
                $root,
                $before,
                DerivedIdentity::from($operation, 'person.contact.observation'),
                $actor,
                $intent,
                'observed'
            );
        }
        $this->appendContact($root, $after, $operation, $actor, $intent, $before === null ? 'created' : 'changed');
        return true;
    }
    private function observePerson(
        ProfileHistory $root,
        Person $person,
        EntityId $operation,
        EntityId $actor,
        string $intent
    ): void {
        if ($root->playhead() === 0) {
            $this->append(
                $root,
                $person,
                DerivedIdentity::from($operation, 'person.observation'),
                $actor,
                $intent,
                'observed'
            );
        }
    }
    private function appendContact(
        ProfileHistory $root,
        ContactDetails $contact,
        EntityId $operation,
        EntityId $actor,
        string $intent,
        string $provenance
    ): void {
        $event = PersonContactWasRecorded::occur($root->aggregateId(), [
            'operation_id' => $operation->value, 'previous_operation_id' => $root->operation(),
            'provenance' => $provenance, 'facts' => $contact->facts(), 'intent' => $intent,
        ], ['__aggregate_type' => 'person_profile'])->withPlayhead($root->playhead() + 1);
        $this->commit($root, $event, $operation, $actor, $provenance);
    }
    public function create(Person $person, EntityId $operation, EntityId $actor, string $intent): void
    {
        $root = $this->load($person->id)['root'];
        if ($root->playhead() !== 0) {
            throw new CorruptHistoryException();
        }
        $this->append($root, $person, $operation, $actor, $intent, 'created');
    }
    public function record(Person $before, Person $after, EntityId $operation, EntityId $actor, string $intent): void
    {
        $root = $this->load($before->id)['root'];
        if (
            $before->id->value !== $after->id->value || ($root->person() !== null &&
            ProfileFacts::of($root->person()) !== ProfileFacts::of($before))
        ) {
            throw new CorruptHistoryException();
        }
        if (ProfileFacts::of($before) === ProfileFacts::of($after)) {
            return;
        }
        if ($root->playhead() === 0) {
            $baseline = DerivedIdentity::from($operation, 'person.observation');
            $this->append($root, $before, $baseline, $actor, $intent, 'observed');
        }
        $this->append($root, $after, $operation, $actor, $intent, 'changed');
    }
    private function append(
        ProfileHistory $root,
        Person $p,
        EntityId $operation,
        EntityId $actor,
        string $intent,
        string $provenance
    ): void {
        $version = $root->playhead();
        $e = PersonProfileWasRecorded::occur(
            $root->aggregateId(),
            ['operation_id' => $operation->value,
            'previous_operation_id' => $root->operation(), 'provenance' => $provenance,
            'facts' => ProfileFacts::of($p),
                'intent' => $intent],
            ['__aggregate_type' => 'person_profile']
        )->withPlayhead($version + 1);
        $this->commit($root, $e, $operation, $actor, $provenance);
    }
    private function commit(
        ProfileHistory $root,
        DomainEvent $event,
        EntityId $operation,
        EntityId $actor,
        string $provenance
    ): void {
        $version = $root->playhead();
        if ($version >= self::MAX_EVENTS) {
            throw new HistoryReadLimitException();
        }
        $root->acceptHistorical($event);
        $this->history->append(
            new StreamReference($root->aggregateId(), 'person_profile'),
            $operation,
            $version,
            new EventContext(
                $actor,
                $this->clock->now(),
                $operation,
                reason: $provenance === 'observed'
                    ? 'Current profile observed; earlier personal history is unknown.'
                    : null
            ),
            [$event]
        );
    }
    /** @return array{
     * root:ProfileHistory, receipt:?Person, contact_receipt:?ContactDetails,
     * emergency_receipt:?EmergencyContacts, address_receipt:?AddressBook
     * } */
    private function load(
        EntityId $id,
        ?EntityId $operation = null,
        ?EntityId $actor = null,
        ?string $intent = null
    ): array {
        $root = ProfileHistory::root(new PersonProfileId($id->value));
        $receipt = null;
        $contactReceipt = null;
        $emergencyReceipt = null;
        $addressReceipt = null;
        $cursor = null;
        $count = 0;
        do {
            $page = $this->history->page(new StreamReference($root->aggregateId(), 'person_profile'), $cursor, 20);
            foreach ($page->events as $stored) {
                if (++$count > self::MAX_EVENTS) {
                    throw new HistoryReadLimitException();
                }
                $root->acceptRecorded($stored);
                $p = $stored->event->payload();
                if ($p['operation_id'] === $operation?->value) {
                    if ($stored->context->actor?->value !== $actor?->value || $p['intent'] !== $intent) {
                        throw new AccessDeniedException('This profile operation has another actor or intent.');
                    }
                    if ($stored->event instanceof PersonAddressesWereRecorded) {
                        $addressReceipt = $root->addresses();
                    } elseif ($stored->event instanceof PersonContactWasRecorded) {
                        $contactReceipt = $root->contact();
                    } elseif ($stored->event instanceof PersonEmergencyContactsWereRecorded) {
                        $emergencyReceipt = $root->emergency();
                    } else {
                        $receipt = $root->person();
                    }
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        return ['root' => $root, 'receipt' => $receipt,
            'contact_receipt' => $contactReceipt, 'emergency_receipt' => $emergencyReceipt,
            'address_receipt' => $addressReceipt];
    }
}
