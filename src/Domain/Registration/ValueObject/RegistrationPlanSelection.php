<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** A bounded working selection, not an academic aggregate or a seat reservation. */
final readonly class RegistrationPlanSelection
{
    public const int LIMIT = 10;
    /** @var list<EntityId> */
    public array $sections;

    public function __construct(public EntityId $student, EntityId ...$sections)
    {
        if (count($sections) < 1 || count($sections) > self::LIMIT) {
            self::invalid();
        }
        $distinct = [];
        foreach ($sections as $section) {
            if (isset($distinct[$section->value])) {
                self::invalid();
            }
            $distinct[$section->value] = $section;
        }
        ksort($distinct, SORT_STRING);
        $this->sections = array_values($distinct);
    }

    public function requestHash(string $revision, bool $byStaff): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
            self::invalid();
        }
        return hash(
            "sha256",
            json_encode(
                [
                    "registration-plan",
                    $this->student->value,
                    array_map(static fn(EntityId $id): string => $id->value, $this->sections),
                    $revision,
                    $byStaff,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    private static function invalid(): never
    {
        throw new ValidationException([
            ["field" => "sections", "message" => "Choose 1–10 distinct course sections."],
        ]);
    }
}
