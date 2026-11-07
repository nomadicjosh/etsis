<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Person\Person;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Person\Service\InstitutionalIdGenerator;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlPersonAccountRepository implements PersonAccountRepository
{
    public function __construct(
        private SqlRecords $sql,
        private AdmissionsRepository $admissions,
        private InstitutionalIdGenerator $identifiers
    ) {
    }

    public function ensure(EntityId $user, string $givenName, string $familyName, ?EntityId $person = null): Person
    {
        $q = $this->sql->quote(...);
        $this->sql->execute('UPDATE ' . $q('users') . ' SET ' . $q('user_id') . ' = ' . $q('user_id')
            . ' WHERE ' . $q('user_id') . ' = ?', [$user->value]);
        $linked = $this->sql->rows('sis_persons', ['owner_user_id' => $user->value], 2);
        if (count($linked) > 1) {
            throw new DomainException('Resolve duplicate account-to-person links before continuing.');
        }
        if ($linked !== []) {
            if ($person !== null && $person->value !== $linked[0]['id']) {
                throw new DomainException('This account is already attached to another person.');
            }

            return $this->admissions->person(new EntityId($linked[0]['id']));
        }
        if ($person !== null) {
            $existing = $this->admissions->person($person);
            if ($existing->ownerUserId !== null) {
                throw new DomainException('This person already has sign-in access.');
            }
            $changed = $this->sql->execute(
                'UPDATE ' . $q('sis_persons') . ' SET ' . $q('owner_user_id')
                . ' = ? WHERE ' . $q('id') . ' = ? AND ' . $q('owner_user_id') . ' IS NULL',
                [$user->value, $person->value]
            );
            if ($changed !== 1) {
                throw new DomainException('This person already has sign-in access.');
            }

            return $this->admissions->person($person);
        }
        $created = new Person(EntityId::generate(), $user, $givenName, $familyName, $this->identifiers->next());
        $this->admissions->addPerson($created);

        return $created;
    }

    public function updateNames(EntityId $user, string $givenName, string $familyName): void
    {
        $person = $this->ensure($user, $givenName, $familyName);
        new Person($person->id, $user, $givenName, $familyName, $person->institutionalId);
        $this->sql->update(
            'sis_persons',
            ['given_name' => $givenName, 'family_name' => $familyName],
            ['id' => $person->id->value]
        );
    }
}
