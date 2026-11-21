<?php

declare(strict_types=1);

namespace Infrastructure\Projection;

use Codefy\Domain\EventSourcing\EventStream;
use Domain\Finance\Repository\AccountProjectionVerifier;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\AccountProjectionCheck;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;
use Throwable;

/** Selected account verification, without transaction locks, write paths or external side effects. */
final readonly class SqlAccountProjectionVerifier implements AccountProjectionVerifier
{
    public function __construct(private SqlRecords $sql, private HistoricalEventStore $history)
    {
    }

    public function verify(AccountId $account, int $eventLimit = 500): AccountProjectionCheck
    {
        if ($eventLimit < 1 || $eventLimit > 2000) {
            throw new InvalidArgumentException('Use an account verification allowance of 1–2000 events.');
        }
        $reference = new EntityId((string) $account);
        if ($this->sql->inTransaction()) {
            return new AccountProjectionCheck('unverified', $reference, 0, issues: ['unavailable']);
        }
        $head = $identity = null;
        $events = [];
        $postings = null;
        $position = null;
        $issues = [];
        $status = 'unverified';
        try {
            if ($this->sql->tableExists('event_store')) {
                throw new CorruptHistoryException();
            }
            $head = $this->head($reference);
            $identity = $this->identity($reference);
            if ($head === null) {
                $issues[] = 'journal';
            }
            if ($identity === null) {
                $issues[] = 'account_identity';
            }
            if ($issues === []) {
                if ((int) $head['version'] < 1) {
                    throw new CorruptHistoryException();
                }
                $position = (int) $head['version'];
                if ($position > $eventLimit) {
                    throw new HistoryReadLimitException();
                }
                $budget = new HistoryReadBudget(maximumEvents: $eventLimit);
                $stream = new StreamReference($account, 'student_account');
                $cursor = null;
                $bytes = 0;
                $begin = hrtime(true);
                do {
                    $page = $budget->page($this->history, $stream, $cursor);
                    if ($page->throughVersion !== $position) {
                        $status = 'changed';
                        break;
                    }
                    foreach ($page->events as $stored) {
                        $bytes += strlen(json_encode(
                            [$stored->event->payload(), $stored->event->metadata()],
                            JSON_THROW_ON_ERROR
                        ));
                        if ($bytes > 4_194_304 || hrtime(true) - $begin >= 5_000_000_000) {
                            throw new HistoryReadLimitException();
                        }
                        $events[] = $stored->event;
                    }
                    $cursor = $page->nextCursor;
                } while ($cursor !== null);
                if ($status !== 'changed') {
                    if (count($events) !== $position) {
                        throw new CorruptHistoryException();
                    }
                    $root = StudentAccount::reconstituteFromEventStream(new EventStream($account, $events));
                    if (
                        !$root instanceof StudentAccount || (string) $root->aggregateId() !== $reference->value ||
                        $root->playhead() !== $position
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $postings = $root->entryCount();
                    if (
                        $root->studentId()->value !== $identity['student_id'] ||
                        $root->termId()->value !== $identity['term_id'] ||
                        $root->balance()->currency !== $identity['currency']
                    ) {
                        $issues[] = 'account_identity';
                    }
                    try {
                        new SqlAccountBalanceProjection($this->sql)->verify($root);
                    } catch (CorruptHistoryException) {
                        $issues[] = 'balance';
                    }
                    try {
                        new SqlAccountEntryProjection($this->sql)->verify($root, $events);
                    } catch (CorruptHistoryException) {
                        $issues[] = 'postings';
                    }
                    if (hrtime(true) - $begin >= 5_000_000_000) {
                        throw new HistoryReadLimitException();
                    }
                    $status = $issues === [] ? 'verified' : 'unverified';
                }
            }
        } catch (HistoryReadLimitException) {
            $status = 'limited';
            $issues = [];
        } catch (CorruptHistoryException) {
            $issues = ['journal'];
        } catch (Throwable) {
            $issues = ['unavailable'];
        }
        // A legitimate commit may occur between reads. It must not be mislabeled as damaged projections.
        if ($head !== null && $identity !== null) {
            try {
                if (
                    $head !== $this->head($reference) ||
                    $identity !== $this->identity($reference)
                ) {
                    $status = 'changed';
                    $issues = [];
                }
            } catch (Throwable) {
                $status = 'unverified';
                $issues = ['unavailable'];
            }
        }
        return new AccountProjectionCheck($status, $reference, count($events), $postings, $issues, $position);
    }

    /** @return array<string,mixed>|null */
    private function head(EntityId $account): ?array
    {
        $q = $this->sql->quote(...);
        // Compare scalar head facts, not driver-specific LOB handles for the sealed anchor.
        return $this->sql->fetch('SELECT '
            . implode(', ', array_map($q, ['id', 'stream_type', 'aggregate_id', 'version', 'head_hash']))
            . ' FROM ' . $q('sis_history_streams') . ' WHERE ' . $q('stream_type') . ' = ? AND '
            . $q('aggregate_id') . ' = ?', ['student_account', $account->value], 1)[0] ?? null;
    }

    /** @return array<string,mixed>|null */
    private function identity(EntityId $account): ?array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch('SELECT ' . implode(', ', array_map($q, ['id', 'student_id', 'term_id', 'currency']))
            . ' FROM ' . $q('sis_student_accounts') . ' WHERE ' . $q('id') . ' = ?', [$account->value], 1)[0] ?? null;
    }
}
