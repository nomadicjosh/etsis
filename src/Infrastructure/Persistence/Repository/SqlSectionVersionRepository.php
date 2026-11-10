<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Registration\Repository\SectionVersionRepository;
use Domain\Registration\ValueObject\PublishedSectionDefinition;
use Domain\Registration\ValueObject\SectionDefinitionV1;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\Persistence\SqlRecords;
use Throwable;

final readonly class SqlSectionVersionRepository implements SectionVersionRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function find(EntityId $publication): ?PublishedSectionDefinition
    {
        $row = $this->sql->rows('sis_section_versions', ['id' => $publication->value], 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        try {
            $facts = array_intersect_key($row, array_flip(SectionDefinitionV1::FIELDS));
            $facts['capacity'] = $this->integer($facts['capacity']);
            $facts['fee_minor'] = $this->integer($facts['fee_minor']);
            if ($this->integer($row['schema_version']) !== 1) {
                throw new CorruptHistoryException();
            }
            return new PublishedSectionDefinition(
                new EntityId($row['section_id']),
                $publication,
                $this->integer($row['stream_version']),
                new SectionDefinitionV1($facts)
            );
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function retain(PublishedSectionDefinition $definition): void
    {
        $existing = $this->find($definition->publication);
        if ($existing !== null) {
            if (
                ProtectedEventCodec::canonical($existing->describe())
                !== ProtectedEventCodec::canonical($definition->describe())
            ) {
                throw new CorruptHistoryException();
            }
            return;
        }
        $this->sql->insert('sis_section_versions', [
            'id' => $definition->publication->value, 'section_id' => $definition->section->value,
            'stream_version' => $definition->position, 'schema_version' => 1,
        ] + $definition->definition->data);
    }

    private function integer(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || (string) (int) $value !== $value)) {
            throw new CorruptHistoryException();
        }
        return (int) $value;
    }
}
