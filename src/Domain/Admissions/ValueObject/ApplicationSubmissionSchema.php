<?php

declare(strict_types=1);

namespace Domain\Admissions\ValueObject;

use Domain\Admissions\Event\AdmissionApplicationWasObserved;
use Domain\Shared\EventSourcing\CorruptHistoryException;

final class ApplicationSubmissionSchema
{
    public const array FIELDS = [
        "decision_id",
        "application",
        "source",
        "channel",
        "authorization",
        "form_definition_hash",
    ];
    /** @param array<string,mixed> $p */
    public static function validate(array $p): void
    {
        if (count($p) !== count(self::FIELDS) || array_diff(array_keys($p), self::FIELDS) !== []) {
            throw new CorruptHistoryException();
        }
        AdmissionDecisionSchema::validate(AdmissionApplicationWasObserved::class, [
            "decision_id" => $p["decision_id"],
            "application" => $p["application"],
        ]);
        AdmissionDecisionSchema::source($p["source"]);
        if (
            $p["application"]["version"] !== 0 ||
            !in_array($p["channel"], ["direct", "form"], true) ||
            !in_array($p["authorization"], ["owner", "admissions_staff"], true)
        ) {
            throw new CorruptHistoryException();
        }
        $ref = $p["source"]["submission"];
        if ($p["channel"] === "direct") {
            if (
                $ref !== null ||
                $p["form_definition_hash"] !== null ||
                $p["decision_id"] !== $p["application"]["id"]
            ) {
                throw new CorruptHistoryException();
            }
        } elseif (
            $ref === null ||
            $ref["id"] !== $p["decision_id"] ||
            !is_string($p["form_definition_hash"]) ||
            preg_match('/^[a-f0-9]{64}$/D', $p["form_definition_hash"]) !== 1
        ) {
            throw new CorruptHistoryException();
        }
    }
}
