<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use Domain\Finance\Event\AccountEntryWasPosted;
use Domain\Finance\Event\StudentAccountWasOpened;
use Domain\User\Event\EmailAddressWasChanged;
use Domain\User\Event\NameWasChanged;
use Domain\User\Event\PasswordWasChanged;
use Domain\User\Event\RoleWasChanged;
use Domain\User\Event\UserWasCreated;
use Domain\User\Event\UserWasDeleted;

final class EventClassMap
{
    public static function allows(string $class): bool
    {
        return in_array($class, [
            \Domain\Finance\Event\BillingRuleWasDefined::class,
            \Domain\Finance\Event\BillingRuleWasRetired::class,
            \Domain\Finance\Event\AccountEntryWasReversed::class,
            \Domain\Finance\Event\JournalWasPosted::class,
            \Domain\Finance\Event\JournalWasReversed::class,
            UserWasCreated::class,
            UserWasDeleted::class,
            EmailAddressWasChanged::class,
            NameWasChanged::class,
            PasswordWasChanged::class,
            RoleWasChanged::class,
            StudentAccountWasOpened::class,
            AccountEntryWasPosted::class,
        ], true);
    }
}
