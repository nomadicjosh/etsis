<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Registration\Repository\SectionVersionRepository;
use Domain\Registration\ValueObject\PublishedSectionDefinition;
use Domain\Registration\ValueObject\SectionDefinitionV1;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\ConcurrentModificationException;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\Persistence\SqlRecords;
use Throwable;

final readonly class SqlSectionVersionRepository implements SectionVersionRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function selected(EntityId $section): ?PublishedSectionDefinition
    {
        $row = $this->sql->rows("sis_section_offering_versions", ["id" => $section->value], 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        $definition =
        $this->find(new EntityId($row["publication_id"])) ?? throw new CorruptHistoryException();
        if ($definition->section->value !== $section->value) {
            throw new CorruptHistoryException();
        }
        return $definition;
    }

    public function selectionPosition(EntityId $section): int
    {
        $row = $this->sql->rows("sis_section_offering_versions", ["id" => $section->value], 1)[0] ?? null;
        if ($row === null) {
            return 0;
        }
        $position = $this->integer($row["stream_version"]);
        if ($position < 1 || $position > 2147483646) {
            throw new CorruptHistoryException();
        }
        return $position;
    }

    public function amend(
        PublishedSectionDefinition $before,
        PublishedSectionDefinition $after,
        int $expectedPosition,
    ): void {
        if (
            $before->section->value !== $after->section->value ||
            $before->publication->value === $after->publication->value ||
            $expectedPosition < 1
        ) {
            throw new CorruptHistoryException();
        }
        $this->retain($after);
        if (
            $this->sql->update(
                "sis_section_offering_versions",
                [
                    "publication_id" => $after->publication->value,
                    "stream_version" => $expectedPosition + 1,
                ],
                [
                    "id" => $before->section->value,
                    "publication_id" => $before->publication->value,
                    "stream_version" => $expectedPosition,
                ],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("The offering selection changed.");
        }
    }

    public function select(PublishedSectionDefinition $definition): void
    {
        $old = $this->selected($definition->section);
        if ($old !== null) {
            if ($old->fingerprint() !== $definition->fingerprint()) {
                throw new CorruptHistoryException();
            }
            return;
        }
        $this->retain($definition);
        $this->sql->insert("sis_section_offering_versions", [
            "id" => $definition->section->value,
            "publication_id" => $definition->publication->value,
            "stream_version" => 1,
        ]);
    }

    public function enrollmentVersion(EntityId $enrollment): ?EntityId
    {
        $row =
        $this->sql->rows("sis_enrollment_section_versions", ["id" => $enrollment->value], 1)[0] ?? null;
        return $row === null ? null : new EntityId($row["publication_id"]);
    }

    public function retainEnrollmentVersion(EntityId $enrollment, ?EntityId $publication): void
    {
        $old = $this->enrollmentVersion($enrollment);
        if ($old !== null) {
            if ($old->value !== $publication?->value) {
                throw new CorruptHistoryException();
            }
            return;
        }
        if ($publication !== null) {
            $this->sql->insert("sis_enrollment_section_versions", [
                "id" => $enrollment->value,
                "publication_id" => $publication->value,
            ]);
        }
    }

    public function find(EntityId $publication): ?PublishedSectionDefinition
    {
        $row = $this->sql->rows("sis_section_versions", ["id" => $publication->value], 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        return $this->hydrate($publication, $row);
    }

    /** @return array{status: string, latest: ?PublishedSectionDefinition} */
    public function latest(EntityId $section): array
    {
        $q = $this->sql->quote(...);
        // One statement gives a consistent head/reference pair without section write locks or replay.
        $row =
        $this->sql->fetch(
            "SELECT v.*, h." .
                    $q("version") .
                    " AS " .
                    $q("head_version") .
                    " FROM " .
                    $q("sis_history_streams") .
                    " h LEFT JOIN " .
                    $q("sis_section_versions") .
                    " v" .
                    " ON v." .
                    $q("section_id") .
                    " = h." .
                    $q("aggregate_id") .
                    " AND v." .
                    $q("stream_version") .
                    " = h." .
                    $q("version") .
                    " WHERE h." .
                    $q("stream_type") .
                    " = ? AND h." .
                    $q("aggregate_id") .
                    " = ?",
            ["section_publication", $section->value],
            1,
        )[0] ?? null;
        if ($row === null || $this->integer($row["head_version"]) === 0) {
            return ["status" => "unpublished", "latest" => null];
        }
        if ($row["id"] === null) {
            return ["status" => "unavailable", "latest" => null];
        }
        $definition = $this->hydrate(new EntityId($row["id"]), $row);
        if ($definition->section->value !== $section->value) {
            throw new CorruptHistoryException();
        }
        return ["status" => "ready", "latest" => $definition];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(EntityId $publication, array $row): PublishedSectionDefinition
    {
        try {
            $facts = array_intersect_key($row, array_flip(SectionDefinitionV1::FIELDS));
            $facts["capacity"] = $this->integer($facts["capacity"]);
            $facts["fee_minor"] = $this->integer($facts["fee_minor"]);
            if ($this->integer($row["schema_version"]) !== 1) {
                throw new CorruptHistoryException();
            }
            return new PublishedSectionDefinition(
                new EntityId($row["section_id"]),
                $publication,
                $this->integer($row["stream_version"]),
                new SectionDefinitionV1($facts),
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
                ProtectedEventCodec::canonical($existing->describe()) !==
                ProtectedEventCodec::canonical($definition->describe())
            ) {
                throw new CorruptHistoryException();
            }
            return;
        }
        $this->sql->insert(
            "sis_section_versions",
            [
                "id" => $definition->publication->value,
                "section_id" => $definition->section->value,
                "stream_version" => $definition->position,
                "schema_version" => 1,
            ] + $definition->definition->data,
        );
    }

    private function integer(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || (string) (int) $value !== $value)) {
            throw new CorruptHistoryException();
        }
        return (int) $value;
    }
}
