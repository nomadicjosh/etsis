<?php

declare(strict_types=1);

namespace Domain\Admissions\ValueObject;

use Domain\Admissions\Event\AdmissionApplicationWasObserved;
use Domain\Admissions\Event\AdmissionWasDecided;
use Domain\Admissions\Event\AdmissionDecisionWasObserved;
use Domain\Admissions\Event\AdmissionDecisionWasCorrected;
use Domain\Shared\EventSourcing\CorruptHistoryException;

/** Frozen v1 correction/observation reader; original submission/decision contracts are unchanged. */
final class AdmissionCorrectionSchema
{
    public const array OBSERVED = ["decision_id", "application", "source"];
    public const array CORRECTED = [
        "decision_id",
        "previous_decision_id",
        "previous_status",
        "status",
        "application_version",
        "source",
    ];
    /** @param array<string,mixed> $p */
    public static function validate(string $class, array $p): void
    {
        $fields = match ($class) {
            AdmissionDecisionWasObserved::class => self::OBSERVED,
            AdmissionDecisionWasCorrected::class => self::CORRECTED,
            default => throw new CorruptHistoryException(),
        };
        if (count($p) !== count($fields) || array_diff(array_keys($p), $fields) !== []) {
            throw new CorruptHistoryException();
        }
        if ($class === AdmissionDecisionWasObserved::class) {
            if (
                !is_array($p["application"]) ||
                !in_array($p["application"]["status"] ?? null, ["accepted", "rejected"], true)
            ) {
                throw new CorruptHistoryException();
            }
            // Reuse the frozen identity/version validation without claiming this was submitted now.
            $a = $p["application"];
            $a["status"] = "submitted";
            AdmissionDecisionSchema::validate(AdmissionApplicationWasObserved::class, [
                "decision_id" => $p["decision_id"],
                "application" => $a,
            ]);
            AdmissionDecisionSchema::source($p["source"]);
            return;
        }
        if (
            !in_array($p["previous_status"], ["accepted", "rejected"], true) ||
            $p["previous_status"] === $p["status"]
        ) {
            throw new CorruptHistoryException();
        }
        $decision = $p;
        unset($decision["previous_status"]);
        AdmissionDecisionSchema::validate(AdmissionWasDecided::class, $decision);
    }
}
