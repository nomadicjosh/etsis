<?php

declare(strict_types=1);

namespace Infrastructure\EventSourcing;

use Domain\Finance\StudentAccount;
use Domain\Finance\BillingRule;
use Domain\Finance\ValueObject\BillingRuleId;
use Domain\Finance\ValueObject\AccountId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Infrastructure\Persistence\DatabaseUnitOfWork;
use Infrastructure\Persistence\SqlRecords;
use Infrastructure\Projection\SqlAccountBalanceProjection;
use Infrastructure\Projection\SqlBillingRuleProjection;
use Infrastructure\SystemClock;
use LogicException;
use Qubus\Expressive\Database;

/** Offline alpha migration: derive read models from genuine protected events, without business side effects. */
final readonly class FinancialProjectionUpgrade
{
    public function __construct(private Database $db, private ?ProtectedEventCodec $codec = null)
    {
    }

    public function run(string $type, bool $entries = false, bool $paymentMethods = false, bool $temporal = false): void
    {
        if (($paymentMethods || $temporal) && ($type !== 'student_account' || !$entries)) {
            throw new LogicException('Financial entry enrichment requires the verified account-entry upgrade.');
        }
        $sql = new SqlRecords($this->db);
        $table = match ($type) {
            'student_account' => 'sis_student_accounts',
            'billing_rule' => 'sis_billing_rules',
            default => throw new LogicException('An explicit supported financial projection is required.'),
        };
        $records = $sql->rows($table, [], 201);
        $streams = $sql->rows('sis_history_streams', ['stream_type' => $type], 201);
        if (count($records) > 200 || count($streams) > 200) {
            throw new LogicException(
                'Financial projection migration allows 200 records. Plan a bounded offline conversion.'
            );
        }
        if (count($records) !== count($streams)) {
            throw new CorruptHistoryException();
        }
        if ($records === []) {
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
        $uow->run(function () use (
            $sql,
            $history,
            $records,
            $table,
            $type,
            $entries,
            $paymentMethods,
            $temporal
        ): void {
            $budget = new HistoryReadBudget();
            foreach ($records as $row) {
                $sql->lock($table, $row['id'], 'id');
                $stream = new StreamReference(
                    $type === 'student_account' ? new AccountId($row['id']) : new BillingRuleId($row['id']),
                    $type
                );
                $events = [];
                $cursor = null;
                do {
                    $page = $budget->page($history, $stream, $cursor);
                    foreach ($page->events as $stored) {
                        $events[] = $stored->event;
                    }
                    $cursor = $page->nextCursor;
                } while ($cursor !== null);
                $recorded = new \Codefy\Domain\EventSourcing\EventStream($stream->aggregateId, $events);
                if ($type === 'billing_rule') {
                    $root = BillingRule::reconstituteFromEventStream($recorded);
                    if (!$root instanceof BillingRule) {
                        throw new CorruptHistoryException();
                    }
                    new SqlBillingRuleProjection($sql)->initialize($root);
                    continue;
                }
                $root = StudentAccount::reconstituteFromEventStream($recorded);
                if (!$root instanceof StudentAccount) {
                    throw new CorruptHistoryException();
                }
                if (
                    $root->playhead() < 1 || $root->studentId()->value !== $row['student_id']
                    || $root->termId()->value !== $row['term_id'] || $root->balance()->currency !== $row['currency']
                ) {
                    throw new CorruptHistoryException();
                }
                new SqlAccountBalanceProjection($sql)->initialize($root);
                if ($entries) {
                    new \Infrastructure\Projection\SqlAccountEntryProjection($sql)->initialize(
                        $root,
                        $events,
                        enrichPaymentMethods: $paymentMethods,
                        enrichTemporal: $temporal
                    );
                }
            }
        });
    }
}
