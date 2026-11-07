<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Codefy\Domain\EventSourcing\TransactionalEventStore;
use Domain\Finance\BillingRule;
use Domain\Finance\Event\BillingRuleWasDefined;
use Domain\Finance\Repository\BillingRuleRepository;
use Domain\Finance\ValueObject\BillingRuleId;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class EventSourcedBillingRuleRepository implements BillingRuleRepository
{
    public function __construct(
        private SqlRecords $sql,
        private TransactionalEventStore $events,
        private UnitOfWork $transactions
    ) {
    }

    public function save(BillingRule $rule): void
    {
        $events = iterator_to_array($rule->getRecordedEvents());
        if ($events === []) {
            return;
        }
        $this->transactions->run(function () use ($rule, $events): void {
            if ($events[0] instanceof BillingRuleWasDefined) {
                $this->sql->insert('sis_billing_rules', ['id' => (string) $rule->aggregateId(),
                    'term_id' => $rule->termId()->value, 'program_id' => $rule->programId()?->value]);
            }
            $this->events->commit(...$events);
        });
        $rule->clearRecordedEvents();
    }

    public function get(BillingRuleId $id): BillingRule
    {
        $history = $this->events->getAggregateHistoryFor($id);
        if (count($history) === 0) {
            throw new RecordNotFoundException('Billing rule not found.');
        }
        $rule = BillingRule::reconstituteFromEventStream($history);
        if (!$rule instanceof BillingRule) {
            throw new \LogicException('Invalid billing rule aggregate.');
        }

        return $rule;
    }

    public function forTerm(EntityId $term): array
    {
        $result = [];
        foreach ($this->sql->rows('sis_billing_rules', ['term_id' => $term->value]) as $row) {
            $rule = $this->get(new BillingRuleId($row['id']));
            if (!$rule->isRetired()) {
                $result[] = $rule;
            }
        }

        return $result;
    }
}
