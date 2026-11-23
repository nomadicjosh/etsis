<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Catalog\ValueObject\ClassificationSnapshot;
use Domain\Catalog\Enum\ClassificationKind;
use InvalidArgumentException;

final readonly class SectionClassifications
{
    public function __construct(
        public ?ClassificationSnapshot $instructionalMethod = null,
        public ?ClassificationSnapshot $campus = null,
        public ?ClassificationSnapshot $reportingGroup = null,
        public bool $reportingGroupRecorded = true,
    ) {
        if (!$reportingGroupRecorded && $reportingGroup !== null) {
            throw new InvalidArgumentException('An unrecorded reporting group must stay unknown.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $values = [];
        if (!in_array(count($data), [2, 3], true)) {
            throw new InvalidArgumentException(
                "Provide the complete retained section classifications.",
            );
        }
        $recorded = array_key_exists('reporting_group', $data);
        $kinds = [ClassificationKind::InstructionalMethod, ClassificationKind::Campus];
        if ($recorded) {
            $kinds[] = ClassificationKind::ReportingGroup;
        }
        if (count($data) !== count($kinds)) {
            throw new InvalidArgumentException('Use only the retained section classification fields.');
        }
        foreach ($kinds as $kind) {
            if (!array_key_exists($kind->value, $data)) {
                throw new InvalidArgumentException(
                    "Provide the complete retained section classifications.",
                );
            }
            $value = $data[$kind->value];
            if ($value !== null && !is_array($value)) {
                throw new InvalidArgumentException(
                    "Use a classification definition or an explicit blank.",
                );
            }
            $values[] =
            $value === null
            ? null
            : ClassificationSnapshot::fromArray($value);
        }
        return new self($values[0], $values[1], $values[2] ?? null, $recorded);
    }

    /** @return array<string, array{id: string, code: string, title: string}|null> */
    public function describe(): array
    {
        return [
            "instructional_method" => $this->instructionalMethod?->describe(),
            "campus" => $this->campus?->describe(),
        ] + ($this->reportingGroupRecorded ? ['reporting_group' => $this->reportingGroup?->describe()] : []);
    }
}
