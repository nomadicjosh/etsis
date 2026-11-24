<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\Repository;

use Domain\Shared\ValueObject\EntityId;
use Domain\Catalog\ValueObject\PublishedCourseDefinition;

interface AcademicCreditRepository
{
    /** @return array<string,mixed>|null */
    public function mapping(EntityId $credit): ?array;
    /** @return array<string,mixed>|null */
    public function forEnrollment(EntityId $enrollment): ?array;
    /** @return array<string,mixed>|null */
    public function projection(EntityId $credit): ?array;
    /** @param array<string,mixed> $facts */
    public function project(array $facts, EntityId $section, ?int $expectedVersion): void;
    /** Establish or verify routing for an actual observation without changing the academic projection.
     * @param array<string, mixed> $facts */
    public function track(array $facts, EntityId $section): void;
    /** @return array<string,mixed> */
    public function section(EntityId $section): array;
    public function acceptCourse(
        EntityId $credit,
        PublishedCourseDefinition $definition,
        ?EntityId $previous,
        EntityId $previousCourse,
    ): void;
    /** @return array<string,mixed>|null */
    public function enrollment(EntityId $enrollment): ?array;
    /** @return array{id:string,code:string} */
    public function term(EntityId $term): array;
    /** @return array{term_id:string,term_code:string,decision_id:string}|null */
    public function postingTerm(EntityId $credit): ?array;
    /** @param array{term_id:string,term_code:string,decision_id:string} $reference
     * @param array{term_id:string,term_code:string,decision_id:string}|null $previous */
    public function recordPostingTerm(EntityId $credit, array $reference, ?array $previous): void;
    public function earnedCeus(EntityId $credit): ?array;
    public function ceuRecords(EntityId $student, ?EntityId $after = null): array;
    public function recordEarnedCeus(EntityId $credit, array $award): void;
    public function lockStudent(EntityId $student): void;
    public function lockSection(EntityId $section): void;
}
