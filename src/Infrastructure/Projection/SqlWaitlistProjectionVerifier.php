<?php

declare(strict_types=1);

namespace Infrastructure\Projection;

use Domain\Registration\Waitlist\SectionWaitlist;
use Domain\Registration\Waitlist\WaitlistLedger;
use Domain\Registration\Waitlist\WaitlistProjectionCheck;
use Domain\Registration\Waitlist\WaitlistProjectionVerifier;
use Domain\Registration\Waitlist\WaitlistRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;
use Throwable;

/** Read outside a snapshot; verify through the ordinary protected queue reader and compare boundaries. */
final readonly class SqlWaitlistProjectionVerifier implements WaitlistProjectionVerifier
{
    public function __construct(
        private SqlRecords $sql,
        private WaitlistRepository $records,
        private WaitlistLedger $ledger,
    ) {
    }

    public function verify(EntityId $entry, int $eventLimit = 500): WaitlistProjectionCheck
    {
        if ($eventLimit < 1 || $eventLimit > 2000) {
            throw new InvalidArgumentException('Use a waitlist verification allowance of 1–2000 events.');
        }
        if ($this->sql->inTransaction()) {
            return new WaitlistProjectionCheck('unverified', $entry, 0, issues: ['unavailable']);
        }
        $budget = new HistoryReadBudget(
            maximumEvents: $eventLimit,
            maximumBytes: 4_194_304,
            maximumMilliseconds: 5000,
        );
        $before = null;
        $status = 'unverified';
        $issues = ['waitlist_entry'];
        $position = null;
        try {
            if ($this->sql->tableExists('event_store')) {
                throw new CorruptHistoryException();
            }
            $before = $this->boundary($entry);
            $row = $this->records->entry($entry);
            if ($row !== null && count($before['section']) === 1) {
                if (
                    count($before['student']) !== 1 || count($before['person']) !== 1
                    || $row['person_id'] !== $before['student'][0]['person_id']
                ) {
                    $issues = ['student_identity'];
                } else {
                    $section = new EntityId($row['section_id']);
                    $version = $before['head'][0]['version'] ?? null;
                    if (
                        count($before['head']) !== 1 || !is_scalar($version)
                        || !preg_match('/^[1-9][0-9]{0,3}$/D', (string) $version)
                        || (string) (int) $version !== (string) $version
                        || (int) $version > SectionWaitlist::MAX_EVENTS
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $position = (int) $version;
                    if ($position > $eventLimit) {
                        throw new HistoryReadLimitException();
                    }
                    $root = $this->ledger->load($section, $budget);
                    $accepted = $root->entries()[$entry->value] ?? null;
                    if ($accepted === null || $root->playhead() !== $position) {
                        throw new CorruptHistoryException();
                    }
                    unset($accepted['changed_at'], $row['section_id']);
                    ksort($accepted);
                    ksort($row);
                    if ($accepted !== $row) {
                        throw new CorruptHistoryException();
                    }
                    $status = 'verified';
                    $issues = [];
                }
            }
            $budget->checkTime();
        } catch (HistoryReadLimitException) {
            $status = 'limited';
            $issues = [];
        } catch (CorruptHistoryException | ConcurrentModificationException) {
            $status = 'unverified';
            $issues = ['waitlist_entry'];
        } catch (Throwable) {
            $status = 'unverified';
            $issues = ['unavailable'];
        }
        if ($before !== null) {
            try {
                if ($before !== $this->boundary($entry)) {
                    $status = 'changed';
                    $issues = [];
                }
                $budget->checkTime();
            } catch (HistoryReadLimitException) {
                if ($status !== 'changed') {
                    $status = 'limited';
                    $issues = [];
                }
            } catch (Throwable) {
                $status = 'unverified';
                $issues = ['unavailable'];
            }
        }
        return new WaitlistProjectionCheck($status, $entry, $budget->eventsRead(), $position, $issues);
    }

    private function boundary(EntityId $entry): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->rows('sis_waitlist_entries', ['id' => $entry->value], 2);
        $section = $rows[0]['section_id'] ?? null;
        $student = $rows[0]['student_id'] ?? null;
        $students = $student === null ? [] : $this->sql->fetch('SELECT '
            . implode(', ', array_map($q, ['id', 'person_id'])) . ' FROM ' . $q('sis_students')
            . ' WHERE ' . $q('id') . ' = ?', [$student], 2);
        $person = $students[0]['person_id'] ?? null;
        return [
            'entry' => $rows,
            'student' => $students,
            'person' => $person === null ? [] : $this->sql->fetch('SELECT '
                . implode(', ', array_map($q, ['id', 'owner_user_id'])) . ' FROM ' . $q('sis_persons')
                . ' WHERE ' . $q('id') . ' = ?', [$person], 2),
            'section' => $section === null ? [] : $this->sql->fetch('SELECT ' . $q('id')
                . ' FROM ' . $q('sis_sections') . ' WHERE ' . $q('id') . ' = ?', [$section], 2),
            'state' => $section === null ? [] : $this->sql->rows('sis_waitlist_state', ['section_id' => $section], 2),
            'active' => $section === null ? [] : $this->records->active(new EntityId($section)),
            'head' => $section === null ? [] : $this->sql->fetch('SELECT '
                . implode(', ', array_map($q, ['id', 'stream_type', 'aggregate_id', 'version', 'head_hash']))
                . ' FROM ' . $q('sis_history_streams') . ' WHERE ' . $q('stream_type') . ' = ? AND '
                . $q('aggregate_id') . ' = ?', ['section_waitlist', $section], 2),
        ];
    }
}
