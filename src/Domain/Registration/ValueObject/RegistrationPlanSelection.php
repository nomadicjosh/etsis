<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** A bounded working selection, not an academic aggregate or a seat reservation. */
final class RegistrationPlanSelection
{
    public const int LIMIT = 10;
    /** @var list<EntityId> */
    public readonly array $sections;
    private array $creditChoices = [];

    public function __construct(public readonly EntityId $student, EntityId ...$sections)
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

    public function withCreditChoices(array $choices): self
    {
        $copy = clone $this;
        $copy->creditChoices = RequestedCreditChoices::validate($choices, $this->sections);
        return $copy;
    }

    public function creditChoices(): array
    {
        return $this->creditChoices;
    }

    public function creditsMilli(EntityId $id): ?int
    {
        return $this->creditChoices[$id->value] ?? null;
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
                    ...($this->creditChoices === [] ? [] : [$this->creditChoices]),
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
