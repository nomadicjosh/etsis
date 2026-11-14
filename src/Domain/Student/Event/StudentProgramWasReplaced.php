<?php

declare(strict_types=1);

namespace Domain\Student\Event;

use Codefy\Domain\EventSourcing\AggregateChanged;

/** The former pursuit closed as part of a program change, without institutional withdrawal. */
final class StudentProgramWasReplaced extends AggregateChanged
{
}
