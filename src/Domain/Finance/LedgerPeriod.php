<?php

declare(strict_types=1);

namespace Domain\Finance;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Domain\Finance\Event\LedgerPeriodWasRecorded;
use Domain\Finance\ValueObject\LedgerSchema;

/** Retained accounting dates, closing decisions and authenticated movement checkpoints. */
final class LedgerPeriod extends EventSourcedAggregate
{
    private array $definition = [];

    protected function whenLedgerPeriodWasRecorded(
        LedgerPeriodWasRecorded $event,
    ): void {
        LedgerSchema::period($event->payload()["definition"]);
        $this->definition = $event->payload()["definition"];
    }

    public function definition(): array
    {
        return $this->definition;
    }
}
