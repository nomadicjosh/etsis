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
    public function calendar(EntityId $term): array
    {
        return $this->sql->one("sis_terms", ["id" => $term->value]);
    }
    public function lockTerm(EntityId $term): void
    {
        $this->sql->lock("sis_terms", $term->value, "id");
    }
    public function find(EntityId $term): ?array
    {
        $r = $this->sql->rows("sis_term_drop_policies", ["term_id" => $term->value], 1)[0] ?? null;
        return $r === null
        ? null
        : [
                "version" => (int) $r["version"],
                "windows" => json_decode($r["windows"], true, 16, JSON_THROW_ON_ERROR),
            ];
    }
    public function save(EntityId $term, int $version, array $windows): void
    {
        $current = $this->find($term);
        if (($current["version"] ?? -1) !== $version) {
            throw new ConcurrentModificationException("Reload the drop policy before saving.");
        }
        $values = ["version" => $version + 1, "windows" => json_encode($windows, JSON_THROW_ON_ERROR)];
        if ($current === null) {
            $this->sql->insert(
                "sis_term_drop_policies",
                ["id" => EntityId::generate()->value, "term_id" => $term->value] + $values,
            );
        } elseif (
            $this->sql->update("sis_term_drop_policies", $values, [
                "term_id" => $term->value,
                "version" => $version,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException("The drop policy changed.");
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
