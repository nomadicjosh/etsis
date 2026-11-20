<?php

declare(strict_types=1);

namespace Domain\Student\Event;

use Codefy\Domain\EventSourcing\AggregateChanged;

/** An attributed correction to a closed pursuit, with explicit institutional-evidence confirmation. */
final class StudentStandingWasCorrected extends AggregateChanged
{
}
