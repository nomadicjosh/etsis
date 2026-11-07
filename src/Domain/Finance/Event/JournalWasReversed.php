<?php

declare(strict_types=1);

namespace Domain\Finance\Event;

use Codefy\Domain\EventSourcing\AggregateChanged;

final class JournalWasReversed extends AggregateChanged
{
}
