<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use DateTimeImmutable;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\RestrictionRepository;
use Domain\Student\Restriction;
use Infrastructure\Persistence\SqlRecords;
use PDOException;

final readonly class SqlRestrictionRepository implements RestrictionRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function lockPerson(EntityId $person): void
    {
        $this->sql->lock('sis_persons', $person->value, 'id');
    }

    public function lock(EntityId $restriction): void
    {
        $this->sql->lock('sis_restrictions', $restriction->value, 'id');
    }

    public function find(EntityId $restriction): ?Restriction
    {
        $row = $this->sql->rows('sis_restrictions', ['id' => $restriction->value], 1)[0] ?? null;
        if ($row === null) {
            return null;
        }

        return new Restriction(
            $restriction,
            new EntityId($row['person_id']),
            $row['code'],
            (int) $row['severity'],
            new DateRange(
                new DateTimeImmutable($row['starts_on']),
                $row['ends_on'] === null ? null : new DateTimeImmutable($row['ends_on'])
            ),
            (bool) $row['blocks_staff']
        );
    }

    public function add(Restriction $restriction): void
    {
        $this->insert('sis_restrictions', ['id' => $restriction->id->value,
            'person_id' => $restriction->personId->value,
            'code' => $restriction->code, 'severity' => $restriction->severity,
            'starts_on' => $restriction->dates->start->format('Y-m-d'),
            'ends_on' => $restriction->dates->end?->format('Y-m-d'),
            'blocks_staff' => (int) $restriction->blocksStaffRegistration]);
    }

    public function setEnd(EntityId $restriction, string $on): void
    {
        $this->sql->update('sis_restrictions', ['ends_on' => $on], ['id' => $restriction->value]);
    }

    public function page(EntityId $person, ?EntityId $after, int $limit): array
    {
        return $this->paged('sis_restrictions', 'person_id', $person, $after, $limit);
    }

    public function note(EntityId $id): ?array
    {
        return $this->sql->rows('sis_restriction_notes', ['id' => $id->value], 1)[0] ?? null;
    }

    public function addNote(
        EntityId $id,
        EntityId $restriction,
        EntityId $actor,
        #[\SensitiveParameter] string $encrypted,
        string $createdAt
    ): void {
        $this->insert('sis_restriction_notes', ['id' => $id->value, 'restriction_id' => $restriction->value,
            'actor_id' => $actor->value, 'encrypted_content' => $encrypted, 'created_at' => $createdAt]);
    }

    public function notes(EntityId $restriction, ?EntityId $after, int $limit): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            'SELECT n.' . $q('id') . ', n.' . $q('actor_id') . ', n.' . $q('created_at')
            . ', n.' . $q('encrypted_content') . ', u.' . $q('first_name') . ' AS ' . $q('actor_given_name')
            . ', u.' . $q('last_name') . ' AS ' . $q('actor_family_name') . ' FROM ' . $q('sis_restriction_notes')
            . ' n JOIN ' . $q('users') . ' u ON u.' . $q('user_id') . ' = n.' . $q('actor_id')
            . ' WHERE n.' . $q('restriction_id') . ' = ?' . ($after === null ? '' : ' AND n.' . $q('id') . ' < ?')
            . ' ORDER BY n.' . $q('id') . ' DESC',
            $after === null ? [$restriction->value] : [$restriction->value, $after->value],
            $limit
        );
    }

    /** @return list<array<string, mixed>> */
    private function paged(string $table, string $field, EntityId $id, ?EntityId $after, int $limit): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            'SELECT * FROM ' . $q($table) . ' WHERE ' . $q($field) . ' = ?'
            . ($after === null ? '' : ' AND ' . $q('id') . ' < ?') . ' ORDER BY ' . $q('id') . ' DESC',
            $after === null ? [$id->value] : [$id->value, $after->value],
            $limit
        );
    }

    /** @param array<string, scalar|null> $data */
    private function insert(string $table, #[\SensitiveParameter] array $data): void
    {
        try {
            $this->sql->insert($table, $data);
        } catch (PDOException $error) {
            if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                throw new ConcurrentModificationException('Operation ID already in use. Reload before retrying.');
            }
            throw $error;
        }
    }
}
