<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

final class RegistrationBatchSelection
{
    public const int LIMIT = 25;
    /** @var list<EntityId> */
    public readonly array $students;
    private array $creditChoices = [];
    private \Domain\Registration\Enum\EnrollmentBasis $enrollmentBasis =
    \Domain\Registration\Enum\EnrollmentBasis::Standard;

    public function withBasis(\Domain\Registration\Enum\EnrollmentBasis $basis): self
    {
        $copy = clone $this;
        $copy->enrollmentBasis = $basis;
        return $copy;
    }

    public function basis(): \Domain\Registration\Enum\EnrollmentBasis
    {
        return $this->enrollmentBasis;
    }

    public function __construct(public readonly EntityId $section, EntityId ...$students)
    {
        if (count($students) < 1 || count($students) > self::LIMIT) {
            throw new ValidationException([['field' => 'students', 'message' => 'Select 1–25 distinct students.']]);
        }
        $keys = [];
        foreach ($students as $student) {
            if (isset($keys[$student->value])) {
                throw new ValidationException([
                    ['field' => 'students', 'message' => 'Select distinct student records.'],
                ]);
            }
            $keys[$student->value] = $student;
        }
        ksort($keys, SORT_STRING);
        $this->students = array_values($keys);
    }

    public function withCreditChoices(array $choices): self
    {
        $copy = clone $this;
        $copy->creditChoices = RequestedCreditChoices::validate($choices, $this->students);
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

    public function requestHash(string $revision): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
            throw new ValidationException([
                ['field' => 'revision', 'message' => 'Review the batch before confirming.'],
            ]);
        }
        return hash('sha256', json_encode([$this->section->value,
            array_map(static fn (EntityId $id): string => $id->value, $this->students),
            $revision, ...($this->basis() === \Domain\Registration\Enum\EnrollmentBasis::Audit ? ['audit'] : []),
            ...($this->creditChoices === [] ? [] : [$this->creditChoices])], JSON_THROW_ON_ERROR));
    }
}
