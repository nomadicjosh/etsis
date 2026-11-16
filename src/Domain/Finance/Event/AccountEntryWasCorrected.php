<?php

declare(strict_types=1);

namespace Domain\Finance\Event;

use Codefy\Domain\EventSourcing\AggregateChanged;

/** One accepted correction retains the original and its replacement together. */
final class AccountEntryWasCorrected extends AggregateChanged
{
}
