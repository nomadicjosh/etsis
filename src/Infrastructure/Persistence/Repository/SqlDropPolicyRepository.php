<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Registration\Repository\DropPolicyRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlDropPolicyRepository implements DropPolicyRepository
{
    public function __construct(
        private SqlRecords $sql,
        private \Domain\Gradebook\Service\AcademicGradeCatalog $grades,
    ) {
    }
    public function calendar(EntityId $term, ?EntityId $section = null): array
    {
        $calendar = $this->sql->current("sis_terms", $term->value) ??
        throw new \Domain\Shared\Exception\RecordNotFoundException();
        if ($section !== null) {
            $row = $this->sql->one('sis_sections', ['id' => $section->value]);
            if ($row['term_id'] !== $term->value) {
                throw new ConcurrentModificationException('The section term changed. Reload its setup.');
            }
            $calendar += ['section_id' => $section->value, 'section_code' => $row['code']];
        }
        return $calendar;
    }
    public function lockTerm(EntityId $term, ?EntityId $section = null): void
    {
        if ($section !== null) {
            $this->sql->lock('sis_sections', $section->value, 'id');
        }
        $this->sql->lock("sis_terms", $term->value, "id");
        $this->calendar($term);
    }
    public function find(EntityId $term, ?EntityId $section = null): ?array
    {
        $r = $this->sql->current(
            $section === null ? "sis_term_drop_policies" : 'sis_section_drop_policies',
            ($section ?? $term)->value,
            $section === null ? "term_id" : 'section_id',
        );
        return $r === null
        ? null
        : [
                "version" => (int) $r["version"],
                "windows" => json_decode(
                    $r["windows"],
                    true,
                    16,
                    JSON_THROW_ON_ERROR,
                ),
                "publication_id" => $r["publication_id"],
                "calendar" =>
                    $r["calendar_snapshot"] === null
                        ? null
                        : json_decode(
                            $r["calendar_snapshot"],
                            true,
                            16,
                            JSON_THROW_ON_ERROR,
                        ),
            ];
    }
    public function save(
        EntityId $term,
        int $version,
        array $windows,
        EntityId $publication,
        array $calendar,
        ?EntityId $section = null,
    ): void {
        $table = $section === null ? 'sis_term_drop_policies' : 'sis_section_drop_policies';
        $key = $section === null ? 'term_id' : 'section_id';
        $id = ($section ?? $term)->value;
        $current = $this->find($term, $section);
        if (($current["version"] ?? -1) !== $version) {
            throw new ConcurrentModificationException(
                "Reload the drop policy before saving.",
            );
        }
        $values = [
            "version" => $version + 1,
            "windows" => json_encode($windows, JSON_THROW_ON_ERROR),
            "publication_id" => $publication->value,
            "calendar_snapshot" => json_encode($calendar, JSON_THROW_ON_ERROR),
        ];
        if ($current === null) {
            $this->sql->insert(
                $table,
                ["id" => EntityId::generate()->value, $key => $id] + $values,
            );
        } elseif (
            $this->sql->update($table, $values, [
                $key => $id,
                "version" => $version,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException(
                "The drop policy changed.",
            );
        }
    }
    public function grade(string $code, ?EntityId $student = null): ?array
    {
        $r = null;
        foreach ($this->grades->choices($student) as $candidate) {
            if ($candidate["code"] === $code) {
                if ($r !== null) {
                    throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
                }
                $r = $candidate;
            }
        }
        if ($r === null) {
            return null;
        }
        return [
            "id" => $r["id"],
            "code" => $r["code"],
            "points_milli" => (int) $r["points_milli"],
            "counts_gpa" => (bool) $r["counts_gpa"],
            "passes" => (bool) $r["passes"],
        ];
    }
}
