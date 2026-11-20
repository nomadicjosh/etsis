<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Registration\Repository\RegistrationWindowRepository;
use Domain\Registration\ValueObject\RegistrationWindowDefinition;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlRegistrationWindowRepository implements RegistrationWindowRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function term(EntityId $term): array
    {
        return $this->sql->one("sis_terms", ["id" => $term->value]);
    }
    public function lock(EntityId $term): void
    {
        $this->sql->lock("sis_terms", $term->value, "id");
    }
    public function current(EntityId $term): ?array
    {
        return $this->sql->current("sis_reg_windows", $term->value, "term_id");
    }
    public function publish(
        EntityId $term,
        array $definition,
        array $reference,
    ): void {
        $window = new RegistrationWindowDefinition($definition)->window();
        $values = [
            "opens_at" => $window->dates->start->format("Y-m-d H:i:s"),
            "closes_at" => $window->dates->end->format("Y-m-d H:i:s"),
            "course_limit" => $window->courseLimit,
            "publication_id" => $reference["publication_id"],
            "policy_version" => $reference["version"],
            "definition_hash" => $reference["definition_hash"],
        ];
        if ($this->current($term) === null) {
            $this->sql->insert(
                "sis_reg_windows",
                [
                    "id" => EntityId::generate()->value,
                    "term_id" => $term->value,
                ] + $values,
            );
        } else {
            $this->sql->update("sis_reg_windows", $values, [
                "term_id" => $term->value,
            ]);
        }
    }
}
