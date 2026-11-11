<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Retained calculation choices. Null means not recorded, never a current-setting default. */
final readonly class AuditCalculationPolicy
{
    public function __construct(
        public ?RepeatCreditPolicy $credit,
        public ?RepeatGpaPolicy $gpa,
        public ?TransferGpaPolicy $transferGpa,
        public ?EntityId $gradeScaleVersion,
        public ?RepeatAttemptOrder $attemptOrder,
    ) {
    }

    /** Defaults apply only when making a new institutional selection, never when reading earlier history. */
    public static function forNewSelection(
        RepeatCreditPolicy $credit,
        RepeatGpaPolicy $gpa,
        TransferGpaPolicy $transferGpa,
        ?EntityId $gradeScaleVersion,
        RepeatAttemptOrder $attemptOrder = RepeatAttemptOrder::AcademicPeriod,
    ): self {
        return new self($credit, $gpa, $transferGpa, $gradeScaleVersion, $attemptOrder);
    }

    /** @param array<string,mixed> $requirements Already-verified retained publication, as returned by its reader.
     * @param ?RepeatAttemptOrder $retainedAttemptOrder Retained institutional choice; absence stays unknown.
     */
    public static function fromRequirements(
        array $requirements,
        ?RepeatAttemptOrder $retainedAttemptOrder = null,
    ): self {
        $settings = $requirements["definition"]["policies"] ?? [];
        if (!is_array($settings)) {
            throw new InvalidArgumentException("Use retained audit policy choices.");
        }
        $credit = $settings["repeat_credit"] ?? null;
        $gpa = $settings["repeat_gpa"] ?? null;
        $transfer = $settings["transfer_overall_gpa"] ?? null;
        $version = $requirements["grade_scale"]["version_id"] ?? null;
        if (
            ($credit !== null && !is_string($credit)) ||
            ($gpa !== null && !is_string($gpa)) ||
            ($transfer !== null && !is_string($transfer)) ||
            ($version !== null && !is_string($version))
        ) {
            throw new InvalidArgumentException("Use retained audit policy choices.");
        }
        return new self(
            $credit === null
                ? null
                : RepeatCreditPolicy::tryFrom($credit) ??
                    throw new InvalidArgumentException("Unknown repeat-credit policy."),
            $gpa === null
                ? null
                : RepeatGpaPolicy::tryFrom($gpa) ??
                    throw new InvalidArgumentException("Unknown repeat-GPA policy."),
            $transfer === null
                ? null
                : TransferGpaPolicy::tryFrom($transfer) ??
                    throw new InvalidArgumentException("Unknown transfer-GPA policy."),
            $version === null ? null : new EntityId($version),
            $retainedAttemptOrder,
        );
    }
}
