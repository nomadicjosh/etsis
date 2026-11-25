<?php

declare(strict_types=1);

namespace Infrastructure\Projection;

use Codefy\Domain\EventSourcing\EventStream;
use Domain\Communications\Service\MessageCipher;
use Domain\Finance\Repository\AccountProjectionRecovery;
use Domain\Finance\Repository\AccountProjectionStageStorage;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\AccountProjectionStage;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Operations\MaintenanceMode;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Database;

/** Explicit deployment recovery. No business writers, event append, messaging or external provider is invoked. */
final readonly class SqlAccountProjectionRecovery implements AccountProjectionRecovery
{
    private SqlRecords $sql;

    public function __construct(
        private Database $db,
        private HistoricalEventStore $history,
        private UnitOfWork $transactions,
        private AccountProjectionStageStorage $stages,
        private MessageCipher $cipher,
        private Clock $clock,
        private MaintenanceMode $maintenance,
    ) {
        $this->sql = new SqlRecords($db);
    }

    public function stage(AccountId $account, int $eventLimit = 500): array
    {
        $deadline = hrtime(true) + 5_000_000_000;
        $this->outside();
        [$root, $events, $head, $identity, $before, $after, $stamp] = $this->build($account, $eventLimit, $deadline);
        $stage = new AccountProjectionStage([
            'purpose' => 'finance.account_projection_stage', 'id' => EntityId::generate()->value,
            'account_id' => (string) $account, 'source' => $head, 'identity' => $identity,
            'storage_stamp' => $stamp, 'scope' => $this->scope(), 'created_at' => $this->at(),
            'events_checked' => count($events),
            'postings_checked' => $root->entryCount(), 'before' => $before, 'after' => $after,
        ]);
        $this->stages->write($stage);
        return $stage->describe('staged') + ['requires_repair' => self::digest($before) !== self::digest($after)];
    }

    public function check(EntityId $id, int $eventLimit = 500): array
    {
        $deadline = hrtime(true) + 5_000_000_000;
        $this->outside();
        $stage = $this->stages->read($id);
        $this->fresh($stage);
        $this->compare($stage, $this->build(new AccountId($stage->data['account_id']), $eventLimit, $deadline));
        return $stage->describe('ready') + ['requires_repair' =>
            self::digest($stage->data['before']) !== self::digest($stage->data['after'])];
    }

    public function apply(EntityId $id, int $eventLimit = 500): array
    {
        $deadline = hrtime(true) + 5_000_000_000;
        $this->outside();
        $this->limit($eventLimit);
        if (($receipt = $this->receipt($id)) !== null) {
            return $receipt;
        }
        $stage = $this->stages->read($id);
        $this->fresh($stage);
        $this->down();
        return $this->transactions->run(function () use ($stage, $id, $eventLimit, $deadline): array {
            $account = new AccountId($stage->data['account_id']);
            $this->sql->lock('sis_student_accounts', (string) $account, 'id');
            if (($receipt = $this->receipt($id)) !== null) {
                return $receipt;
            }
            $head = $this->head($account) ?? throw new CorruptHistoryException();
            $this->sql->lock('sis_history_streams', $head['id'], 'version');
            $this->fresh($stage);
            $this->down();
            $built = $this->build($account, $eventLimit, $deadline);
            $this->compare($stage, $built);
            [$root, $events, $head, $identity, $before, $after, $stamp] = $built;
            $changed = self::digest($before) !== self::digest($after);
            if ($changed) {
                foreach (['sis_account_balances', 'sis_account_entry_heads'] as $table) {
                    $row = $after[$table][0];
                    if ($before[$table] === []) {
                        $this->sql->insert($table, $row);
                    } else {
                        $this->sql->update($table, $row, ['id' => (string) $account]);
                    }
                }
                $q = $this->sql->quote(...);
                $this->sql->execute(
                    'DELETE FROM ' . $q('sis_account_entries') . ' WHERE ' . $q('account_id') . ' = ?',
                    [(string) $account]
                );
                foreach ($after['sis_account_entries'] as $row) {
                    if (hrtime(true) >= $deadline) {
                        throw new HistoryReadLimitException();
                    }
                    $this->sql->insert('sis_account_entries', $row);
                }
            }
            new SqlAccountBalanceProjection($this->sql)->verify($root);
            new SqlAccountEntryProjection($this->sql)->verify($root, $events);
            $result = $stage->describe('applied', $changed);
            $at = $this->at();
            $proof = ['purpose' => 'finance.account_projection_receipt', 'stage_id' => $id->value,
                'account_id' => (string) $account, 'scope' => $this->scope(), 'completed_at' => $at,
                'executor' => 'system:deployment_projection_recovery', 'stage_hash' => self::digest($stage->data),
                'before_hash' => self::digest($before), 'after_hash' => self::digest($after), 'result' => $result];
            $sealed = $this->cipher->seal(json_encode($proof, JSON_THROW_ON_ERROR));
            $this->sql->insert('sis_account_projection_repairs', ['id' => $id->value,
                'account_id' => (string) $account, 'completed_at' => $at, 'sealed_result' => $sealed]);
            $this->fresh($stage);
            $this->down();
            if (
                $stamp !== $this->sourceStamp($head, $deadline) || $head !== $this->head($account)
                || $identity !== $this->identity($account)
                || self::digest($this->inventory($account, $deadline)) !== self::digest($after)
            ) {
                throw new ConcurrentModificationException('Projection recovery source changed.');
            }
            new SqlAccountBalanceProjection($this->sql)->verify($root);
            new SqlAccountEntryProjection($this->sql)->verify($root, $events);
            $this->receipt($id) ?? throw new CorruptHistoryException();
            if (hrtime(true) >= $deadline) {
                throw new HistoryReadLimitException();
            }
            return $result;
        });
    }

    private function compare(AccountProjectionStage $stage, array $built): void
    {
        [$root, $events, $head, $identity, $before, $after, $stamp] = $built;
        if (
            $stage->data['storage_stamp'] !== $stamp || $stage->data['source'] !== $head
            || $stage->data['identity'] !== $identity
            || $stage->data['events_checked'] !== count($events)
            || $stage->data['postings_checked'] !== $root->entryCount()
            || self::digest($stage->data['before']) !== self::digest($before)
            || self::digest($stage->data['after']) !== self::digest($after)
        ) {
            throw new ConcurrentModificationException('Projection stage changed. Build a fresh stage.');
        }
    }

    private function build(AccountId $account, int $eventLimit, int $deadline): array
    {
        $this->limit($eventLimit);
        if ($this->sql->tableExists('event_store')) {
            throw new CorruptHistoryException();
        }
        $head = $this->head($account) ?? throw new CorruptHistoryException();
        $identity = $this->identity($account) ?? throw new CorruptHistoryException();
        if ($head['version'] < 1 || $head['version'] > $eventLimit) {
            throw new HistoryReadLimitException();
        }
        $stamp = $this->sourceStamp($head, $deadline);
        $before = $this->inventory($account, $deadline);
        $events = [];
        $cursor = null;
        $bytes = 0;
        $budget = new HistoryReadBudget(maximumEvents: $eventLimit);
        do {
            $page = $budget->page($this->history, new StreamReference($account, 'student_account'), $cursor);
            if ($page->throughVersion !== $head['version']) {
                throw new ConcurrentModificationException('Account history changed.');
            }
            foreach ($page->events as $stored) {
                $bytes += strlen(json_encode(
                    [$stored->event->payload(), $stored->event->metadata()],
                    JSON_THROW_ON_ERROR
                ));
                if ($bytes > 4_194_304 || hrtime(true) >= $deadline) {
                    throw new HistoryReadLimitException();
                }
                $events[] = $stored->event;
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if (count($events) !== $head['version']) {
            throw new CorruptHistoryException();
        }
        $root = StudentAccount::reconstituteFromEventStream(new EventStream($account, $events));
        if (
            !$root instanceof StudentAccount || (string) $root->aggregateId() !== (string) $account
            || $root->playhead() !== $head['version']
            || $root->studentId()->value !== $identity['student_id'] || $root->termId()->value !== $identity['term_id']
            || $root->balance()->currency !== $identity['currency']
        ) {
            throw new CorruptHistoryException();
        }
        $postings = new SqlAccountEntryProjection($this->sql)->snapshot($root, $events);
        $after = ['sis_account_balances' => [new SqlAccountBalanceProjection($this->sql)->snapshot($root)],
            'sis_account_entry_heads' => [$postings['head']], 'sis_account_entries' => $postings['entries']];
        foreach ($before as $table => $rows) {
            $keys = array_keys($after[$table][0] ?? array_fill_keys([
                'id', 'account_id', 'operation_id', 'type', 'amount_minor', 'effect_minor', 'currency', 'reversed',
                'recorded_at', 'posted_position', 'reversed_at', 'reversed_position', 'origin', 'payment_method',
                'charge_source', 'corrects_operation_id', 'corrected_by_operation_id', 'source_operation_id',
            ], null));
            foreach ($rows as $row) {
                if (array_diff(array_keys($row), $keys) !== [] || count($row) !== count($keys)) {
                    throw new CorruptHistoryException();
                }
            }
        }
        $after = self::normalize($after);
        if (
            $stamp !== $this->sourceStamp($head, $deadline) || $head !== $this->head($account)
                || $identity !== $this->identity($account)
            || self::digest($before) !== self::digest($this->inventory($account, $deadline))
        ) {
            throw new ConcurrentModificationException('Account sources changed during staging.');
        }
        if (
            strlen(json_encode($after, JSON_THROW_ON_ERROR)) > 4_194_304
            || hrtime(true) >= $deadline
        ) {
            throw new HistoryReadLimitException();
        }
        return [$root, $events, $head, $identity, $before, $after, $stamp];
    }

    /** Small keyset pages respect the portable SELECT ceiling and avoid retaining protected source ciphertext. */
    private function boundedRows(string $table, string $column, string $id, int $deadline): \Generator
    {
        $q = $this->sql->quote(...);
        $cursor = '';
        $count = $bytes = 0;
        do {
            $page = $this->sql->fetch('SELECT * FROM ' . $q($table) . ' WHERE ' . $q($column)
                . ' = ? AND ' . $q('id') . ' > ? ORDER BY ' . $q('id'), [$id, $cursor], 10);
            foreach ($page as $row) {
                foreach ($row as &$value) {
                    if (is_resource($value)) {
                        $value = stream_get_contents($value, 16_777_217);
                    }
                    if (!is_scalar($value) && $value !== null) {
                        throw new CorruptHistoryException();
                    }
                    $bytes += strlen((string) $value);
                }
                unset($value);
                if (++$count > 2000 || $bytes > 16_777_216 || hrtime(true) >= $deadline) {
                    throw new HistoryReadLimitException();
                }
                $cursor = $row['id'];
                yield $row;
            }
        } while (count($page) === 10);
    }

    private function inventory(AccountId $account, int $deadline): array
    {
        $rows = [];
        $bytes = 0;
        foreach ($this->boundedRows('sis_account_entries', 'account_id', (string) $account, $deadline) as $row) {
            $bytes += strlen(json_encode($row, JSON_THROW_ON_ERROR));
            if ($bytes > 4_194_304) {
                throw new HistoryReadLimitException();
            }
            $rows[] = $row;
        }
        $result = ['sis_account_balances' => $this->sql->rows('sis_account_balances', ['id' => (string) $account], 1),
            'sis_account_entry_heads' => $this->sql->rows('sis_account_entry_heads', ['id' => (string) $account], 1),
            'sis_account_entries' => $rows];
        if (count($rows) > 2000 || strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 4_194_304) {
            throw new HistoryReadLimitException();
        }
        return self::normalize($result);
    }

    private static function normalize(array $tables): array
    {
        foreach ($tables as &$rows) {
            foreach ($rows as &$row) {
                foreach ($row as &$value) {
                    if ($value !== null && !is_scalar($value)) {
                        throw new CorruptHistoryException();
                    }
                    $value = $value === null ? null : (string) (is_bool($value) ? (int) $value : $value);
                }
                unset($value);
                ksort($row, SORT_STRING);
            }
            unset($row);
            usort($rows, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
        }
        unset($rows);
        ksort($tables, SORT_STRING);
        return $tables;
    }

    private static function digest(array $data): string
    {
        return hash('sha256', ProtectedEventCodec::canonical($data));
    }

    private function head(AccountId $account): ?array
    {
        $q = $this->sql->quote(...);
        $row = $this->sql->fetch(
            'SELECT ' . implode(', ', array_map(
                $q,
                ['id', 'stream_type', 'aggregate_id', 'version', 'head_hash']
            )) . ' FROM ' . $q('sis_history_streams')
            . ' WHERE ' . $q('stream_type') . ' = ? AND ' . $q('aggregate_id') . ' = ?',
            ['student_account', (string) $account],
            1
        )[0] ?? null;
        if ($row !== null) {
            $row['version'] = (int) $row['version'];
        }
        return $row;
    }

    /** Compare original protected storage bytes as well as authenticated replay, including the final write boundary. */
    private function sourceStamp(array $head, int $deadline): string
    {
        $hash = hash_init('sha256');
        $headRow = $this->sql->rows('sis_history_streams', ['id' => $head['id']], 1);
        hash_update($hash, self::digest(self::normalize(['sis_history_streams' => $headRow])));
        $bytes = 0;
        foreach (['sis_history_events', 'sis_history_operations'] as $table) {
            hash_update($hash, $table . "\n");
            foreach ($this->boundedRows($table, 'stream_id', $head['id'], $deadline) as $row) {
                $data = self::normalize([$table => [$row]]);
                $canonical = ProtectedEventCodec::canonical($data);
                $bytes += strlen($canonical);
                if ($bytes > 16_777_216) {
                    throw new HistoryReadLimitException();
                }
                hash_update($hash, $canonical . "\n");
            }
        }
        return hash_final($hash);
    }

    private function identity(AccountId $account): ?array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch('SELECT ' . implode(', ', array_map($q, ['id', 'student_id', 'term_id', 'currency']))
            . ' FROM ' . $q('sis_student_accounts') . ' WHERE ' . $q('id') . ' = ?', [(string) $account], 1)[0] ?? null;
    }

    private function receipt(EntityId $id): ?array
    {
        $row = $this->sql->rows('sis_account_projection_repairs', ['id' => $id->value], 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        if (!is_string($row['sealed_result']) || strlen($row['sealed_result']) > 32768) {
            throw new CorruptHistoryException();
        }
        $data = json_decode($this->cipher->open($row['sealed_result']), true, 16, JSON_THROW_ON_ERROR);
        if (
            !is_array($data) || count($data) !== 10
            || ($data['purpose'] ?? null) !== 'finance.account_projection_receipt'
            || ($data['stage_id'] ?? null) !== $id->value || ($data['account_id'] ?? null) !== $row['account_id']
            || ($data['completed_at'] ?? null) !== $row['completed_at'] || ($data['scope'] ?? null) !== $this->scope()
            || ($data['executor'] ?? null) !== 'system:deployment_projection_recovery'
            || !is_array($data['result'] ?? null)
        ) {
            throw new CorruptHistoryException();
        }
        foreach (['stage_hash', 'before_hash', 'after_hash'] as $key) {
            if (!is_string($data[$key] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $data[$key]) !== 1) {
                throw new CorruptHistoryException();
            }
        }
        $result = $data['result'];
        if (
            array_keys($result) !== ['status', 'stage_reference', 'events_checked', 'postings_checked',
            'source_position', 'projections_changed'] || $result['status'] !== 'applied'
            || $result['stage_reference'] !== $id->value || !is_int($result['events_checked'])
            || $result['events_checked'] < 1 || $result['events_checked'] > 2000
            || !is_int($result['postings_checked']) || $result['postings_checked'] < 0
            || $result['postings_checked'] > 2000 || $result['source_position'] !== $result['events_checked']
            || !is_bool($result['projections_changed'])
        ) {
            throw new CorruptHistoryException();
        }
        $result['status'] = 'already_applied';
        $result['projections_changed'] = false;
        return $result;
    }

    private function fresh(AccountProjectionStage $stage): void
    {
        $time = new \DateTimeImmutable($stage->data['created_at']);
        $now = $this->clock->now()->getTimestamp();
        if (
            $stage->data['scope'] !== $this->scope() || $now < $time->getTimestamp()
            || $now >= $time->getTimestamp() + 1200
        ) {
            throw new ConcurrentModificationException('Projection stage expired or belongs to another connection.');
        }
    }

    private function scope(): string
    {
        $dsn = $this->db->getConnection()->getDsn();
        if (!is_string($dsn) || $dsn === '') {
            throw new CorruptHistoryException();
        }
        return hash('sha256', $dsn);
    }

    private function at(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function down(): void
    {
        if ($this->maintenance->status()->status !== 'down') {
            throw new \RuntimeException('Projection promotion requires available enabled maintenance.');
        }
    }

    private function outside(): void
    {
        if ($this->sql->inTransaction()) {
            throw new \LogicException('Projection recovery must own its transaction boundary.');
        }
    }

    private function limit(int $events): void
    {
        if ($events < 1 || $events > 2000) {
            throw new \InvalidArgumentException('Use a replay allowance of 1–2000 events.');
        }
    }
}
