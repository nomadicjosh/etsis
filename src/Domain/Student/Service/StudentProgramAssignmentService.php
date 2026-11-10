<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogYearRepository;
use Domain\Catalog\Service\CatalogEnrollment;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\Student;
use Domain\Student\StudentProgram;
use Domain\Student\ValueObject\StudentProgramStatusSchema;

final readonly class StudentProgramAssignmentService
{
    public function __construct(
        private StudentRecordRepository $students,
        private AdmissionsRepository $admissions,
        private StudentProgramStatusLedger $ledger,
        private StudentPrimaryProgramLedger $primary,
        private StudentActivityLedger $activity,
        private CatalogYearRepository $catalogs,
        private CatalogEnrollment $enrollment,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** @return array<string,mixed> */
    public function review(
        EntityId $student,
        EntityId $program,
        EntityId $term,
        string $revision,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->access->requirePermission("sis:registration:manage");
        if (
            !mb_check_encoding($reason, "UTF-8") ||
            trim($reason) === "" ||
            mb_strlen($reason) > 10000 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            throw new ValidationException([
                ["field" => "reason", "message" => "Enter a reason of up to 10,000 characters."],
            ]);
        }
        return $this->transactions->run(function () use (
            $student,
            $program,
            $term,
            $revision,
            $reason,
            $actor,
        ): array {
            $s = $this->lock($student, $program, $term, $actor);
            if (!hash_equals($this->revision($s), $revision)) {
                throw new ConcurrentModificationException(
                    "Reload the student record before reviewing an additional program.",
                );
            }
            $this->ledger->assertCanAssign($student, $program, $term);
            $source = $this->proposal($program, $term);
            $token = $this->cipher->seal(
                json_encode(
                    [
                        "purpose" => "student-program-assignment.v1",
                        "student" => $student->value,
                        "program" => $program->value,
                        "term" => $term->value,
                        "assignment" => EntityId::generate()->value,
                        "actor" => $actor->value,
                        "reason" => trim($reason),
                        "context" => $this->context($s, $source),
                        "expires" => $this->clock->now()->getTimestamp() + 1200,
                    ],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
            );
            return [
                "source" => $source,
                "review_token" => $token,
                "effective_on" => $this->clock->now()->format("Y-m-d"),
            ];
        });
    }
    /** @return array<string,mixed> */
    public function commit(EntityId $student, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->access->requirePermission("sis:registration:manage");
        try {
            if (strlen($token) > 100000) {
                throw new \InvalidArgumentException();
            }
            $p = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($p) ||
                count($p) !== 9 ||
                ($p["purpose"] ?? null) !== "student-program-assignment.v1" ||
                ($p["student"] ?? null) !== $student->value ||
                ($p["actor"] ?? null) !== $actor->value ||
                !is_string($p["program"] ?? null) ||
                !is_string($p["term"] ?? null) ||
                !is_string($p["assignment"] ?? null) ||
                !is_string($p["reason"] ?? null) ||
                !is_string($p["context"] ?? null) ||
                !is_int($p["expires"] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
            $program = new EntityId($p["program"]);
            $term = new EntityId($p["term"]);
            $id = new EntityId($p["assignment"]);
        } catch (\Throwable) {
            throw new ValidationException([
                ["field" => "review_token", "message" => "Review this program assignment again."],
            ]);
        }
        return $this->transactions->run(function () use ($student, $program, $term, $id, $p, $actor): array {
            $s = $this->lock($student, $program, $term, $actor);
            try {
                $existing = $this->students->assignment($id);
            } catch (RecordNotFoundException) {
                $existing = null;
            }
            if ($existing !== null) {
                if (
                    $existing->studentId->value !== $student->value ||
                    $existing->programId->value !== $program->value ||
                    $existing->entryTermId->value !== $term->value
                ) {
                    throw new CorruptHistoryException();
                }
                return $this->ledger->receipt($existing, $id, $actor) ??
                    throw new ConcurrentModificationException(
                        "This assignment identity already exists. Review the record again.",
                    );
            }
            $source = $this->proposal($program, $term);
            if (
                $p["expires"] < $this->clock->now()->getTimestamp() ||
                !hash_equals($p["context"], $this->context($s, $source))
            ) {
                throw new ConcurrentModificationException(
                    "The assignment review expired or its context changed. Review it again.",
                );
            }
            $this->ledger->assertCanAssign($student, $program, $term);
            $assignment = new StudentProgram($id, $student, $program, $term);
            $this->students->addAssignment($assignment);
            $this->enrollment->initialize($assignment, $actor);
            $source["cohort"] = $this->acceptedCohort($id, $program, $source["cohort"]);
            $result = $this->ledger->assigned($assignment, $source, $actor, $p["reason"]);
            $this->audit->record($actor, "student.program_assigned", $student);
            return $result;
        });
    }
    private function lock(EntityId $student, EntityId $program, EntityId $term, EntityId $actor): Student
    {
        $this->students->lock($student);
        $this->catalogs->lockTerm($term);
        $this->catalogs->lockProgram($program);
        if ($this->access->requirePermission("sis:registration:manage")->value !== $actor->value) {
            throw new AccessDeniedException("The program assignment actor changed.");
        }
        $s = $this->admissions->student($student);
        $this->activity->verify($s);
        $this->primary->verify($s);
        return $s;
    }
    /** @return array{program_code:string,program_title:string,entry_term_code:string,cohort:?array{year_id:string,year_code:string,catalog_id:?string,revision:int}} */
    private function proposal(EntityId $program, EntityId $term): array
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
    private function revision(Student $s): string
    {
        return hash(
            "sha256",
            json_encode(
                [
                    $s->id->value,
                    $s->personId->value,
                    $s->programId->value,
                    $s->entryTermId->value,
                    $s->active,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
    /** @param array<string,mixed> $source */
    private function context(Student $s, array $source): string
    {
        return hash(
            "sha256",
            json_encode(
                [
                    $this->revision($s),
                    $this->primary->verify($s),
                    $this->activity->verify($s),
                    $source,
                    $this->clock->now()->format("Y-m-d"),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
