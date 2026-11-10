<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\Catalog\Repository\ProgramDefinitionRepository;
use Domain\Catalog\Service\PublishedProgramReader;
use Domain\Shared\ValueObject\EntityId;

/** Used under the program/year locks when reviewing a consequential publication. */
final readonly class RequirementProgramContext
{
    public function __construct(
        private ProgramDefinitionRepository $programs,
        private PublishedProgramReader $published,
    ) {
    }

    /** @return array{version_id:string, catalog_revision:int, academic_year_code:string, definition:array<string,mixed>}|null */
    public function resolve(EntityId $program, EntityId $year): ?array
    {
        $current = $this->programs->current($program, $year);
        $id = $current === null ? null : new EntityId($current["id"]);
        $definition = $this->published->current($program, $year, $id);
        $retained = $id === null ? null : $this->programs->retained($id);
        if (
            $definition !== null &&
            ($retained === null ||
                !is_int($retained["catalog_revision"]) ||
                !is_string($retained["academic_year_code"]))
        ) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        return $definition === null
        ? null
        : [
                "version_id" => $id->value,
                "catalog_revision" => $retained["catalog_revision"],
                "academic_year_code" => $retained["academic_year_code"],
                "definition" => $definition->describe(),
            ];
    }
}
