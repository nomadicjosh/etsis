<?php

declare(strict_types=1);

namespace Domain\Person\Event;

use Codefy\Domain\EventSourcing\AggregateChanged;

final class PersonEducationWasRecorded extends AggregateChanged
{
}
