<?php

declare(strict_types=1);

namespace Infrastructure\Projection;

use Domain\Person\Repository\PersonSsnProjectionVerifier;
use Domain\Person\Service\PersonSsnReader;
use Domain\Person\ValueObject\PersonSsnProjectionCheck;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

/** Observation outside snapshot transactions. No write, lock, audit, value disclosure or repair. */
final readonly class SqlPersonSsnProjectionVerifier implements PersonSsnProjectionVerifier
{
    public function __construct(private SqlRecords $sql, private PersonSsnReader $reader)
    {
    }

    public function verify(EntityId $person, int $eventLimit = 500): PersonSsnProjectionCheck
    {
        if ($eventLimit < 1 || $eventLimit > PersonSsnReader::MAX_EVENTS) {
            throw new \InvalidArgumentException('Use an SSN verification allowance of 1–500 events.');
        }
        $budget = new HistoryReadBudget(
            maximumEvents: $eventLimit,
            maximumBytes: 2097152,
            maximumMilliseconds: 5000
        );
        $before = null;
        $position = null;
        $status = 'unverified';
        $issues = [];
        try {
            if ($this->sql->inTransaction() || $this->sql->tableExists('event_store')) {
                throw new CorruptHistoryException();
            }
            $before = $this->boundary($person);
            if (
                count($before['person']) !== 1 || count($before['identity']) !== 1
                || count($before['account']) !== 1 || count($before['linked_people']) !== 1
                || $before['linked_people'][0]['id'] !== $person->value
            ) {
                $issues = ['identity'];
            } else {
                if (count($before['head']) > 1) {
                    throw new CorruptHistoryException();
                }
                $head = $before['head'][0] ?? null;
                $position = $head === null ? 0 : (int) $head['version'];
                if ($head !== null && ($position < 1 || (string) $position !== (string) $head['version'])) {
                    throw new CorruptHistoryException();
                }
                if ($position > $eventLimit) {
                    throw new HistoryReadLimitException();
                }
                $root = $this->reader->replay($person, $budget);
                if ($root->playhead() !== $position) {
                    $status = 'changed';
                } else {
                    try {
                        if (count($before['projection']) > 1) {
                            throw new CorruptHistoryException();
                        }
                        $this->reader->verifyProjection($person, $root, $before['projection'][0] ?? null);
                        $status = $position === 0 ? 'observed_empty' : 'verified';
                    } catch (CorruptHistoryException) {
                        $issues = ['ssn_projection'];
                    }
                }
            }
        } catch (HistoryReadLimitException) {
            $status = 'limited';
            $issues = [];
        } catch (CorruptHistoryException) {
            $issues = ['journal'];
        } catch (\Throwable) {
            $issues = ['unavailable'];
        }
        if ($before !== null) {
            try {
                if ($before !== $this->boundary($person)) {
                    $status = 'changed';
                    $issues = [];
                }
                $budget->checkTime();
            } catch (HistoryReadLimitException) {
                $status = 'limited';
                $issues = [];
            } catch (\Throwable) {
                $status = 'unverified';
                $issues = ['unavailable'];
            }
        }
        return new PersonSsnProjectionCheck(
            $status,
            $person,
            $budget->eventsRead(),
            in_array($status, ['verified', 'observed_empty'], true) ? $position : null,
            $issues
        );
    }

    private function boundary(EntityId $person): array
    {
        $q = $this->sql->quote(...);
        $select = fn(string $table, array $fields, string $key, string $id): array =>
            $this->sql->fetch('SELECT ' . implode(', ', array_map($q, $fields)) . ' FROM ' . $q($table)
                . ' WHERE ' . $q($key) . ' = ?', [$id], 2);
        $people = $select('sis_persons', ['id', 'owner_user_id'], 'id', $person->value);
        $owner = $people[0]['owner_user_id'] ?? null;
        $headFields = ['id', 'stream_type', 'aggregate_id', 'version', 'head_hash', 'sealed_anchor'];
        return ['person' => $people,
            'identity' => $select(
                'sis_person_identifiers',
                ['id', 'person_id', 'version'],
                'person_id',
                $person->value
            ),
            'account' => $owner === null ? [] : $select('users', ['user_id'], 'user_id', $owner),
            'linked_people' => $owner === null ? [] : $select('sis_persons', ['id'], 'owner_user_id', $owner),
            'head' => $this->sql->fetch('SELECT '
                . implode(', ', array_map($q, $headFields))
                . ' FROM ' . $q('sis_history_streams') . ' WHERE ' . $q('stream_type') . ' = ? AND '
                . $q('aggregate_id') . ' = ?', ['person_ssn', $person->value], 2),
            'projection' => $select('sis_person_ssns', ['id', 'version', 'sealed_data'], 'id', $person->value),
        ];
    }
}
