<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Codefy\Domain\EventSourcing\TransactionalEventStore;
use Domain\Finance\BillingRule;
use Domain\Finance\Event\BillingRuleWasDefined;
use Domain\Finance\Repository\BillingRuleRepository;
use Domain\Finance\ValueObject\BillingRuleId;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use Infrastructure\EventSourcing\FinancialEventWriter;

final readonly class EventSourcedBillingRuleRepository implements BillingRuleRepository
{
    public function __construct(
        private SqlRecords $sql,
        private TransactionalEventStore $events,
        private UnitOfWork $transactions,
        private FinancialEventWriter $writer,
    ) {
    }

    public function save(BillingRule $rule, #[\SensitiveParameter] EventContext $context): void
    {
        $events = iterator_to_array($rule->getRecordedEvents());
        if ($events === []) {
            return;
        }
        $this->transactions->run(function () use ($rule, $events, $context): void {
            if ($events[0] instanceof BillingRuleWasDefined) {
                $this->sql->insert('sis_billing_rules', ['id' => (string) $rule->aggregateId(),
                    'term_id' => $rule->termId()->value, 'program_id' => $rule->programId()?->value]);
            }
            $this->writer->append($rule, $context);
            new \Infrastructure\Projection\SqlBillingRuleProjection($this->sql)->advance(
                $rule,
                $events[0]->playhead() - 1
            );
        });
        $rule->clearRecordedEvents();
    }

    public function get(BillingRuleId $id, ?HistoryReadBudget $budget = null): BillingRule
    {
        $history = $budget === null ? $this->events->getAggregateHistoryFor($id)
        : $this->writer->readForWrite($id, $budget);
        if (count($history) === 0) {
            throw new RecordNotFoundException('Billing rule not found.');
        }
        $rule = BillingRule::reconstituteFromEventStream($history);
        if (!$rule instanceof BillingRule) {
            throw new \LogicException('Invalid billing rule aggregate.');
        }
        new \Infrastructure\Projection\SqlBillingRuleProjection($this->sql)->verify($rule);

        return $rule;
    }

    public function forTerm(EntityId $term, ?HistoryReadBudget $budget = null, ?array $programs = null): array
    {
        new \Infrastructure\Projection\SqlBillingRuleProjection($this->sql)->assertCurrent();
        $result = [];
        $q = $this->sql->quote(...);
        $scope = '';
        $parameters = [$term->value];
        if ($programs !== null) {
            if (count($programs) > 25) {
                throw new \InvalidArgumentException('Use at most 25 program scopes per billing read.');
            }
            $scope = ' AND (' . $q('program_id') . ' IS NULL';
            if ($programs !== []) {
                $placeholders = implode(',', array_fill(0, count($programs), '?'));
                $scope .= ' OR ' . $q('program_id') . ' IN (' . $placeholders . ')';
                foreach ($programs as $program) {
                    $parameters[] = $program->value;
                }
            }
            $scope .= ')';
        }
        // Selected verification can lock metadata; every multi-rule decision uses the same identity order.
        $rows = $this->sql->fetch(
            'SELECT ' . $q('id') . ' FROM ' . $q('sis_billing_rules')
            . ' WHERE ' . $q('term_id') . ' = ?' . $scope . ' ORDER BY ' . $q('id'),
            $parameters,
            501
        );
        if (count($rows) > 500) {
            throw new \Domain\Shared\Exception\ValidationException([['field' => 'term_id',
                'message' => 'This term exceeds the fee-definition limit. Ask finance administration to review it.']]);
        }
        foreach ($rows as $row) {
            $rule = $this->get(new BillingRuleId($row['id']), $budget);
            if (!$rule->isRetired()) {
                $result[] = $rule;
            }
        }

        return $result;
    }
}
