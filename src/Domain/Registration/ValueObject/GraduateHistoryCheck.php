<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\ValueObject\EntityId;

/** Restricted routing plus fixed counts/codes; never GPA, reasons, actors, source identities or payloads. */
final readonly class GraduateHistoryCheck
{
    public function __construct(
        public string $kind,
        public EntityId $reference,
        public string $status,
        public int $events,
        public ?int $records = null,
        public array $issues = [],
    ) {
        $complete = in_array($status, ["verified", "observed_empty"], true);
        if (
            !in_array($kind, ["gradpolicy", "graduate"], true) ||
            !in_array($status, ["verified", "observed_empty", "unverified", "changed", "limited"], true) ||
            $events < 0 ||
            $events > 2000 ||
            $complete !== ($records !== null) ||
            ($records !== null && ($records < 0 || $records > $events)) ||
            ($status === "verified" && ($records ?? 0) < 1) ||
            ($status === "observed_empty" && $records !== 0) ||
            !array_is_list($issues) ||
            array_diff($issues, [
                "graduate_history",
                "retained_reference",
                "pursuit_identity",
                "unavailable",
            ]) !== [] ||
            ($status === "unverified" ? $issues === [] : $issues !== [])
        ) {
            throw new \InvalidArgumentException("Use a bounded consistent graduate history observation.");
        }
    }

    public function describe(): array
    {
        return [
            "status" => $this->status,
            "scope" =>
                $this->kind === "gradpolicy" ? "graduate_registration_policy" : "graduate_course_approvals",
            $this->kind === "gradpolicy" ? "policy_reference" : "pursuit_reference" => $this->reference
                ->value,
            "events_checked" => $this->events,
            "records_checked" => $this->records,
            "issues" => $this->issues,
            "records_changed" => false,
        ];
    }
}
