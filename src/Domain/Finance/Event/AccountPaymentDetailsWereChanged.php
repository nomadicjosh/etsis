<?php

declare(strict_types=1);

namespace Domain\Finance\Event;

use Codefy\Domain\EventSourcing\AggregateChanged;

/** Changes the receipt information for one retained entry family, without moving money. */
final class AccountPaymentDetailsWereChanged extends AggregateChanged
{
}
