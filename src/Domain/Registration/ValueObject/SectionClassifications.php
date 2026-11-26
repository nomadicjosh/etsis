<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Catalog\ValueObject\ClassificationSnapshot;
use Domain\Catalog\Enum\ClassificationKind;
use InvalidArgumentException;

use function Codefy\Framework\Helpers\trans_html;

final readonly class SectionClassifications
{
    public function __construct(
        public ?ClassificationSnapshot $instructionalMethod = null,
        public ?ClassificationSnapshot $campus = null,
        public ?ClassificationSnapshot $reportingGroup = null,
        public bool $reportingGroupRecorded = true,
        /** @var list<ClassificationSnapshot>|null Unknown when absent from an earlier publication. */
        public ?array $reportingAncestors = null,
    ) {
        if (!$reportingGroupRecorded && $reportingGroup !== null) {
            throw new InvalidArgumentException(trans_html('An unrecorded reporting group must stay unknown.'));
        }
        if ($reportingAncestors !== null) {
            if (
                !$reportingGroupRecorded || count($reportingAncestors) > 7 ||
                !array_is_list($reportingAncestors) || ($reportingGroup === null && $reportingAncestors !== [])
            ) {
                throw new InvalidArgumentException(
                    trans_html('Use a complete reporting hierarchy of at most eight groups.'),
                );
            }
            $seen = $reportingGroup === null ? [] : [$reportingGroup->id->value => true];
            foreach ($reportingAncestors as $ancestor) {
                if (!$ancestor instanceof ClassificationSnapshot || isset($seen[$ancestor->id->value])) {
                    throw new InvalidArgumentException(
                        trans_html('Use unique reporting groups in the retained hierarchy.'),
                    );
                }
                $seen[$ancestor->id->value] = true;
            }
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $values = [];
        if (!in_array(count($data), [2, 3, 4], true)) {
            throw new InvalidArgumentException(
                "Provide the complete retained section classifications.",
            );
        }
        $recorded = array_key_exists('reporting_group', $data);
        $kinds = [ClassificationKind::InstructionalMethod, ClassificationKind::Campus];
        if ($recorded) {
            $kinds[] = ClassificationKind::ReportingGroup;
        }
        $hasAncestors = array_key_exists('reporting_group_ancestors', $data);
        if (count($data) !== count($kinds) + (int) $hasAncestors || ($hasAncestors && !$recorded)) {
            throw new InvalidArgumentException(trans_html('Use only the retained section classification fields.'));
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
        $ancestors = null;
        if ($hasAncestors) {
            if (!is_array($data['reporting_group_ancestors']) || !array_is_list($data['reporting_group_ancestors'])) {
                throw new InvalidArgumentException(trans_html('Use a complete retained reporting hierarchy.'));
            }
            $ancestors = array_map(static function (mixed $value): ClassificationSnapshot {
                if (!is_array($value)) {
                    throw new InvalidArgumentException(
                        trans_html('Use complete reporting-group definitions in the hierarchy.'),
                    );
                }
                return ClassificationSnapshot::fromArray($value);
            }, $data['reporting_group_ancestors']);
        }
        return new self($values[0], $values[1], $values[2] ?? null, $recorded, $ancestors);
    }

    /** @return array<string,mixed> */
    public function describe(): array
    {
        return [
            "instructional_method" => $this->instructionalMethod?->describe(),
            "campus" => $this->campus?->describe(),
        ] + ($this->reportingGroupRecorded ? ['reporting_group' => $this->reportingGroup?->describe()] : [])
        + ($this->reportingAncestors === null ? [] : ['reporting_group_ancestors' => array_map(
            static fn(ClassificationSnapshot $value): array => $value->describe(),
            $this->reportingAncestors,
        )]);
    }

    public function reportingRoot(): ?ClassificationSnapshot
    {
        if ($this->reportingAncestors === null) {
            return null;
        }
        return $this->reportingAncestors === [] ? $this->reportingGroup
        : $this->reportingAncestors[array_key_last($this->reportingAncestors)];
    }
}
