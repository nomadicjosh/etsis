<?php

declare(strict_types=1);

namespace Domain\ServiceFee\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Finance\Journal;
use Domain\Finance\Repository\JournalRepository;
use Domain\Finance\Service\LedgerControl;
use Domain\Finance\ValueObject\JournalId;
use Domain\ServiceFee\ServiceFee;
use Domain\ServiceFee\ValueObject\FeeAccounting;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\Service\Clock;

/** Caller owns the person/finance locks and the shared financial transaction. */
final readonly class ServiceFeePosting
{
    public function __construct(
        private LedgerControl $control,
        private JournalRepository $journals,
        private Clock $clock,
    ) {
    }

    public function details(ServiceFee $root, string $action, array $d): array
    {
        if ($action === 'assess') {
            $existing = $root->state()['assessment'];
            if ($existing !== null) {
                if (array_key_exists('accounting', $existing)) {
                    $d['accounting'] = $existing['accounting'];
                }
                return $d;
            }
            $book = $this->control->book($d['policy']['currency']);
            $map = $book['definition']['service_fees'] ?? null;
            $d['accounting'] = $map === null ? null : [
                'configuration_event_id' => $book['event_id'], 'definition' => $map,
            ];
        } elseif ($action === 'payment' && ($root->state()['assessment']['accounting'] ?? null) !== null) {
            $s = $root->state();
            $asset = $d['channel'] === 'provider'
            ? ($root->captures()[$d['payment_id']]['plan']['receipt']['asset'] ?? null)
            : $s['assessment']['accounting']['definition']['cash'];
            if ($asset === null) {
                throw new \DomainException('A retained provider clearing asset is required.');
            }
            $d['asset'] = array_intersect_key($asset, array_flip(['id', 'code', 'title']));
        }
        return $d;
    }

    public function advance(array $before, ServiceFee $root, StoredEvent $stored, EventContext $context): void
    {
        $lines = FeeAccounting::lines($before, $root->state(), $stored->event->payload());
        if ($lines === []) {
            return;
        }
        $currency = $root->state()['assessment']['policy']['currency'];
        $book = $this->control->book($currency) ?? throw new \DomainException('Publish the accounting mapping.');
        $date = $this->clock->now()->setTimezone(new DateTimeZone($book['definition']['timezone']))->format('Y-m-d');
        $source = ['kind' => 'service_fee', 'fee_id' => (string) $root->aggregateId(),
            'event_id' => (string) $stored->event->eventId()];
        $journal = Journal::post(
            new JournalId(),
            'SF.' . $stored->event->eventId(),
            new DateTimeImmutable($date),
            $lines,
            $context->actor,
            $this->control->accounting($currency, $date, $source)
        );
        $this->journals->save($journal, $context);
    }
}
