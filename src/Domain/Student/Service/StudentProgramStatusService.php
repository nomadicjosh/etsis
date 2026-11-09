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
use Domain\Student\ValueObject\StudentProgramStatusSchema;

final readonly class StudentProgramStatusService
{
    public function __construct(
        private StudentRecordRepository $students,
        private AdmissionsRepository $admissions,
        private StudentProgramStatusLedger $ledger,
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
        EntityId $id,
        bool $active,
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
            $id,
            $active,
            $revision,
            $reason,
            $actor,
        ): array {
            [$s, $p] = $this->lock($student, $id, $actor);
            if (!hash_equals($this->revision($p), $revision)) {
                throw new ConcurrentModificationException(
                    "The program assignment changed. Reload it before reviewing.",
                );
            }
            $status = $active ? "active" : "closed";
            $this->allowed($s, $p, $status);
            $version = $this->ledger->verify($p);
            $token = $this->cipher->seal(
                json_encode(
                    [
                        "purpose" => "student-program-status.v1",
                        "student" => $student->value,
                        "assignment" => $id->value,
                        "actor" => $actor->value,
                        "operation" => EntityId::generate()->value,
                        "status" => $status,
                        "reason" => trim($reason),
                        "context" => $this->context($s, $p, $version),
                        "expires" => $this->clock->now()->getTimestamp() + 1200,
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            );
            return [
                "before" => $p->status,
                "after" => $status,
                "program" => $this->students->program($p->programId),
                "entry_term" => $this->students->term($p->entryTermId),
                "review_token" => $token,
                "effective_on" => $this->clock->now()->format("Y-m-d"),
                "earlier_history_unknown" => true,
            ];
        });
    }
    /** @return array<string,mixed> */
    public function commit(EntityId $student, EntityId $id, #[\SensitiveParameter] string $token): array
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
                ($p["purpose"] ?? null) !== "student-program-status.v1" ||
                ($p["student"] ?? null) !== $student->value ||
                ($p["assignment"] ?? null) !== $id->value ||
                ($p["actor"] ?? null) !== $actor->value ||
                !in_array($p["status"] ?? null, ["active", "closed"], true) ||
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
                ["field" => "review_token", "message" => "Review this program decision again."],
            ]);
        }
        return $this->transactions->run(function () use ($student, $id, $actor, $operation, $p): array {
            [$s, $assignment] = $this->lock($student, $id, $actor);
            $receipt = $this->ledger->receipt($assignment, $operation, $actor);
            if ($receipt !== null) {
                return $receipt;
            }
            $version = $this->ledger->verify($assignment);
            if (
                $p["expires"] < $this->clock->now()->getTimestamp() ||
                !hash_equals($p["context"], $this->context($s, $assignment, $version))
            ) {
                throw new ConcurrentModificationException(
                    "The program review expired or its context changed. Review it again.",
                );
            }
            $this->allowed($s, $assignment, $p["status"]);
            $result = $this->ledger->change($assignment, $p["status"], $operation, $actor, $p["reason"]);
            $this->audit->record(
                $actor,
                $p["status"] === "active" ? "student.program_reopened" : "student.program_closed",
                $student,
            );
            return $result;
        });
    }
    /** @return array<string,mixed> */
    public function history(EntityId $student, EntityId $id, ?string $cursor = null): array
    {
        $actor = $this->access->requirePermission("sis:registration:manage");
        return $this->transactions->run(function () use ($student, $id, $cursor, $actor): array {
            [, $p] = $this->lock($student, $id, $actor);
            $page = $this->ledger->timeline($p, $cursor);
            $this->audit->record($actor, "student.program_status_history_read", $id);
            return $page;
        });
    }
    /** @return array{Student,StudentProgram} */
    private function lock(EntityId $student, EntityId $id, EntityId $actor): array
    {
        $this->students->lock($student);
        if ($this->access->requirePermission("sis:registration:manage")->value !== $actor->value) {
            throw new AccessDeniedException("The program decision actor changed.");
        }
        $p = $this->students->assignment($id);
        if ($p->studentId->value !== $student->value) {
            throw new RecordNotFoundException("Program assignment not found.");
        }
        return [$this->admissions->student($student), $p];
    }
    private function allowed(Student $student, StudentProgram $p, string $status): void
    {
        if ($p->status === $status) {
            throw new ValidationException([
                ["field" => "active", "message" => "This assignment already has the selected status."],
            ]);
        }
        if ($status === "closed" && $p->programId->value === $student->programId->value) {
            throw new ValidationException([
                [
                    "field" => "active",
                    "message" => "Choose another primary program before closing this assignment.",
                ],
            ]);
        }
        if ($status === "active") {
            $this->ledger->assertNoActiveProgram($student->id, $p->programId, $p->id);
        }
    }
    private function revision(StudentProgram $p): string
    {
        return hash(
            "sha256",
            json_encode(
                [
                    $p->id->value,
                    $p->studentId->value,
                    $p->programId->value,
                    $p->entryTermId->value,
                    $p->status,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
    private function context(Student $student, StudentProgram $p, int $version): string
    {
        return hash(
            "sha256",
            json_encode(
                [
                    StudentProgramStatusSchema::facts($p),
                    $version,
                    $student->personId->value,
                    $student->programId->value,
                    $student->entryTermId->value,
                    $student->active,
                    $this->clock->now()->format("Y-m-d"),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
