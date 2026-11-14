<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\ValueObject;

use Codefy\Domain\EventSourcing\AggregateChanged;
use Domain\AcademicRecord\AcademicCredit;
use Domain\AcademicRecord\Event\AcademicCompletionWasRecorded;
use Domain\AcademicRecord\Event\InstitutionalAcademicPeriodWasRecorded;

enum AcademicDateKind: string
{
    case Completion = "completed_on";
    case Period = "academic_on";

    public function tokenKind(): string
    {
        return $this === self::Completion ? "academic-completion-review" : "institutional-academic-period-review";
    }
    public function label(): string
    {
        return $this === self::Completion ? "actual completion date" : "source academic-period start";
    }
    public function current(AcademicCredit $credit): ?string
    {
        return $this === self::Completion ? $credit->completedOn() : $credit->academicOn();
    }
    /** @return class-string<AggregateChanged> */
    public function eventClass(): string
    {
        return $this === self::Completion
        ? AcademicCompletionWasRecorded::class
        : InstitutionalAcademicPeriodWasRecorded::class;
    }
    public function action(): string
    {
        return $this === self::Completion ? "academic.completion_recorded" : "academic.period_recorded";
    }
}
