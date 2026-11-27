<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Registration\Repository\RegistrationOpenRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlRegistrationOpenRepository implements RegistrationOpenRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function person(EntityId $owner): ?EntityId
    {
        [$sql, $parameters] = $this->audience($owner);
        $rows = $this->sql->fetch($sql, $parameters, 1);
        return $rows === [] ? null : new EntityId($rows[0]['id']);
    }

    private function audience(EntityId $owner, ?EntityId $person = null): array
    {
        $q = $this->sql->quote(...);
        $sql = 'SELECT p.' . $q('id') . ' FROM ' . $q('sis_persons') . ' p JOIN ' . $q('users') . ' u ON u.'
        . $q('user_id') . ' = p.' . $q('owner_user_id') . ' WHERE u.' . $q('user_id') . ' = ? AND u.'
        . $q('sign_in_enabled') . ' = 1 AND EXISTS (SELECT 1 FROM ' . $q('sis_students') . ' s WHERE s.'
        . $q('person_id') . ' = p.' . $q('id') . ' AND s.' . $q('active')
        . ' = 1 AND EXISTS (SELECT 1 FROM ' . $q('sis_student_programs') . ' pursuit'
        . ' WHERE pursuit.' . $q('student_id') . ' = s.' . $q('id') . ' AND pursuit.' . $q('status') . " = 'active'))";
        $parameters = [$owner->value];
        if ($person !== null) {
            $sql .= ' AND p.' . $q('id') . ' = ?';
            $parameters[] = $person->value;
        }
        return [$sql, $parameters];
    }

    private function query(EntityId $owner, EntityId $person, string $now): array
    {
        $q = $this->sql->quote(...);
        $parameters = [$owner->value, $person->value, $owner->value, $person->value, $now, $now];
        $sql = 'SELECT w.*, r.' . $q('revision') . ' AS ' . $q('read_revision') . ', r.'
        . $q('publication_id') . ' AS ' . $q('read_publication_id') . ', d.' . $q('revision')
        . ' AS ' . $q('dismissed_revision') . ', d.' . $q('publication_id') . ' AS ' . $q('dismissed_publication_id')
        . ' FROM ' . $q('sis_reg_windows') . ' w LEFT JOIN ' . $q('sis_registration_open_reads') . ' r ON r.'
        . $q('owner_user_id') . ' = ? AND r.' . $q('person_id') . ' = ? AND r.' . $q('term_id') . ' = w.'
        . $q('term_id') . ' LEFT JOIN ' . $q('sis_registration_open_dismissals') . ' d ON d.'
        . $q('owner_user_id') . ' = ? AND d.' . $q('person_id') . ' = ? AND d.' . $q('term_id') . ' = w.'
        . $q('term_id') . ' WHERE w.' . $q('publication_id') . ' IS NOT NULL AND w.' . $q('opens_at')
        . ' <= ? AND w.' . $q('closes_at') . ' >= ?';
        [$audience, $bindings] = $this->audience($owner, $person);
        return [$sql . ' AND EXISTS (' . $audience . ')', [...$parameters, ...$bindings]];
    }

    public function page(EntityId $owner, EntityId $person, string $now, bool $unreadOnly, ?array $after): array
    {
        [$sql, $parameters] = $this->query($owner, $person, $now);
        $q = $this->sql->quote(...);
        $sql .= ' AND (d.' . $q('publication_id') . ' IS NULL OR d.' . $q('publication_id') . ' <> w.'
        . $q('publication_id') . ' OR d.' . $q('revision') . ' <> w.' . $q('policy_version') . ')';
        if ($unreadOnly) {
            $sql .= ' AND (r.' . $q('publication_id') . ' IS NULL OR r.' . $q('publication_id') . ' <> w.'
            . $q('publication_id') . ' OR r.' . $q('revision') . ' <> w.' . $q('policy_version') . ')';
        }
        if ($after !== null) {
            $sql .= ' AND (w.' . $q('opens_at') . ' < ? OR (w.' . $q('opens_at') . ' = ? AND w.'
            . $q('term_id') . ' < ?))';
            array_push($parameters, $after['at'], $after['at'], $after['id']);
        }
        return $this->sql->fetch(
            $sql . ' ORDER BY w.' . $q('opens_at') . ' DESC, w.' . $q('term_id') . ' DESC',
            $parameters,
            21
        );
    }

    public function candidate(EntityId $owner, EntityId $person, EntityId $term, string $now): ?array
    {
        [$sql, $parameters] = $this->query($owner, $person, $now);
        $sql .= ' AND w.' . $this->sql->quote('term_id') . ' = ?';
        $parameters[] = $term->value;
        return $this->sql->fetch($sql, $parameters, 1)[0] ?? null;
    }

    private function key(EntityId $owner, EntityId $person, EntityId $term): array
    {
        return ['owner_user_id' => $owner->value, 'person_id' => $person->value, 'term_id' => $term->value];
    }

    public function readState(EntityId $owner, EntityId $person, EntityId $term): ?array
    {
        return $this->sql->rows('sis_registration_open_reads', $this->key($owner, $person, $term), 1)[0] ?? null;
    }

    public function dismissal(EntityId $owner, EntityId $person, EntityId $term): ?array
    {
        return $this->sql->rows('sis_registration_open_dismissals', $this->key($owner, $person, $term), 1)[0] ?? null;
    }

    private function save(string $table, array $key, array $values): void
    {
        if ($this->sql->rows($table, $key, 1) === []) {
            $this->sql->insert($table, ['id' => EntityId::generate()->value] + $key + $values);
        } else {
            $this->sql->update($table, $values, $key);
        }
    }

    public function markRead(EntityId $owner, EntityId $person, EntityId $term, array $publication, string $at): void
    {
        $this->save(
            'sis_registration_open_reads',
            $this->key($owner, $person, $term),
            $publication + ['read_at' => $at]
        );
    }

    public function dismiss(EntityId $owner, EntityId $person, EntityId $term, array $publication): void
    {
        $key = $this->key($owner, $person, $term);
        $this->save('sis_registration_open_dismissals', $key, $publication);
        $q = $this->sql->quote(...);
        $this->sql->execute(
            'DELETE FROM ' . $q('sis_registration_open_reads') . ' WHERE '
            . $q('owner_user_id') . ' = ? AND ' . $q('person_id') . ' = ? AND ' . $q('term_id') . ' = ?',
            array_values($key)
        );
    }
}
