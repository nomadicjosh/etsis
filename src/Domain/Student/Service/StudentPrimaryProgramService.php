<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
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

final readonly class StudentPrimaryProgramService
{
    public function __construct(
        private StudentRecordRepository $students,
        private AdmissionsRepository $admissions,
        private StudentPrimaryProgramLedger $ledger,
        private StudentProgramStatusLedger $statuses,
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
        EntityId $assignment,
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
            $assignment,
            $revision,
            $reason,
            $actor,
        ): array {
            $s = $this->lock($student, $actor);
            if (!hash_equals($this->revision($s), $revision)) {
                throw new ConcurrentModificationException(
                    "Reload the student record before reviewing its primary program.",
                );
            }
            $version = $this->ledger->verify($s);
            $p = $this->target($s, $assignment);
            $selection = $this->ledger->selection($p);
            $token = $this->cipher->seal(
                json_encode(
                    [
                        "purpose" => "student-primary-program.v1",
                        "student" => $student->value,
                        "assignment" => $assignment->value,
                        "actor" => $actor->value,
                        "operation" => EntityId::generate()->value,
                        "reason" => trim($reason),
                        "context" => $this->context($s, $p, $selection, $version),
                        "expires" => $this->clock->now()->getTimestamp() + 1200,
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            );
            return [
                "before" => $this->ledger->current($s),
                "after" => $selection,
                "review_token" => $token,
                "effective_on" => $this->clock->now()->format("Y-m-d"),
                "earlier_history_unknown" => true,
            ];
        });
    }
    /** @return array<string,mixed> */
    public function commit(
        EntityId $student,
        EntityId $assignment,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->access->requirePermission("sis:registration:manage");
        try {
            if (strlen($token) > 100000) {
                throw new \InvalidArgumentException();
            }
            $p = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($p) ||
                count($p) !== 8 ||
                ($p["purpose"] ?? null) !== "student-primary-program.v1" ||
                ($p["student"] ?? null) !== $student->value ||
                ($p["assignment"] ?? null) !== $assignment->value ||
                ($p["actor"] ?? null) !== $actor->value ||
                !is_string($p["reason"] ?? null) ||
                !is_string($p["context"] ?? null) ||
                !is_int($p["expires"] ?? null) ||
                !is_string($p["operation"] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
            $operation = new EntityId($p["operation"]);
        } catch (\Throwable) {
            throw new ValidationException([
                ["field" => "review_token", "message" => "Review this primary-program decision again."],
            ]);
        }
        return $this->transactions->run(function () use (
            $student,
            $assignment,
            $actor,
            $operation,
            $p,
        ): array {
            $s = $this->lock($student, $actor);
            $receipt = $this->ledger->receipt($s, $operation, $actor);
            if ($receipt !== null) {
                return $receipt;
            }
            $version = $this->ledger->verify($s);
            $target = $this->target($s, $assignment);
            $selection = $this->ledger->selection($target);
            if (
                $p["expires"] < $this->clock->now()->getTimestamp() ||
                !hash_equals($p["context"], $this->context($s, $target, $selection, $version))
            ) {
                throw new ConcurrentModificationException(
                    "The primary-program review expired or its context changed. Review it again.",
                );
            }
            $result = $this->ledger->select($s, $target, $selection, $operation, $actor, $p["reason"]);
            $this->audit->record($actor, "student.primary_program_changed", $student);
            return $result;
        });
    }
    /** @return array<string,mixed> */
    public function history(EntityId $student, ?string $cursor = null): array
    {
        $actor = $this->access->requirePermission("sis:registration:manage");
        return $this->transactions->run(function () use ($student, $cursor, $actor): array {
            $s = $this->lock($student, $actor);
            $page = $this->ledger->timeline($s, $cursor);
            $this->audit->record($actor, "student.primary_program_history_read", $student);
            return $page;
        });
    }
    private function lock(EntityId $student, EntityId $actor): Student
    {
        $this->students->lock($student);
        if ($this->access->requirePermission("sis:registration:manage")->value !== $actor->value) {
            throw new AccessDeniedException("The primary selection actor changed.");
        }
        return $this->admissions->student($student);
    }
    private function target(Student $student, EntityId $assignment): StudentProgram
    {
        $p = $this->students->assignment($assignment);
        if ($p->studentId->value !== $student->id->value) {
            throw new RecordNotFoundException("Program assignment not found.");
        }
        $this->statuses->verify($p);
        if ($p->status !== "active") {
            throw new ValidationException([
                ["field" => "assignment_id", "message" => "Choose an active program assignment."],
            ]);
        }
        $this->statuses->assertNoActiveProgram($student->id, $p->programId, $p->id);
        if ($p->programId->value === $student->programId->value) {
            throw new ValidationException([
                ["field" => "assignment_id", "message" => "This assignment is already primary."],
            ]);
        }
        return $p;
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
     * @param array<string,mixed> $selection
     * @throws JsonException
     */
    private function context(Student $s, StudentProgram $p, array $selection, int $version): string
    {
        return hash(
            "sha256",
            json_encode(
                [
                    $this->revision($s),
                    $version,
                    $selection,
                    $this->statuses->verify($p),
                    $this->clock->now()->format("Y-m-d"),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
