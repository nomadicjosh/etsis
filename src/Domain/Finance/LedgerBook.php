<?php

declare(strict_types=1);

namespace Domain\Finance;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Domain\Finance\Event\LedgerBookWasPublished;
use Domain\Finance\ValueObject\LedgerSchema;

/** One institution currency's explicitly published student-account mapping. */
final class LedgerBook extends EventSourcedAggregate
{
    private array $definition = [];

    protected function whenLedgerBookWasPublished(
        LedgerBookWasPublished $event,
    ): void {
        LedgerSchema::book($event->payload()["definition"]);
        $this->definition = $event->payload()["definition"];
    }

    public function definition(): array
    {
        return $this->definition;
    }
}
