<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\Student;

final readonly class StudentActivityService
{
    public function __construct(
        private StudentRecordRepository $students,
        private AdmissionsRepository $admissions,
        private StudentActivityLedger $ledger,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** @return array<string,mixed> */
    public function review(
        EntityId $id,
        bool $active,
        string $on,
        #[\SensitiveParameter] string $reason,
        string $revision,
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
        $reason = trim($reason);
        return $this->transactions->run(function () use (
            $id,
            $active,
            $on,
            $reason,
            $revision,
            $actor,
        ): array {
            $student = $this->lock($id, $actor);
            $this->revision($student, $revision);
            $preview = $this->ledger->preview($student, $active, $on);
            if ($preview["active_on_date"] === $active) {
                throw new ValidationException([
                    [
                        "field" => "active",
                        "message" =>
                            "The selected status already applies on this date. Choose a different status or date.",
                    ],
                ]);
            }
            $operation = EntityId::generate();
            $token = $this->cipher->seal(
                json_encode(
                    [
                        "purpose" => "student-activity.v1",
                        "student" => $id->value,
                        "actor" => $actor->value,
                        "operation" => $operation->value,
                        "active" => $active,
                        "on" => $on,
                        "reason" => $reason,
                        "revision" => $revision,
                        "version" => $preview["version"],
                        "expires" => $this->clock->now()->getTimestamp() + 1200,
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            );
            return $preview + ["review_token" => $token];
        });
    }
    /** @return array<string,mixed> */
    public function commit(EntityId $id, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->access->requirePermission("sis:registration:manage");
        try {
            if (strlen($token) > 100000) {
                throw new \InvalidArgumentException();
            }
            $p = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($p) ||
                count($p) !== 10 ||
                ($p["purpose"] ?? null) !== "student-activity.v1" ||
                ($p["student"] ?? null) !== $id->value ||
                ($p["actor"] ?? null) !== $actor->value ||
                !is_bool($p["active"] ?? null) ||
                !is_string($p["on"] ?? null) ||
                !is_string($p["reason"] ?? null) ||
                !is_string($p["revision"] ?? null) ||
                !is_int($p["version"] ?? null) ||
                !is_int($p["expires"] ?? null) ||
                !is_string($p["operation"] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
            $operation = new EntityId($p["operation"]);
        } catch (\Throwable) {
            throw new ValidationException([
                ["field" => "review_token", "message" => "Review this activity decision again."],
            ]);
        }
        return $this->transactions->run(function () use ($id, $actor, $operation, $p): array {
            $student = $this->lock($id, $actor);
            $previous = $this->ledger->receipt($student, $operation, $actor);
            if ($previous !== null) {
                return $previous;
            }
            if ($p["expires"] < $this->clock->now()->getTimestamp()) {
                throw new ConcurrentModificationException("The review expired. Review this decision again.");
            }
            $this->revision($student, $p["revision"]);
            $current = $this->ledger->preview($student, $p["active"], $p["on"]);
            if ($current["version"] !== $p["version"]) {
                throw new ConcurrentModificationException("The activity timeline changed. Review it again.");
            }
            $result = $this->ledger->decide(
                $student,
                $p["active"],
                $p["on"],
                "registrar",
                null,
                $operation,
                $actor,
                $p["reason"],
            );
            $this->audit->record($actor, "student.activity_decided", $id);
            return $result;
        });
    }
    /** @return array<string,mixed> */
    public function history(EntityId $id, ?string $cursor = null): array
    {
        $actor = $this->access->requirePermission("sis:registration:manage");
        return $this->transactions->run(function () use ($id, $cursor, $actor): array {
            $student = $this->lock($id, $actor);
            $page = $this->ledger->timeline($student, $cursor);
            $this->audit->record($actor, "student.activity_history_read", $id);
            return $page;
        });
    }
    private function lock(EntityId $id, EntityId $actor): Student
    {
        $this->students->lock($id);
        if ($this->access->requirePermission("sis:registration:manage")->value !== $actor->value) {
            throw new AccessDeniedException("The activity actor changed.");
        }
        return $this->admissions->student($id);
    }
    private function revision(Student $student, string $expected): void
    {
        $current = hash(
            "sha256",
            json_encode(
                [
                    $student->id->value,
                    $student->personId->value,
                    $student->programId->value,
                    $student->entryTermId->value,
                    $student->active,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
        if (!hash_equals($current, $expected)) {
            throw new ConcurrentModificationException(
                "The student record changed. Reload it before reviewing.",
            );
        }
    }
}
