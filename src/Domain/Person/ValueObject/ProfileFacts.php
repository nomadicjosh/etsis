<?php

declare(strict_types=1);

namespace Domain\Person\ValueObject;

use Domain\Person\Person;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Throwable;

final class ProfileFacts
{
    public const array FIELDS = ['id', 'owner_user_id', 'given_name', 'family_name', 'institutional_id',
        'directory_restricted', 'identity_version'];
    /** @return array<string,scalar|null> */
    public static function of(Person $p): array
    {
        return ['id' => $p->id->value, 'owner_user_id' => $p->ownerUserId?->value,
            'given_name' => $p->givenName, 'family_name' => $p->familyName,
            'institutional_id' => $p->institutionalId->value, 'directory_restricted' => $p->directoryRestricted,
            'identity_version' => $p->identityVersion];
    }
    /** @param array<string,mixed> $f */
    public static function person(array $f): Person
    {
        try {
            if (
                count($f) !== 7 || array_diff(array_keys($f), self::FIELDS) !== [] ||
                !is_string($f['id']) || ($f['owner_user_id'] !== null && !is_string($f['owner_user_id'])) ||
                !is_string($f['given_name']) || !is_string($f['family_name']) ||
                !mb_check_encoding($f['given_name'] . $f['family_name'], 'UTF-8') ||
                !is_string($f['institutional_id']) || !is_bool($f['directory_restricted']) ||
                !is_int($f['identity_version'])
            ) {
                throw new CorruptHistoryException();
            }
            $person = new Person(
                new EntityId($f['id']),
                $f['owner_user_id'] === null ? null : new EntityId($f['owner_user_id']),
                $f['given_name'],
                $f['family_name'],
                new InstitutionalId($f['institutional_id']),
                $f['directory_restricted'],
                $f['identity_version']
            );
            if (self::of($person) != $f) {
                throw new CorruptHistoryException();
            }
            return $person;
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }
    /** @param array<string,mixed> $p */
    public static function validate(array $p): void
    {
        if (
            count($p) !== 5
                || array_diff(
                    array_keys($p),
                    ['operation_id',
                    'previous_operation_id',
                    'provenance',
                    'facts',
                    'intent']
                ) !== []
            || !is_string($p['operation_id'])
                || ($p['previous_operation_id'] !== null
                && !is_string($p['previous_operation_id']))
            || !in_array($p['provenance'], ['created', 'observed', 'changed'], true)
            || !is_array($p['facts'])
            || !is_string($p['intent'])
            || preg_match('/^[a-f0-9]{64}$/D', $p['intent']) !== 1
        ) {
            throw new CorruptHistoryException();
        }
        new EntityId($p['operation_id']);
        if ($p['previous_operation_id'] !== null) {
            new EntityId($p['previous_operation_id']);
        }
        $person = self::person($p['facts']);
        if ($p['provenance'] !== 'observed' && $person->ownerUserId === null) {
            throw new CorruptHistoryException();
        }
    }
}
