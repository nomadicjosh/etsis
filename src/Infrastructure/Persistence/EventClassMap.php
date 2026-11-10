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
            \Domain\Admissions\Event\AdmissionApplicationWasObserved::class,
            \Domain\Admissions\Event\AdmissionWasDecided::class,
            \Domain\Admissions\Event\StudentWasMatriculated::class,
            \Domain\Student\Event\StudentActivityWasStarted::class,
            \Domain\Student\Event\PrimaryProgramWasEstablished::class,
            \Domain\Student\Event\StudentProgramWasAssigned::class,
            \Domain\Student\Event\PrimaryProgramWasObserved::class,
            \Domain\Student\Event\PrimaryProgramWasSelected::class,
            \Domain\Student\Event\StudentProgramWasObserved::class,
            \Domain\Student\Event\StudentProgramStatusWasChanged::class,
            \Domain\Student\Event\StudentActivityWasObserved::class,
            \Domain\Student\Event\StudentActivityWasDecided::class,
            \Domain\Registration\Event\EnrollmentWasEstablished::class,
            \Domain\Registration\Event\EnrollmentWasClosed::class,
            \Domain\Transfer\Event\TransferCreditWasEstablished::class,
            \Domain\Transfer\Event\TransferCreditWasChanged::class,
            \Domain\AcademicRecord\Event\InstitutionalCreditWasEstablished::class,
            \Domain\AcademicRecord\Event\FinalGradeWasDecided::class,
            \Domain\AcademicRecord\Event\InstitutionalCreditWasDropped::class,
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
