<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use Domain\Catalog\Repository\CatalogYearRepository;
use Domain\Catalog\Service\CatalogEnrollment;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\StudentProgram;
use Domain\Student\ValueObject\StudentProgramStatusSchema;

/** Pure proposals, followed by verification of the cohort actually bound on confirmation. */
final readonly class ProgramEntryContext
{
    public function __construct(
        private StudentRecordRepository $students,
        private CatalogYearRepository $catalogs,
        private CatalogEnrollment $enrollment,
    ) {
    }
    /** @param array<string,mixed> $source
     * @return array<string,mixed> */
    public function initialize(StudentProgram $assignment, EntityId $actor, array $source): array
    {
        $this->enrollment->initialize($assignment, $actor);
        $source["cohort"] = $this->acceptedCohort($assignment->id, $assignment->programId, $source["cohort"]);
        return $source;
    }
    /** @return array{program_code:string,program_title:string,entry_term_code:string,cohort:?array{year_id:string,year_code:string,catalog_id:?string,revision:int}} */
    public function proposal(EntityId $program, EntityId $term): array
    {
        $programRow = $this->students->program($program);
        $termRow = $this->students->term($term);
        $source = [
            "program_code" => $programRow["code"],
            "program_title" => $programRow["title"],
            "entry_term_code" => $termRow["code"],
            "cohort" => null,
        ];
        StudentProgramStatusSchema::source($source);
        $source["cohort"] = $this->catalogs->plan($program, $term);
        return $source;
    }
    /** @param array{year_id:string,year_code:string,catalog_id:?string,revision:int}|null $plan
     * @return array{catalog_id:string,year_id:string,year_code:string,revision:int}|null */
    private function acceptedCohort(EntityId $assignment, EntityId $program, ?array $plan): ?array
    {
        $binding = $this->catalogs->binding($assignment);
        if ($plan === null) {
            if ($binding !== null) {
                throw new CorruptHistoryException();
            }
            return null;
        }
        if ($binding === null) {
            throw new CorruptHistoryException();
        }
        $id = new EntityId($binding["catalog_id"]);
        $release = $this->catalogs->release($id);
        if (
            ($plan["catalog_id"] !== null && $plan["catalog_id"] !== $id->value) ||
            $release["program_id"] !== $program->value ||
            $release["year_id"] !== $plan["year_id"] ||
            $release["year_code"] !== $plan["year_code"] ||
            $release["revision"] !== $plan["revision"]
        ) {
            throw new CorruptHistoryException();
        }
        return [
            "catalog_id" => $id->value,
            "year_id" => $plan["year_id"],
            "year_code" => $plan["year_code"],
            "revision" => $plan["revision"],
        ];
    }
}
