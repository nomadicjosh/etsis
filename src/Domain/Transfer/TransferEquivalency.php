<?php

declare(strict_types=1);

namespace Domain\Transfer;

use DateTimeImmutable;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use InvalidArgumentException;

final readonly class TransferEquivalency
{
    public function __construct(
        public EntityId $id,
        public EntityId $externalInstitutionId,
        public EntityId $externalCourseId,
        public EntityId $institutionalCourseId,
        public DateRange $effectiveDates,
        public int $minimumGradeRank,
        public int $creditThousandths,
        public string $awardedGrade,
    ) {
        if (
            $creditThousandths < 1
            || $creditThousandths > 999_999
            || trim($awardedGrade) === ''
            || $minimumGradeRank < 0 || $minimumGradeRank > 10000
        ) {
            throw new InvalidArgumentException('Positive transfer credits and an awarded grade are required.');
        }
    }

    public function assertEligible(EntityId $institutionId, DateTimeImmutable $completedOn, int $gradeRank): void
    {
        if (
            $institutionId->value !== $this->externalInstitutionId->value
            || $completedOn->format('Y-m-d') < $this->effectiveDates->start->format('Y-m-d')
            || ($this->effectiveDates->end !== null
                && $completedOn->format('Y-m-d') > $this->effectiveDates->end->format('Y-m-d'))
            || $gradeRank < $this->minimumGradeRank
        ) {
            throw new DomainException('The external course does not meet the transfer equivalency.');
        }
    }
}
