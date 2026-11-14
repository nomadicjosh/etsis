<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Catalog\Repository\SpecializationPolicyRepository;
use Domain\Catalog\ValueObject\ComponentCourseSharing;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlSpecializationPolicyRepository implements SpecializationPolicyRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function lockInstitution(): void
    {
        $this->sql->lock('sis_specialization_policies', self::INSTITUTION_ID, 'id');
    }
    public function get(?EntityId $program): array
    {
        $row =
        $this->sql->rows(
            'sis_specialization_policies',
            ['id' => $program->value ?? self::INSTITUTION_ID],
            1,
        )[0] ?? null;
        if ($row === null) {
            if ($program === null) {
                throw new CorruptHistoryException();
            }
            return ['revision' => 0, 'minimum' => 0, 'maximum' => null, 'default_maximum' => null,
                'component_sharing' => ComponentCourseSharing::OneComponent->value];
        }
        if ($row['program_id'] !== $program?->value) {
            throw new CorruptHistoryException();
        }
        $out = [];
        foreach (['revision', 'minimum', 'maximum', 'default_maximum'] as $key) {
            if ($row[$key] === null && in_array($key, ['maximum', 'default_maximum'], true)) {
                $out[$key] = null;
                continue;
            }
            $value = filter_var($row[$key], FILTER_VALIDATE_INT);
            if ($value === false || $value < 0 || $value > 2000000000) {
                throw new CorruptHistoryException();
            }
            $out[$key] = $value;
        }
        if ($program !== null) {
            $sharing = $row['component_sharing'] ?? ComponentCourseSharing::OneComponent->value;
            if (!is_string($sharing) || ComponentCourseSharing::tryFrom($sharing) === null) {
                throw new CorruptHistoryException();
            }
            $out['component_sharing'] = $sharing;
        }
        return $out;
    }
    public function save(?EntityId $program, array $values): void
    {
        $id = $program->value ?? self::INSTITUTION_ID;
        if ($this->sql->rows('sis_specialization_policies', ['id' => $id], 1) === []) {
            $this->sql->insert(
                'sis_specialization_policies',
                ['id' => $id, 'program_id' => $program?->value] + $values,
            );
        } else {
            $this->sql->update('sis_specialization_policies', $values, ['id' => $id]);
        }
    }
}
