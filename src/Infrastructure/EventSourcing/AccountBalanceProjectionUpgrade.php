<?php

declare(strict_types=1);

namespace Infrastructure\EventSourcing;

use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Infrastructure\Persistence\DatabaseUnitOfWork;
use Infrastructure\Persistence\SqlRecords;
use Infrastructure\Projection\SqlAccountBalanceProjection;
use Infrastructure\SystemClock;
use LogicException;
use Qubus\Expressive\Database;

/** Offline alpha migration: derive balances from genuine protected events, without business side effects. */
final readonly class AccountBalanceProjectionUpgrade
{
    public function __construct(private Database $db, private ?ProtectedEventCodec $codec = null)
    {
    }

    public function run(): void
    {
        $sql = new SqlRecords($this->db);
        $accounts = $sql->rows('sis_student_accounts', [], 201);
        $streams = $sql->rows('sis_history_streams', ['stream_type' => 'student_account'], 201);
        if (count($accounts) > 200 || count($streams) > 200) {
            throw new LogicException(
                'Account reporting migration allows 200 accounts. Plan a bounded offline conversion.'
            );
        }
        if (count($accounts) !== count($streams)) {
            throw new CorruptHistoryException();
        }
        if ($accounts === []) {
            return;
        }
        if ($this->codec === null) {
            throw new LogicException('Configure the history codec before reconstructing financial projections.');
        }
        $uow = new DatabaseUnitOfWork($this->db);
        $history = new SqlHistoricalEventStore(
            $sql,
            $uow,
            new SystemClock(),
            $this->codec,
            new HistoricalSchemaRegistry()
        );
        $uow->run(function () use ($sql, $history, $accounts): void {
            $budget = new HistoryReadBudget();
            $projection = new SqlAccountBalanceProjection($sql);
            foreach ($accounts as $row) {
                $sql->lock('sis_student_accounts', $row['id'], 'id');
                $stream = new StreamReference(new AccountId($row['id']), 'student_account');
                $events = [];
                $cursor = null;
                do {
                    $page = $budget->page($history, $stream, $cursor);
                    foreach ($page->events as $stored) {
                        $events[] = $stored->event;
                    }
                    $cursor = $page->nextCursor;
                } while ($cursor !== null);
                $root = StudentAccount::reconstituteFromEventStream(
                    new \Codefy\Domain\EventSourcing\EventStream($stream->aggregateId, $events)
                );
                if (!$root instanceof StudentAccount) {
                    throw new CorruptHistoryException();
                }
                if (
                    $root->playhead() < 1 || $root->studentId()->value !== $row['student_id']
                    || $root->termId()->value !== $row['term_id'] || $root->balance()->currency !== $row['currency']
                ) {
                    throw new CorruptHistoryException();
                }
                $projection->initialize($root);
            }
        });
    }
}
