<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Repository\AddressBookRepository;
use Domain\Person\Repository\PersonContactRepository;
use Domain\Person\Repository\PersonProfileRepository;
use Domain\Person\ValueObject\AddressBook;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\DerivedIdentity;
use Domain\Shared\ValueObject\EntityId;
use Qubus\ValueObjects\Geography\CountryCode;
use ReflectionClass;

final readonly class PersonAddressService
{
    public function __construct(
        private AddressBookRepository $addresses,
        private PersonContactRepository $contacts,
        private PersonProfileRepository $rows,
        private ProfileLedger $history,
        private PersonProfileAccess $authorization,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }
    /** @return array<string,mixed> */
    public function view(?EntityId $person = null, bool $staff = false): array
    {
        $actor = $this->authorization->actor();
        $record = $this->authorization->person($actor, $person, $staff);
        $book = $this->addresses->current($record->id);
        $contact = $this->contacts->current($record->id);
        $this->history->verifyAddresses($record, $contact, $book);
        $today = $this->clock->now()->format('Y-m-d');
        $countries = [];
        foreach (new ReflectionClass(CountryCode::class)->getConstants() as $code) {
            if (is_string($code) && strlen($code) === 2) {
                $countries[$code] = AddressBook::countryName($code);
            }
        }
        asort($countries);
        $this->authorization->person($actor, $person, $staff);
        return ['addresses' => $book->addresses ?? [], 'version' => $book->version ?? 0,
            'mailing_managed' => $book->mailingManaged ?? false, 'today' => $today,
            'mailing_address' => $book?->mailingManaged ? $book->mailingOn($today) : ($contact->address ?? ''),
            'earlier_mailing_address' => $book?->mailingManaged ? '' : ($contact->address ?? ''),
            'new_ids' => array_map(static fn(): string => EntityId::generate()->value, range(1, AddressBook::LIMIT)),
            'contact_revision' => ['preferred_name' => $contact->preferredName ?? '',
                'phone' => $contact->phone ?? '', 'version' => $contact->version ?? 0],
            'purposes' => AddressBook::PURPOSES, 'countries' => $countries];
    }
    /** @param array<mixed> $input */
    public function save(#[\SensitiveParameter] array $input, int $version, ?EntityId $staffPerson = null): void
    {
        $actor = $this->authorization->actor();
        $staff = $staffPerson !== null;
        $person = $this->authorization->person($actor, $staffPerson, $staff);
        if ($version < 0 || $version === PHP_INT_MAX) {
            throw new ValidationException([['field' => 'version', 'message' => 'Reload the addresses.']]);
        }
        $requested = AddressBook::fromInput($input, $version + 1, false);
        $intent = ProfileLedger::intent([$version, $requested->addresses]);
        $operation = DerivedIdentity::from($person->id, 'person.addresses:' . $actor->value . ':' . $intent);
        $this->transactions->run(function () use (
            $actor,
            $staff,
            $person,
            $requested,
            $version,
            $intent,
            $operation
        ): void {
            if ($staff) {
                $this->authorization->lockStaffRoles();
            }
            $this->rows->lock($person->id);
            $record = $this->authorization->person($actor, $person->id, $staff);
            if ($this->history->addressReceipt($person->id, $operation, $actor, $intent) !== null) {
                $this->authorization->person($actor, $person->id, $staff);
                return;
            }
            $before = $this->addresses->current($record->id);
            $next = AddressBook::fromInput($requested->addresses, $version + 1, $before->mailingManaged ?? false);
            $contact = $this->contacts->current($record->id);
            $result = $this->history->recordAddresses($record, $contact, $before, $next, $operation, $actor, $intent);
            if (!$result['changed']) {
                $this->authorization->person($actor, $person->id, $staff);
                return;
            }
            if ($result['retired_contact'] !== null) {
                $this->contacts->save($person->id, $result['retired_contact'], $contact->version ?? 0);
            }
            $this->addresses->save($person->id, $next, $version);
            $this->audit->record($actor, 'person.addresses_updated', $person->id);
            $this->authorization->person($actor, $person->id, $staff);
        });
    }
}
