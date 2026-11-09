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
        return in_array($class, self::registered(), true);
    }

    /** @return list<class-string<\Codefy\Domain\EventSourcing\AggregateChanged>> */
    public static function registered(): array
    {
        return [
            \Domain\Gradebook\Event\GradingScaleWasChanged::class,
            \Domain\DegreeAudit\Event\RequirementAdjustmentWasGranted::class,
            \Domain\DegreeAudit\Event\RequirementAdjustmentWasRevoked::class,
            \Domain\DegreeAudit\Event\StudentRequirementsWereAssigned::class,
            \Domain\DegreeAudit\Event\CourseGroupWasPublished::class,
            \Domain\DegreeAudit\Event\RequirementsWerePublished::class,
            \Domain\Registration\Event\SectionCourseVersionWasBound::class,
            \Domain\Catalog\Event\CourseDefinitionWasPublished::class,
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
        ];
    }
}
