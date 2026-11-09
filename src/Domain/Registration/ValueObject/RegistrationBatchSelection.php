<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

final readonly class RegistrationBatchSelection
{
    public const int LIMIT = 25;
    /** @var list<EntityId> */
    public array $students;

    public function __construct(public EntityId $section, EntityId ...$students)
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

    public function requestHash(string $revision): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
            throw new ValidationException([
                ['field' => 'revision', 'message' => 'Review the batch before confirming.'],
            ]);
        }
        return hash('sha256', json_encode([$this->section->value,
            array_map(static fn (EntityId $id): string => $id->value, $this->students),
            $revision], JSON_THROW_ON_ERROR));
    }
}
