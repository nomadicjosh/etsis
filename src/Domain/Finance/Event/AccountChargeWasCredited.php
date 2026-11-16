<?php

declare(strict_types=1);

namespace Domain\Finance\Event;

use Codefy\Domain\EventSourcing\AggregateChanged;

/** A separately retained credit against one original charge, without rewriting its source. */
final class AccountChargeWasCredited extends AggregateChanged
{
}
