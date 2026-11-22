<?php

declare(strict_types=1);

namespace Infrastructure\Projection;

use Brick\Math\BigInteger;
use Codefy\Domain\EventSourcing\DomainEvent;
use DateTimeImmutable;
use DateTimeZone;
use Domain\Finance\Event\AccountChargeWasCredited;
use Domain\Finance\Event\AccountEntryWasCorrected;
use Domain\Finance\Event\AccountEntryWasPosted;
use Domain\Finance\Event\AccountEntryWasReversed;
use Domain\Finance\Event\AccountPaymentDetailsWereChanged;
use Domain\Finance\Event\StudentAccountWasOpened;
use Domain\Finance\StudentAccount;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use Tuupola\Base32;

/** Minimized posting read model. The account journal remains authoritative; caller owns the transaction. */
final readonly class SqlAccountEntryProjection
{
    public function __construct(private SqlRecords $sql)
    {
    }

    /** @param non-empty-list<DomainEvent> $events */
    public function advance(#[\SensitiveParameter] StudentAccount $account, #[\SensitiveParameter] array $events): void
    {
        $id = (string) $account->aggregateId();
        $expected = $events[0]->playhead() - 1;
        $head = $this->sql->current('sis_account_entry_heads', $id);
        if (
            ($expected === 0 && $head !== null) ||
            ($expected > 0 && ($head === null || (int) $head['position'] !== $expected
                || (int) $head['temporal_position'] !== $expected || $head['opened_at'] === null))
        ) {
            throw new CorruptHistoryException();
        }
        [$created, $affected, $previousMethods, $reversals] = $this->changes($account, $events);
        foreach ($affected as $operation => $moneyChanged) {
            $entry = $account->entry(new EntityId($operation)) ?? throw new CorruptHistoryException();
            $old = $this->sql->rows(
                'sis_account_entries',
                ['account_id' => $id, 'operation_id' => $operation],
                1,
            )[0] ?? null;
            $values = $this->values($account, $entry);
            if (isset($created[$operation])) {
                if ($old !== null) {
                    throw new CorruptHistoryException();
                }
                $this->sql->insert('sis_account_entries', $values + $created[$operation]);
                continue;
            }
            if (
                $old === null || $old['payment_method'] !== (array_key_exists($operation, $previousMethods) ?
                $previousMethods[$operation] : $values['payment_method'])
            ) {
                throw new CorruptHistoryException();
            }
            if (!$moneyChanged) {
                $this->compareValues($values, $old, ignoreMissingMethod: false, methodOnlyChange: true);
                $this->sql->update(
                    'sis_account_entries',
                    ['payment_method' => $values['payment_method']],
                    ['id' => $old['id']]
                );
                continue;
            }
            if (
                $old === null || $old['type'] !== $entry['type'] ||
                (int) $old['amount_minor'] !== $entry['minor'] || $old['currency'] !== $entry['currency'] ||
                (bool) $old['reversed'] || $old['corrected_by_operation_id'] !== null ||
                $old['corrects_operation_id'] !== $values['corrects_operation_id'] ||
                $old['source_operation_id'] !== $values['source_operation_id'] ||
                $old['charge_source'] !== $values['charge_source']
            ) {
                throw new CorruptHistoryException();
            }
            $this->sql->update('sis_account_entries', $values + ($reversals[$operation] ?? []), ['id' => $old['id']]);
        }
        $count = ($head === null ? 0 : (int) $head['entry_count']) + count($created);
        if ($count !== $account->entryCount()) {
            throw new CorruptHistoryException();
        }
        $values = ['position' => $account->playhead(), 'entry_count' => $count,
            'temporal_position' => $account->playhead(), 'opened_at' => $head['opened_at'] ?? $this->openedAt($events)];
        if ($head === null) {
            $this->sql->insert('sis_account_entry_heads', ['id' => $id] + $values);
        } elseif (
            $this->sql->update(
                'sis_account_entry_heads',
                $values,
                ['id' => $id, 'position' => $expected],
            ) !== 1
        ) {
            throw new CorruptHistoryException();
        }
    }

    /** Explicit verified migration only; never called while executing a report.
     * @param non-empty-list<DomainEvent> $events */
    public function initialize(
        #[\SensitiveParameter] StudentAccount $account,
        #[\SensitiveParameter] array $events,
        bool $enrichPaymentMethods = false,
        bool $enrichTemporal = false,
    ): void {
        $id = (string) $account->aggregateId();
        [$created] = $this->changes($account, $events);
        $entries = $account->entries();
        $rows = $this->rows($id);
        $head = $this->sql->current('sis_account_entry_heads', $id);
        if (count($entries) > 2000 || count($created) !== count($entries) || count($rows) > 2000) {
            throw new CorruptHistoryException();
        }
        if ($head === null) {
            if ($rows !== []) {
                throw new CorruptHistoryException();
            }
            foreach ($entries as $entry) {
                $opening = $created[$entry['operation_id']] ?? throw new CorruptHistoryException();
                $this->sql->insert('sis_account_entries', $this->values($account, $entry) + $opening);
            }
            $this->sql->insert('sis_account_entry_heads', ['id' => $id, 'position' => $account->playhead(),
                'entry_count' => count($entries), 'opened_at' => $this->openedAt($events),
                'temporal_position' => $account->playhead()]);
            return;
        }
        $this->compare($account, $created, $entries, $head, $rows, $enrichPaymentMethods, $enrichTemporal);
        $this->compareTemporalHead($head, $events, $account->playhead(), $enrichTemporal);
        if ($enrichTemporal) {
            foreach ($created as $operation => $facts) {
                $this->sql->update('sis_account_entries', [
                    'reversed_at' => $facts['reversed_at'], 'reversed_position' => $facts['reversed_position'],
                ], ['account_id' => $id, 'operation_id' => $operation]);
            }
            $this->sql->update('sis_account_entry_heads', [
                'opened_at' => $this->openedAt($events), 'temporal_position' => $account->playhead(),
            ], ['id' => $id, 'position' => $account->playhead()]);
        }
        if ($enrichPaymentMethods) {
            foreach ($entries as $entry) {
                $values = $this->values($account, $entry);
                $this->sql->update(
                    'sis_account_entries',
                    ['payment_method' => $values['payment_method']],
                    ['account_id' => $id, 'operation_id' => $entry['operation_id']]
                );
            }
            $this->compare($account, $created, $entries, $head, $this->rows($id));
        }
        if ($enrichTemporal) {
            $this->verify($account, $events);
        }
    }

    /** Read-only verification shares the migration's deterministic state builder, never its write path.
     * @param non-empty-list<DomainEvent> $events */
    public function verify(
        #[\SensitiveParameter] StudentAccount $account,
        #[\SensitiveParameter] array $events,
    ): void {
        $id = (string) $account->aggregateId();
        [$created] = $this->changes($account, $events);
        $entries = $account->entries();
        $head = $this->sql->current('sis_account_entry_heads', $id);
        $rows = $this->rows($id);
        if ($head === null || count($entries) > 2000 || count($created) !== count($entries)) {
            throw new CorruptHistoryException();
        }
        $this->compare($account, $created, $entries, $head, $rows);
        $this->compareTemporalHead($head, $events, $account->playhead());
    }

    /** @param array<string,array<string,mixed>> $created
     * @param list<array<string,mixed>> $entries
     * @param array<string,mixed> $head
     * @param list<array<string,mixed>> $rows */
    private function compare(
        #[\SensitiveParameter] StudentAccount $account,
        #[\SensitiveParameter] array $created,
        #[\SensitiveParameter] array $entries,
        array $head,
        #[\SensitiveParameter] array $rows,
        bool $enrichPaymentMethods = false,
        bool $enrichTemporal = false,
    ): void {
        if (
            (string) ($head['position'] ?? null) !== (string) $account->playhead() ||
            (string) ($head['entry_count'] ?? null) !== (string) count($entries) || count($rows) !== count($entries)
        ) {
            throw new CorruptHistoryException();
        }
        $rows = array_column($rows, null, 'operation_id');
        foreach ($entries as $entry) {
            $expected = $this->values($account, $entry) + $created[$entry['operation_id']];
            $old = $rows[$entry['operation_id']] ?? throw new CorruptHistoryException();
            $this->compareValues($expected, $old, $enrichPaymentMethods, ignoreMissingTemporal: $enrichTemporal);
        }
    }

    private function compareValues(
        #[\SensitiveParameter] array $expected,
        #[\SensitiveParameter] array $old,
        bool $ignoreMissingMethod = false,
        bool $methodOnlyChange = false,
        bool $ignoreMissingTemporal = false,
    ): void {
        foreach ($expected as $field => $value) {
            if (!array_key_exists($field, $old)) {
                throw new CorruptHistoryException();
            }
            if (
                $ignoreMissingTemporal && in_array($field, ['reversed_at', 'reversed_position'], true)
                && $old[$field] === null
            ) {
                continue;
            }
            if ($field === 'payment_method' && ($methodOnlyChange || ($ignoreMissingMethod && $old[$field] === null))) {
                continue;
            }
            if (is_int($value)) {
                $actual = is_bool($old[$field]) ? (int) $old[$field] : $old[$field];
                if ((string) $actual !== (string) $value) {
                    throw new CorruptHistoryException();
                }
                continue;
            }
            if ($old[$field] !== $value) {
                throw new CorruptHistoryException();
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $id): array
    {
        $rows = [];
        $q = $this->sql->quote(...);
        do {
            $page = $this->sql->fetchPage(
                'SELECT * FROM ' . $q('sis_account_entries')
                . ' WHERE ' . $q('account_id') . ' = ? ORDER BY ' . $q('id'),
                [$id],
                min(1000, 2001 - count($rows)),
                count($rows),
            );
            array_push($rows, ...$page);
        } while (count($page) === 1000 && count($rows) < 2001);
        return $rows;
    }

    /** @param list<DomainEvent> $events
     * @return array{array<string,array<string,mixed>>,array<string,bool>,array<string,string|null>,array<string,array<string,mixed>>} */
    private function changes(
        #[\SensitiveParameter] StudentAccount $account,
        #[\SensitiveParameter] array $events,
    ): array {
        if ($events === [] || count($events) > 2000) {
            throw new CorruptHistoryException();
        }
        $created = [];
        $affected = [];
        $previousMethods = [];
        $reversals = [];
        foreach ($events as $event) {
            if ((string) $event->aggregateId() !== (string) $account->aggregateId()) {
                throw new CorruptHistoryException();
            }
            if ($event instanceof StudentAccountWasOpened) {
                continue;
            }
            $p = $event->payload();
            if ($event instanceof AccountPaymentDetailsWereChanged) {
                foreach ($account->receiptOperations(new EntityId($p['entry_operation_id'])) as $operation) {
                    $affected[$operation] ??= false;
                    if (!array_key_exists($operation, $previousMethods)) {
                        $previousMethods[$operation] = $p['previous_details']['method'] ?? null;
                    }
                }
                continue;
            }
            $operation = new EntityId($p['operation_id']);
            $affected[$operation->value] = true;
            if ($event instanceof AccountEntryWasReversed) {
                $reversals[$operation->value] = $this->reversal($event);
                foreach ($event->metadata()['reversed_credit_ids'] ?? [] as $credit) {
                    $affected[new EntityId($credit)->value] = true;
                    $reversals[new EntityId($credit)->value] = $this->reversal($event);
                }
                continue;
            }
            if (
                !($event instanceof AccountEntryWasPosted) && !($event instanceof AccountChargeWasCredited) &&
                !($event instanceof AccountEntryWasCorrected)
            ) {
                throw new CorruptHistoryException();
            }
            if (isset($created[$operation->value])) {
                throw new CorruptHistoryException();
            }
            $created[$operation->value] = [
                'id' => $this->rowId((string) $account->aggregateId(), $operation->value, $event->recordedAt()),
                'recorded_at' => DateTimeImmutable::createFromInterface($event->recordedAt())
                    ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'posted_position' => $event->playhead(),
                'reversed_at' => null, 'reversed_position' => null,
                'origin' => match (true) {
                    $event instanceof AccountEntryWasCorrected => 'staff_correction',
                    $event instanceof AccountChargeWasCredited => 'charge_credit',
                    ($event->metadata()['staff_recorded'] ?? null) === true => 'staff',
                    isset($event->metadata()['registration_price']) => 'registration_rate',
                    default => 'unclassified',
                },
            ];
            if ($event instanceof AccountEntryWasCorrected) {
                $affected[new EntityId($p['source_operation_id'])->value] = true;
                $reversals[new EntityId($p['source_operation_id'])->value] = $this->reversal($event);
            }
        }
        foreach ($created as $operation => &$facts) {
            if (isset($reversals[$operation])) {
                $facts = array_replace($facts, $reversals[$operation]);
            }
        }
        unset($facts);
        return [$created, $affected, $previousMethods, $reversals];
    }

    /** @param list<DomainEvent> $events */
    private function openedAt(array $events): string
    {
        $first = $events[0] ?? null;
        if (!$first instanceof StudentAccountWasOpened || $first->playhead() !== 1) {
            throw new CorruptHistoryException();
        }
        return $this->utc($first);
    }
    /** @return array{reversed_at:string,reversed_position:int} */
    private function reversal(DomainEvent $event): array
    {
        return ['reversed_at' => $this->utc($event), 'reversed_position' => $event->playhead()];
    }
    private function utc(DomainEvent $event): string
    {
        return DateTimeImmutable::createFromInterface($event->recordedAt())
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    /** @param array<string,mixed> $head
     * @param list<DomainEvent> $events */
    private function compareTemporalHead(array $head, array $events, int $version, bool $enrich = false): void
    {
        if (
            ($head['opened_at'] !== $this->openedAt($events) && !($enrich && $head['opened_at'] === null))
            || ((string) $head['temporal_position'] !== (string) $version
                && !($enrich && $head['temporal_position'] === null))
        ) {
            throw new CorruptHistoryException();
        }
    }

    /** Stable ULID timestamp/entropy, using the same installed Crockford encoder as EntityId generation. */
    private function rowId(string $account, string $operation, \DateTimeInterface $recorded): string
    {
        $milliseconds = BigInteger::of($recorded->format('Uv'));
        if ($milliseconds->isNegative() || $milliseconds->isGreaterThan('281474976710655')) {
            throw new CorruptHistoryException();
        }
        $time = str_pad($milliseconds->toBytes(signed: false), 6, "\0", STR_PAD_LEFT);
        $entropy = substr(hash('sha256', "sis.account-posting\0" . $account . "\0" . $operation, true), 0, 10);
        $encoder = new Base32(['characters' => Base32::CROCKFORD, 'padding' => false, 'crockford' => true]);
        return new EntityId(substr($encoder->encode(str_repeat("\0", 4) . $time . $entropy), 6))->value;
    }

    /** @param array<string,mixed> $entry
     * @return array<string,mixed> */
    private function values(#[\SensitiveParameter] StudentAccount $account, #[\SensitiveParameter] array $entry): array
    {
        return ['account_id' => (string) $account->aggregateId(), 'operation_id' => $entry['operation_id'],
            'type' => $entry['type'],
            'charge_source' => $entry['charge_source'] ?? null,
            'payment_method' => $account->paymentDetails(new EntityId($entry['operation_id']))?->method?->value,
            'amount_minor' => $entry['minor'], 'currency' => $entry['currency'], 'reversed' => (int) $entry['reversed'],
            'effect_minor' => \Domain\Finance\Enum\EntryType::from($entry['type'])->balanceSign() * $entry['minor'],
            'corrects_operation_id' => $entry['corrects_operation_id'] ?? null,
            'corrected_by_operation_id' => $entry['corrected_by_operation_id'] ?? null,
            'source_operation_id' => $entry['source_operation_id'] ?? null];
    }
}
