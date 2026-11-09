<?php

declare(strict_types=1);

namespace Domain\Transfer\ValueObject;

use Domain\Shared\Exception\ValidationException;

final readonly class AwardChange
{
    public string $reason;
    public ?string $grade;

    public function __construct(
        public string $kind,
        public ?int $credits,
        ?string $grade,
        #[\SensitiveParameter] string $reason,
    ) {
        $this->reason = trim($reason);
        $this->grade = $grade === null ? null : strtoupper(trim($grade));
        if (!in_array($kind, ["amend", "void", "reinstate"], true)) {
            self::invalid("action", "Choose a correction, void or reinstatement.");
        }
        if ($kind === "void") {
            if ($credits !== null || $grade !== null) {
                self::invalid("action", "A void does not accept replacement credits or a grade.");
            }
        } elseif (
            $credits === null ||
            $credits < 1 ||
            $credits > 999999 ||
            $this->grade === null ||
            preg_match('/^[A-Z0-9+.-]{1,12}$/D', $this->grade) !== 1
        ) {
            self::invalid("credits_milli", "Enter positive credits up to 999.999 and an existing passing grade code.");
        }
        if (
            $this->reason === "" ||
            !mb_check_encoding($this->reason, "UTF-8") ||
            mb_strlen($this->reason) > 10000 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $this->reason)
        ) {
            self::invalid("reason", "Enter a correction reason of up to 10,000 characters.");
        }
    }

    /** @return array{action: string, credits_milli: ?int, awarded_grade: ?string, reason: string} */
    public function describe(): array
    {
        return [
            "action" => $this->kind,
            "credits_milli" => $this->credits,
            "awarded_grade" => $this->grade,
            "reason" => $this->reason,
        ];
    }

    private static function invalid(string $field, string $message): never
    {
        throw new ValidationException([["field" => $field, "message" => $message]]);
    }
}
