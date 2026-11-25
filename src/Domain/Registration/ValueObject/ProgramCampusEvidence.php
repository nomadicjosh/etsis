<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use JsonSerializable;

/** The accepted cohort/location assessment, independent of later pursuit and catalog edits. */
final readonly class ProgramCampusEvidence implements JsonSerializable
{
    public array $data;

    public function __construct(#[\SensitiveParameter] array $data)
    {
        $fields = ['student_id', 'section_id', 'section_version_id', 'pursuit_id', 'program_id',
            'academic_year_id', 'program_version_id', 'campus_id'];
        try {
            if (count($data) !== count($fields) || array_diff(array_keys($data), $fields) !== []) {
                throw new \InvalidArgumentException();
            }
            foreach ($data as $reference) {
                if (!is_string($reference) || new EntityId($reference)->value !== $reference) {
                    throw new \InvalidArgumentException();
                }
            }
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
        // Map ordering can change in protected storage; identity comparisons must remain strict.
        ksort($data, SORT_STRING);
        $this->data = $data;
    }

    public function routing(string $student, string $section, ?string $publication, string $pursuit): void
    {
        if (
            $this->data['student_id'] !== $student || $this->data['section_id'] !== $section
            || $this->data['section_version_id'] !== $publication || $this->data['pursuit_id'] !== $pursuit
        ) {
            throw new CorruptHistoryException();
        }
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    public function __debugInfo(): array
    {
        return ['evidence' => '[protected program campus eligibility]'];
    }
}
