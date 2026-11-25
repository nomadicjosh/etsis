<?php

declare(strict_types=1);

namespace Domain\Registration;

use Domain\Registration\ValueObject\SectionProgramPolicy;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Enum\DeliveryMode;
use Domain\Shared\ValueObject\EntityId;
use Domain\Registration\ValueObject\OfferingCeuChoice;
use InvalidArgumentException;

final readonly class Section
{
    public function __construct(
        public EntityId $id,
        public EntityId $courseId,
        public EntityId $termId,
        public ?EntityId $facultyUserId,
        public string $code,
        public int $capacity,
        public bool $active,
        public bool $webRegistration,
        public Money $fee,
        public DeliveryMode $deliveryMode = DeliveryMode::Unspecified,
        public ?EntityId $publicationVersionId = null,
        public ?EntityId $instructionalMethodId = null,
        public ?EntityId $campusId = null,
        public int $minimumEnrollment = 0,
        public ?EntityId $reportingGroupId = null,
        public ?int $offeringCreditsMilli = null,
        public OfferingCeuChoice $continuingEducation = new OfferingCeuChoice(),
        public SectionProgramPolicy $programPolicy = new SectionProgramPolicy(),
    ) {
        if ($offeringCreditsMilli !== null && ($offeringCreditsMilli < 0 || $offeringCreditsMilli > 999999)) {
            throw new InvalidArgumentException('Use nonnegative academic-credit thousandths.');
        }
        if (trim($code) === '' || strlen($code) > 80 || $capacity < 1 || $capacity > 100000 || $fee->minor < 0) {
            throw new InvalidArgumentException('A section requires a code, positive capacity and nonnegative fees.');
        }
        if ($minimumEnrollment < 0 || $minimumEnrollment > $capacity) {
            throw new InvalidArgumentException('The enrollment target must be between zero and section capacity.');
        }
    }
}
