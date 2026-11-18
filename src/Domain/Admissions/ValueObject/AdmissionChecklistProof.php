<?php

declare(strict_types=1);

namespace Domain\Admissions\ValueObject;

use Domain\Admissions\AdmissionCasework;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ValidationException;

/** Frozen decision evidence, separate from mutable working casework. */
final class AdmissionChecklistProof
{
    public const array FIELDS = [
        "required",
        "policy_revision",
        "case_version",
        "work_status",
        "items",
    ];

    public static function capture(
        ?AdmissionCasework $case,
        array $policy,
    ): array {
        $proof = [
            "required" => $policy["required"],
            "policy_revision" => $policy["revision"],
            "case_version" => $case?->version,
            "work_status" => $case?->values["work_status"],
            "items" => [],
        ];
        foreach ($case?->values["items"] ?? [] as $item) {
            if ($item["needed"]) {
                $proof["items"][] = array_intersect_key(
                    $item,
                    array_flip(["key", "label", "status", "visible"]),
                );
            }
        }
        self::validate($proof);
        return $proof;
    }

    public static function validate(
        #[\SensitiveParameter] mixed $proof,
        ?string $outcome = null,
    ): void {
        if (
            !is_array($proof) ||
            count($proof) !== count(self::FIELDS) ||
            array_diff(array_keys($proof), self::FIELDS) !== [] ||
            !is_bool($proof["required"]) ||
            !is_int($proof["policy_revision"]) ||
            $proof["policy_revision"] < 0 ||
            $proof["policy_revision"] > 2000000000 ||
            !is_array($proof["items"]) ||
            !array_is_list($proof["items"]) ||
            count($proof["items"]) > CaseworkChecklist::LIMIT
        ) {
            throw new CorruptHistoryException();
        }
        if ($proof["case_version"] === null) {
            if ($proof["work_status"] !== null || $proof["items"] !== []) {
                throw new CorruptHistoryException();
            }
        } elseif (
            !is_int($proof["case_version"]) ||
            $proof["case_version"] < 1 ||
            !in_array($proof["work_status"], ["open", "waiting", "done"], true)
        ) {
            throw new CorruptHistoryException();
        }
        $seen = [];
        foreach ($proof["items"] as $item) {
            if (
                !is_array($item) ||
                count($item) !== 4 ||
                array_diff(array_keys($item), [
                    "key",
                    "label",
                    "status",
                    "visible",
                ]) !== [] ||
                !is_string($item["key"]) ||
                preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $item["key"]) !== 1 ||
                isset($seen[$item["key"]]) ||
                !AdmissionOfferSchema::text($item["label"], 191) ||
                !in_array($item["status"], CaseworkChecklist::STATES, true) ||
                !is_bool($item["visible"])
            ) {
                throw new CorruptHistoryException();
            }
            $seen[$item["key"]] = true;
        }
        if ($proof["work_status"] === "done" && self::pending($proof) !== []) {
            throw new CorruptHistoryException();
        }
        if (
            $outcome === "accepted" &&
            $proof["required"] &&
            !self::ready($proof)
        ) {
            throw new CorruptHistoryException();
        }
    }

    public static function digest(#[\SensitiveParameter] array $proof): string
    {
        return hash(
            "sha256",
            json_encode(
                [
                    $proof["required"],
                    $proof["policy_revision"],
                    $proof["case_version"],
                    $proof["work_status"],
                    array_map(
                        static fn(array $item): array => [
                            $item["key"],
                            $item["label"],
                            $item["status"],
                            $item["visible"],
                        ],
                        $proof["items"],
                    ),
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ),
        );
    }
    public static function ready(#[\SensitiveParameter] array $proof): bool
    {
        return $proof["case_version"] !== null &&
        self::pending($proof) === [] &&
        ($proof["items"] !== [] || $proof["work_status"] === "done");
    }
    private static function pending(#[\SensitiveParameter] array $proof): array
    {
        return array_values(
            array_filter(
                $proof["items"],
                static fn(array $item): bool => in_array(
                    $item["status"],
                    ["pending", "received"],
                    true,
                ),
            ),
        );
    }
    public static function assertAcceptance(
        #[\SensitiveParameter] array $proof,
        bool $accept,
    ): void {
        if ($accept && $proof["required"] && !self::ready($proof)) {
            throw new ValidationException([
                [
                    "field" => "checklist",
                    "message" =>
                        self::pending($proof) === []
                            ? "Save the checklist review. If no items are needed, mark casework Done before acceptance."
                            : "Complete needed items or mark them Not needed as appropriate before acceptance.",
                ],
            ]);
        }
    }
    /** Staff-only current preview, without private notes/instructions or raw journal data. */
    public static function staff(#[\SensitiveParameter] array $proof): array
    {
        return self::summary($proof) + [
            "pending" => array_column(self::pending($proof), "label"),
            "needs_case_review" =>
                $proof["case_version"] === null ||
                ($proof["items"] === [] && $proof["work_status"] !== "done"),
        ];
    }
    /** History summaries deliberately omit item names and private working details. */
    public static function summary(#[\SensitiveParameter] array $proof): array
    {
        return [
            "required" => $proof["required"],
            "ready" => self::ready($proof),
            "needed" => count($proof["items"]),
            "complete" => count(
                array_filter(
                    $proof["items"],
                    static fn(array $item): bool => $item["status"] ===
                        "complete",
                ),
            ),
            "not_needed" => count(
                array_filter(
                    $proof["items"],
                    static fn(array $item): bool => $item["status"] ===
                        "not_needed",
                ),
            ),
        ];
    }
    /** No hidden task names/counts, staff identities, policy revisions or private notes. */
    public static function applicant(#[\SensitiveParameter] array $proof): array
    {
        $visible = array_filter(
            self::pending($proof),
            static fn(array $item): bool => $item["visible"],
        );
        $message = !$proof["required"]
        ? "Checklist progress helps admissions review your application. It is not an admission decision."
        : (self::ready($proof)
                ? "Your checklist is ready for admissions review. It is not an admission decision."
                : ($visible !== []
                    ? "Finish the requested items below. Admissions will review them before a decision."
                    : "Admissions is reviewing your application requirements."));
        return ["required" => $proof["required"], "message" => $message];
    }
}
