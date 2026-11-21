<?php

declare(strict_types=1);

namespace Infrastructure\Projection;

use Domain\Person\Person;
use Domain\Person\ProfileHistory;
use Domain\Person\Repository\AddressBookRepository;
use Domain\Person\Repository\EducationBackgroundRepository;
use Domain\Person\Repository\EmergencyContactsRepository;
use Domain\Person\Repository\PersonContactRepository;
use Domain\Person\Repository\PersonProjectionVerifier;
use Domain\Person\ValueObject\PersonProfileId;
use Domain\Person\ValueObject\PersonProjectionCheck;
use Domain\Person\ValueObject\ProfileFacts;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;
use Throwable;

/** Selected, observational check. No profile service, lock, audit, repair or credential reads. */
final readonly class SqlPersonProjectionVerifier implements PersonProjectionVerifier
{
    private const array TABLES = [
        'identity' => 'sis_person_identifiers', 'contact' => 'sis_portal_contacts',
        'addresses' => 'sis_person_addresses', 'emergency_contacts' => 'sis_person_emergency_contacts',
        'education' => 'sis_person_education',
    ];

    public function __construct(
        private SqlRecords $sql,
        private HistoricalEventStore $history,
        private PersonContactRepository $contacts,
        private AddressBookRepository $addresses,
        private EmergencyContactsRepository $emergency,
        private EducationBackgroundRepository $education,
    ) {
    }

    public function verify(EntityId $person, int $eventLimit = 500): PersonProjectionCheck
    {
        if ($eventLimit < 1 || $eventLimit > 2000) {
            throw new InvalidArgumentException('Use a person verification allowance of 1–2000 events.');
        }
        $budget = new HistoryReadBudget(
            maximumEvents: $eventLimit,
            maximumBytes: 4_194_304,
            maximumMilliseconds: 5000
        );
        $before = null;
        $position = null;
        $issues = $unrecorded = [];
        $earlier = null;
        $status = 'unverified';
        try {
            if ($this->sql->inTransaction() || $this->sql->tableExists('event_store')) {
                throw new CorruptHistoryException();
            }
            $before = $this->boundary($person);
            if ($before['person'] === []) {
                $issues[] = 'identity';
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
                $stream = new StreamReference(new PersonProfileId($person->value), 'person_profile');
                $root = ProfileHistory::root($stream->aggregateId);
                $cursor = null;
                $earlier = false;
                do {
                    $page = $budget->page($this->history, $stream, $cursor);
                    if ($page->throughVersion !== $position) {
                        $status = 'changed';
                        break;
                    }
                    foreach ($page->events as $stored) {
                        $root->acceptRecorded($stored);
                        $earlier = $earlier || ($stored->event->payload()['provenance'] ?? null) === 'observed';
                    }
                    $cursor = $page->nextCursor;
                } while ($cursor !== null);
                if ($status !== 'changed') {
                    if ($root->playhead() !== $position || ($position > 0 && $root->person() === null)) {
                        throw new CorruptHistoryException();
                    }
                    $earlier = $earlier || $root->earlierHistoryUnknown();
                    if ($root->person() === null) {
                        $unrecorded[] = 'identity';
                    }
                    try {
                        $current = $this->person($person, $before);
                        $root->verifyPerson($current);
                    } catch (CorruptHistoryException) {
                        $issues[] = 'identity';
                    }
                    if (!$this->accountMatches($before)) {
                        $issues[] = 'account_link';
                    }
                    foreach (
                        [
                        'contact' => [$this->contacts->current(...), $root->contact()],
                        'addresses' => [$this->addresses->current(...), $root->addresses()],
                        'emergency_contacts' => [$this->emergency->current(...), $root->emergency()],
                        'education' => [$this->education->current(...), $root->education()],
                        ] as $component => [$read, $expected]
                    ) {
                        try {
                            if (count($before[$component]) > 1) {
                                throw new CorruptHistoryException();
                            }
                            $actual = $read($person);
                            if ($component === 'contact' && $expected === null && $actual !== null) {
                                // Freeform contact rows predate this stream; they cannot be adopted by a diagnostic.
                                $unrecorded[] = 'contact';
                            } elseif ($expected?->facts() !== $actual?->facts()) {
                                throw new CorruptHistoryException();
                            }
                        } catch (CorruptHistoryException) {
                            $issues[] = $component;
                        }
                    }
                    $status = $issues !== [] ? 'unverified' :
                    ($earlier || $unrecorded !== [] ? 'history_gaps' : 'verified');
                }
            }
        } catch (HistoryReadLimitException) {
            $status = 'limited';
            $issues = [];
        } catch (CorruptHistoryException) {
            $status = 'unverified';
            $issues = ['journal'];
        } catch (Throwable) {
            $status = 'unverified';
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
            } catch (Throwable) {
                $status = 'unverified';
                $issues = ['unavailable'];
            }
        }
        $complete = in_array($status, ['verified', 'history_gaps'], true);
        return new PersonProjectionCheck(
            $status,
            $person,
            $budget->eventsRead(),
            $position,
            $issues,
            $complete ? $unrecorded : [],
            $complete ? $earlier : null
        );
    }

    /** @param array<string,mixed> $snapshot */
    private function person(EntityId $id, #[\SensitiveParameter] array $snapshot): Person
    {
        if (count($snapshot['person']) !== 1 || count($snapshot['identity']) !== 1) {
            throw new CorruptHistoryException();
        }
        $row = $snapshot['person'][0];
        $identity = $snapshot['identity'][0];
        if (
            !in_array($row['directory_restricted'], [0, 1, '0', '1', false, true], true) ||
            (string) (int) $identity['version'] !== (string) $identity['version']
        ) {
            throw new CorruptHistoryException();
        }
        return ProfileFacts::person([
            'id' => $id->value, 'owner_user_id' => $row['owner_user_id'], 'given_name' => $row['given_name'],
            'family_name' => $row['family_name'], 'directory_restricted' => (bool) $row['directory_restricted'],
            'institutional_id' => $identity['institutional_id'], 'identity_version' => (int) $identity['version'],
        ]);
    }

    /** @param array<string,mixed> $snapshot */
    private function accountMatches(#[\SensitiveParameter] array $snapshot): bool
    {
        $person = $snapshot['person'][0] ?? null;
        $user = $snapshot['account'][0] ?? null;
        return count($snapshot['account']) === 1 && count($snapshot['linked_people']) === 1 &&
        $person['owner_user_id'] === $user['user_id'] && $snapshot['linked_people'][0]['id'] === $person['id'] &&
        $person['given_name'] === $user['first_name'] && $person['family_name'] === $user['last_name'];
    }

    /** Current scalar rows and bounded encrypted projections. Kept only in this request; never returned.
     * @return array<string,mixed> */
    private function boundary(EntityId $person): array
    {
        $q = $this->sql->quote(...);
        $result = ['person' => $this->sql->rows('sis_persons', ['id' => $person->value], 2),
            'head' => $this->sql->fetch('SELECT '
                . implode(', ', array_map($q, ['id', 'stream_type', 'aggregate_id', 'version', 'head_hash']))
                . ' FROM ' . $q('sis_history_streams') . ' WHERE ' . $q('stream_type') . ' = ? AND '
                . $q('aggregate_id') . ' = ?', ['person_profile', $person->value], 2),
            'account' => [], 'linked_people' => [],
        ];
        foreach (self::TABLES as $component => $table) {
            $result[$component] = $this->sql->rows($table, ['person_id' => $person->value], 2);
        }
        $owner = $result['person'][0]['owner_user_id'] ?? null;
        if ($owner !== null) {
            $result['account'] = $this->sql->fetch('SELECT '
                . implode(', ', array_map($q, ['user_id', 'first_name', 'last_name']))
                . ' FROM ' . $q('users') . ' WHERE ' . $q('user_id') . ' = ?', [$owner], 2);
            $result['linked_people'] = $this->sql->fetch('SELECT ' . $q('id') . ' FROM ' . $q('sis_persons')
                . ' WHERE ' . $q('owner_user_id') . ' = ?', [$owner], 2);
        }
        return $result;
    }
}
