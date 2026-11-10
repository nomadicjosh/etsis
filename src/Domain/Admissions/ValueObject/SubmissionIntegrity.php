<?php

declare(strict_types=1);

namespace Domain\Admissions\ValueObject;

use Domain\Admissions\ApplicationForm;
use Domain\Shared\ValueObject\EntityId;

/** Frozen v1 fingerprints. Verification never executes today's answer validation or conditional rules. */
final class SubmissionIntegrity
{
    /** @param array<string,mixed> $answers */
    public static function fingerprint(
        EntityId $form,
        EntityId $person,
        EntityId $term,
        #[\SensitiveParameter] array $answers,
    ): string {
        return hash(
            "sha256",
            json_encode([$form->value, $person->value, $term->value, $answers], JSON_THROW_ON_ERROR),
        );
    }
    public static function formHash(ApplicationForm $form): string
    {
        return hash(
            "sha256",
            self::canonical([
                "id" => $form->id->value,
                "program_id" => $form->programId->value,
                "name" => $form->name,
                "version" => $form->version,
                "fields" => $form->fields,
            ]),
        );
    }
    private static function canonical(mixed $value): string
    {
        if (!is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }
        if (array_is_list($value)) {
            return "[" . implode(",", array_map(self::canonical(...), $value)) . "]";
        }
        ksort($value, SORT_STRING);
        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = json_encode((string) $key, JSON_THROW_ON_ERROR) . ":" . self::canonical($item);
        }
        return "{" . implode(",", $parts) . "}";
    }
}
