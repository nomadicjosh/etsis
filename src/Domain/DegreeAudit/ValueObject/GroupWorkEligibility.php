<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\ValueObject\TransferLabel;

/** Published group restrictions on recorded origin and actual completion, never posting dates. */
final readonly class GroupWorkEligibility
{
    public const array DEFAULT = [
        "credit_origin" => "any",
        "completed_from" => null,
        "completed_through" => null,
        "max_age_months_at_pursuit_start" => null,
        "transfer_institutions" => [],
    ];

    public function __construct(public array $facts = self::DEFAULT)
    {
        if (
            !in_array(count($facts), [3, 4, 5], true) ||
            array_diff(array_keys($facts), array_keys(self::DEFAULT)) !== [] ||
            array_diff(
                ["credit_origin", "completed_from", "completed_through"],
                array_keys($facts),
            ) !== [] ||
            !in_array($facts["credit_origin"] ?? null, ["any", "institutional", "transfer"], true)
        ) {
            self::invalid();
        }
        $months = $facts["max_age_months_at_pursuit_start"] ?? null;
        if ($months !== null && (!is_int($months) || $months < 1 || $months > 1200)) {
            self::invalid();
        }
        $institutions = $facts["transfer_institutions"] ?? [];
        if (
            !is_array($institutions) ||
            !array_is_list($institutions) ||
            count($institutions) > 20 ||
            ($institutions !== [] && $facts["credit_origin"] === "institutional")
        ) {
            self::invalid();
        }
        $seen = [];
        foreach ($institutions as $institution) {
            if (
                !is_array($institution) ||
                count($institution) !== 3 ||
                array_diff(array_keys($institution), ["id", "code", "name"]) !== []
            ) {
                self::invalid();
            }
            try {
                $id = new EntityId($institution["id"]);
                $label = new TransferLabel($institution["code"], $institution["name"], "name");
                if (
                    $label->code !== $institution["code"] ||
                    $label->title !== $institution["name"] ||
                    isset($seen[$id->value])
                ) {
                    self::invalid();
                }
                $seen[$id->value] = true;
            } catch (\Throwable) {
                self::invalid();
            }
        }
        foreach (["completed_from", "completed_through"] as $key) {
            if ($facts[$key] !== null) {
                self::date($facts[$key]);
            }
        }
        if (
            $facts["completed_from"] !== null &&
            $facts["completed_through"] !== null &&
            $facts["completed_from"] > $facts["completed_through"]
        ) {
            self::invalid();
        }
    }

    /** Origin can establish exclusion without guessing other missing academic facts. */
    public function originStatus(?string $origin = null): ?CourseMatchStatus
    {
        if ($this->facts["credit_origin"] === "any") {
            return null;
        }
        if (!in_array($origin, ["institutional", "transfer"], true)) {
            return CourseMatchStatus::OriginUnknown;
        }
        return $origin === $this->facts["credit_origin"] ? null : CourseMatchStatus::OriginExcluded;
    }

    public function transferStatus(
        ?string $origin = null,
        ?string $institution = null,
    ): ?CourseMatchStatus {
        $allowed = $this->facts["transfer_institutions"] ?? [];
        if ($allowed === [] || $origin === "institutional") {
            return null;
        }
        if ($origin !== "transfer") {
            return CourseMatchStatus::OriginUnknown;
        }
        if ($institution === null) {
            return CourseMatchStatus::TransferInstitutionUnknown;
        }
        return in_array($institution, array_column($allowed, "id"), true)
        ? null
        : CourseMatchStatus::TransferInstitutionExcluded;
    }

    public function completionStatus(
        ?string $completedOn = null,
        ?string $pursuitStartedOn = null,
    ): ?CourseMatchStatus {
        $months = $this->facts["max_age_months_at_pursuit_start"] ?? null;
        if (
            $this->facts["completed_from"] === null &&
            $this->facts["completed_through"] === null &&
            $months === null
        ) {
            return null;
        }
        if ($completedOn === null) {
            return CourseMatchStatus::CompletionDateUnknown;
        }
        self::date($completedOn);
        if (
            $this->facts["completed_from"] !== null &&
            $completedOn < $this->facts["completed_from"]
        ) {
            return CourseMatchStatus::CompletedBeforeWindow;
        }
        if (
            $this->facts["completed_through"] !== null &&
            $completedOn > $this->facts["completed_through"]
        ) {
            return CourseMatchStatus::CompletedAfterWindow;
        }
        if ($months !== null) {
            $context = $this->recencyContext($pursuitStartedOn);
            if ($context["pursuit_started_on"] === null) {
                return CourseMatchStatus::PursuitStartUnknown;
            }
            if ($completedOn < $context["earliest_completion_on"]) {
                return CourseMatchStatus::CompletionTooOld;
            }
        }
        return null;
    }

    /** Calendar-month subtraction clamps to the last valid day; the boundary is inclusive.
     * @return array{pursuit_started_on:?string,earliest_completion_on:?string}
     */
    public function recencyContext(?string $pursuitStartedOn = null): array
    {
        $months = $this->facts["max_age_months_at_pursuit_start"] ?? null;
        if ($months === null || $pursuitStartedOn === null) {
            return ["pursuit_started_on" => null, "earliest_completion_on" => null];
        }
        self::date($pursuitStartedOn);
        $index =
        (int) substr($pursuitStartedOn, 0, 4) * 12 +
        (int) substr($pursuitStartedOn, 5, 2) -
        1 -
        $months;
        if ($index < 12) {
            $from = "0001-01-01";
        } else {
            $first = sprintf("%04d-%02d-01", intdiv($index, 12), ($index % 12) + 1);
            $date = new DateTimeImmutable($first, new DateTimeZone("UTC"));
            $from =
            substr($first, 0, 8) .
            str_pad(
                (string) min((int) substr($pursuitStartedOn, 8, 2), (int) $date->format("t")),
                2,
                "0",
                STR_PAD_LEFT,
            );
        }
        return ["pursuit_started_on" => $pursuitStartedOn, "earliest_completion_on" => $from];
    }

    /** @param array<string,mixed> $requirements Verified publication composition. */
    public static function requiresPursuitStart(array $requirements): bool
    {
        foreach ($requirements["group_details"] ?? [] as $group) {
            if (
                ($group["definition"]["work_eligibility"]["max_age_months_at_pursuit_start"] ??
                    null) !==
                null
            ) {
                return true;
            }
        }
        return false;
    }

    private static function date(mixed $value): void
    {
        if (
            !is_string($value) ||
            preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) !== 1 ||
            substr($value, 0, 4) === "0000"
        ) {
            self::invalid();
        }
        $date = DateTimeImmutable::createFromFormat("!Y-m-d", $value, new DateTimeZone("UTC"));
        if ($date === false || $date->format("Y-m-d") !== $value) {
            self::invalid();
        }
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException(
            "Choose valid origin/completion rules, optional age of 1–1,200 months " .
            "and up to 20 distinct sending institutions.",
        );
    }
}
