<?php

declare(strict_types=1);

namespace Domain\Lms\ValueObject;

/** Moodle-owned identifier only. etSIS records keep ULID primary keys. */
final readonly class MoodleExternalId
{
    public function __construct(#[\SensitiveParameter] public string $value)
    {
        if (
            preg_match('/^[1-9][0-9]{0,18}$/D', $value) !== 1 ||
            (strlen($value) === 19 && strcmp($value, "9223372036854775807") > 0)
        ) {
            throw new \InvalidArgumentException("Use a canonical positive Moodle record ID.");
        }
    }
    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ["external_id" => "[private external reference]"];
    }
}
