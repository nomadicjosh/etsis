<?php

declare(strict_types=1);

namespace Domain\Finance;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\Metadata;
use Domain\Finance\Event\BillingRuleWasDefined;
use Domain\Finance\Event\BillingRuleWasRetired;
use Domain\Finance\ValueObject\BillingRuleId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\ValueObject\EntityId;
use DomainException;

final class BillingRule extends EventSourcedAggregate
{
    private EntityId $termId;
    private ?EntityId $programId;
    private string $name;
    private string $kind;
    private Money $amount;
    private bool $retired = false;

    public static function define(
        BillingRuleId $id,
        EntityId $term,
        ?EntityId $program,
        string $name,
        string $kind,
        Money $amount,
        EntityId $actor
    ): self {
        if (
            trim($name) === '' || mb_strlen($name) > 191 || !in_array($kind, ['fee', 'tuition'], true)
            || $amount->minor <= 0
        ) {
            throw new DomainException('A term billing rule requires a name, fee/tuition type and a positive amount.');
        }
        $rule = self::root($id);
        $rule->recordThat(BillingRuleWasDefined::occur($id, ['term_id' => $term->value,
            'program_id' => $program?->value,
            'name' => $name, 'kind' => $kind, 'minor' => $amount->minor, 'currency' => $amount->currency,
            'actor_id' => $actor->value], [Metadata::AGGREGATE_TYPE => 'billing_rule']));

        return $rule;
    }

    public function retire(EntityId $actor): void
    {
        if (!$this->retired) {
            $this->recordThat(BillingRuleWasRetired::occur(
                $this->aggregateId(),
                ['actor_id' => $actor->value],
                [Metadata::AGGREGATE_TYPE => 'billing_rule']
            ));
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function termId(): EntityId
    {
        return $this->termId;
    }

    public function programId(): ?EntityId
    {
        return $this->programId;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function isRetired(): bool
    {
        return $this->retired;
    }

    protected function whenBillingRuleWasDefined(BillingRuleWasDefined $event): void
    {
        $p = $event->payload();
        $this->termId = new EntityId($p['term_id']);
        $this->programId = $p['program_id'] === null ? null : new EntityId($p['program_id']);
        $this->name = $p['name'];
        $this->kind = $p['kind'];
        $this->amount = new Money($p['minor'], $p['currency']);
    }

    protected function whenBillingRuleWasRetired(BillingRuleWasRetired $event): void
    {
        $this->retired = true;
    }
}
