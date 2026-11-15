<?php

declare(strict_types=1);

namespace Domain\User\Event;

use Codefy\Domain\EventSourcing\AggregateChanged;

final class UserSignInWasChanged extends AggregateChanged
{
}
