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

    /** @return array{version_id:string, definition:array<string,mixed>}|null */
    public function resolve(EntityId $program, EntityId $year): ?array
    {
        $current = $this->programs->current($program, $year);
        $id = $current === null ? null : new EntityId($current["id"]);
        $definition = $this->published->current($program, $year, $id);
        return $definition === null || $id === null
        ? null
        : [
                "version_id" => $id->value,
                "definition" => $definition->describe(),
            ];
    }
}
