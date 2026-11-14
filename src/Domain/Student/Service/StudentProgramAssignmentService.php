<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogYearRepository;
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
use JsonException;
use Qubus\Exception\Data\TypeException;

final readonly class StudentProgramAssignmentService
{
    public function __construct(
        private StudentRecordRepository $students,
        private AdmissionsRepository $admissions,
        private StudentProgramStatusLedger $ledger,
        private StudentPrimaryProgramLedger $primary,
        private StudentActivityLedger $activity,
        private CatalogYearRepository $catalogs,
        private ProgramEntryContext $entry,
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
            $source = $this->entry->proposal($program, $term);
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
    public function commit(
        EntityId $student,
        #[\SensitiveParameter] string $token,
        ?EntityId $correlation = null,
    ): array {
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
        return $this->transactions->run(function () use (
            $student,
            $program,
            $term,
            $id,
            $p,
            $actor,
            $correlation
        ): array {
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
            $source = $this->entry->proposal($program, $term);
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
            $source = $this->entry->initialize($assignment, $actor, $source);
            $result = $this->ledger->assigned($assignment, $source, $actor, $p["reason"], $correlation);
            $this->audit->record($actor, "student.program_assigned", $student);
            return $result;
        });
    }
    /**
     * One reviewed replacement of the current pursuit, using the existing assignment/cohort initializer.
     * @return array<string,mixed>
     */
    public function reviewChange(
        EntityId $student,
        EntityId $program,
        EntityId $term,
        string $revision,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->access->requirePermission('sis:registration:manage');
        return $this->transactions->run(function () use ($student, $program, $term, $revision, $reason, $actor): array {
            $this->students->lock($student);
            $assignment = $this->review($student, $program, $term, $revision, $reason);
            $s = $this->admissions->student($student);
            $before = $this->primary->current($s);
            $old = $this->students->assignment(new EntityId($before['assignment_id']));
            if ($old->studentId->value !== $student->value || $old->status !== 'active') {
                throw new CorruptHistoryException();
            }
            $inner = json_decode($this->cipher->open($assignment['review_token']), true, 8, JSON_THROW_ON_ERROR);
            $data = ['purpose' => 'student-program-change', 'student' => $student->value, 'actor' => $actor->value,
                'original_assignment_id' => $old->id->value, 'original_version' => $this->ledger->verify($old),
                'primary_version' => $this->primary->verify($s), 'assignment_id' => $inner['assignment'],
                'assignment_review' => $assignment['review_token'], 'operation' => EntityId::generate()->value,
                'expires' => $inner['expires']];
            $this->changeActor($actor);
            return ['before' => $before, 'source' => $assignment['source'], 'student_active' => $s->active,
                'effective_on' => $assignment['effective_on'],
                'review_token' => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR))];
        });
    }

    /**
     * Creates/selects the new pursuit and closes the old pursuit together; no withdrawal or credit rewrite.
     * @return array<string,mixed>
     */
    public function changeProgram(EntityId $student, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->access->requirePermission('sis:registration:manage');
        try {
            if (strlen($token) > 100000) {
                throw new \InvalidArgumentException();
            }
            $p = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($p) || count($p) !== 10 || ($p['purpose'] ?? null) !== 'student-program-change' ||
                ($p['student'] ?? null) !== $student->value || ($p['actor'] ?? null) !== $actor->value ||
                !is_int($p['expires'] ?? null) || !is_int($p['original_version'] ?? null) ||
                !is_int($p['primary_version'] ?? null) || !is_string($p['assignment_review'] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
            $oldId = new EntityId($p['original_assignment_id']);
            $newId = new EntityId($p['assignment_id']);
            $operation = new EntityId($p['operation']);
        } catch (\Throwable) {
            throw new ValidationException([
                ['field' => 'review_token', 'message' => 'Review this program change again.']
            ]);
        }
        return $this->transactions->run(function () use ($student, $p, $oldId, $newId, $operation, $actor): array {
            $this->students->lock($student);
            $this->changeActor($actor);
            $s = $this->admissions->student($student);
            $this->activity->verify($s);
            $old = $this->students->assignment($oldId);
            if ($old->studentId->value !== $student->value) {
                throw new CorruptHistoryException();
            }
            $primaryOperation = \Domain\Gradebook\ValueObject\PublishedGradeId::forVersion(
                $operation,
                'program.change.primary'
            );
            $receipt = $this->ledger->receipt($old, $operation, $actor);
            if ($receipt !== null) {
                $new = $this->students->assignment($newId);
                if (
                    $new->studentId->value !== $student->value ||
                    $this->ledger->receipt($new, $newId, $actor) === null ||
                    $this->primary->receipt($s, $primaryOperation, $actor) === null
                ) {
                    throw new CorruptHistoryException();
                }
                $this->changeActor($actor);
                return self::changeResult($receipt, $oldId, $newId);
            }
            if (
                $p['expires'] <= $this->clock->now()->getTimestamp() || $old->status !== 'active' ||
                $this->ledger->verify($old) !== $p['original_version'] ||
                $this->primary->verify($s) !== $p['primary_version'] ||
                $this->primary->current($s)['assignment_id'] !== $oldId->value
            ) {
                throw new ConcurrentModificationException(
                    'The current pursuit changed or this review expired. Review again.'
                );
            }
            $result = $this->commit($student, $p['assignment_review'], $operation);
            if ($result['assignment_id'] !== $newId->value) {
                throw new CorruptHistoryException();
            }
            $new = $this->students->assignment($newId);
            $inner = json_decode($this->cipher->open($p['assignment_review']), true, 8, JSON_THROW_ON_ERROR);
            $this->primary->select(
                $s,
                $new,
                $this->primary->selection($new),
                $primaryOperation,
                $actor,
                $inner['reason'],
                $operation
            );
            $receipt = $this->ledger->change($old, 'closed', $operation, $actor, $inner['reason'], $newId, $operation);
            $this->audit->record($actor, 'student.program_changed', $student);
            $this->changeActor($actor);
            return self::changeResult($receipt, $oldId, $newId);
        });
    }

    private function changeActor(EntityId $actor): void
    {
        if ($this->access->requirePermission('sis:registration:manage')->value !== $actor->value) {
            throw new AccessDeniedException('The program-change actor changed.');
        }
    }

    /**
     * @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    private static function changeResult(array $receipt, EntityId $old, EntityId $new): array
    {
        return ['operation_id' => $receipt['operation_id'], 'from_assignment_id' => $old->value,
            'assignment_id' => $new->value, 'status' => 'active', 'previous_status' => 'closed',
            'effective_at' => $receipt['effective_at']];
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
    /**
     * @throws JsonException
     */
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
    /**
     * @param Student $s
     * @param array<string,mixed> $source
     * @return string
     * @throws JsonException
     * @throws TypeException
     */
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
