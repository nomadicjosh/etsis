<?php

declare(strict_types=1);

namespace Domain\Gradebook\Event;

use Codefy\Domain\EventSourcing\AggregateChanged;

final class ClassGradesWereCertified extends AggregateChanged
{
}
