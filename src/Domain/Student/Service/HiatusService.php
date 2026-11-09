<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\HiatusRepository;
use Domain\Student\StudentHiatus;
use Domain\Student\ValueObject\HiatusImpact;

final readonly class HiatusService
{
    public function __construct(
        private HiatusRepository $repository,
        private AdmissionsRepository $admissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    public function create(
        StudentHiatus $hiatus,
        #[\SensitiveParameter] string $comment,
        ?HiatusImpact $impact = null,
    ): void {
        $actor = $this->access->requirePermission("sis:registration:manage");
        $request = [
            "code" => $hiatus->code->value,
            "starts_on" => $hiatus->dates->start->format("Y-m-d"),
            "ends_on" => $hiatus->dates->end?->format("Y-m-d"),
            "note" => $this->note($comment),
        ];
        if ($impact !== null) {
            $request["impact"] = $impact->request();
        }
        if ($hiatus->voided || $hiatus->version !== 0) {
            throw new ValidationException([
                ["field" => "code", "message" => "Create a new, unmodified hiatus record."],
            ]);
        }
        $this->transactions->run(function () use ($hiatus, $actor, $request, $impact): void {
            $this->repository->lockStudent($hiatus->studentId);
            $this->admissions->student($hiatus->studentId);
            if (($existing = $this->repository->find($hiatus->id)) !== null) {
                $this->forStudent($hiatus->studentId, $hiatus->id);
                if (!$this->repeated($hiatus->id, $hiatus->id, $actor, "created", $request)) {
                    throw new ConcurrentModificationException(
                        "The hiatus operation already belongs to another request.",
                    );
                }
                return;
            }
            $this->noOverlap($hiatus);
            $this->repository->add($hiatus);
            $this->record($hiatus->id, $hiatus, $actor, "created", $request, null, $impact?->result);
        });
    }

    /** @return array<string, mixed> */
    public function page(EntityId $studentId, ?EntityId $after = null): array
    {
        $this->access->requirePermission("sis:registration:manage");
        $student = $this->admissions->student($studentId);
        $person = $this->admissions->person($student->personId);
        $rows = $this->repository->page($studentId, $after, 51);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        return [
            "student" => [
                "id" => $studentId->value,
                "given_name" => $person->givenName,
                "family_name" => $person->familyName,
                "institutional_id" => $person->institutionalId->value,
            ],
            "today" => $this->clock->now()->format("Y-m-d"),
            "timezone" => $this->clock->now()->getTimezone()->getName(),
            "items" => array_map($this->describe(...), $rows),
            "next_after" => $more ? $rows[49]->id->value : null,
        ];
    }

    /** @return array<string, mixed> */
    public function view(EntityId $student, EntityId $id): array
    {
        $actor = $this->access->requirePermission("sis:registration:manage");
        $hiatus = $this->forStudent($student, $id);
        $initial = $this->repository->action($id) ?? throw new RecordNotFoundException("Hiatus comment not found.");
        $data = $this->payload($initial);
        $this->audit->record($actor, "student.hiatus_read", $id);
        return $this->describe($hiatus) + [
            "comment" => $data["request"]["note"],
            "created_at" => $initial["created_at"],
            "impact" => $data["impact_result"] ?? null,
            "return_operation_id" => $this->repository->returned($id)["id"] ?? null,
        ];
    }

    public function end(
        EntityId $student,
        EntityId $id,
        EntityId $operation,
        DateTimeImmutable $on,
        string $revision,
        #[\SensitiveParameter] string $reason,
    ): void {
        $this->amend(
            $student,
            $id,
            $operation,
            "end_changed",
            ["ends_on" => $on->format("Y-m-d"), "revision" => $revision, "note" => $this->note($reason)],
            $on,
        );
    }

    public function void(
        EntityId $student,
        EntityId $id,
        EntityId $operation,
        string $revision,
        #[\SensitiveParameter] string $reason,
    ): void {
        $this->amend(
            $student,
            $id,
            $operation,
            "voided",
            ["revision" => $revision, "note" => $this->note($reason)],
            null,
        );
    }

    /** @param array<string, mixed> $request */
    private function amend(
        EntityId $student,
        EntityId $id,
        EntityId $operation,
        string $action,
        #[\SensitiveParameter] array $request,
        ?DateTimeImmutable $on,
    ): void {
        $actor = $this->access->requirePermission("sis:registration:manage");
        $this->transactions->run(function () use ($student, $id, $operation, $action, $request, $on, $actor): void {
            $this->repository->lockStudent($student);
            $this->repository->lock($id);
            $current = $this->forStudent($student, $id);
            if ($this->repeated($operation, $id, $actor, $action, $request)) {
                return;
            }
            if (!hash_equals($current->revision(), $request["revision"])) {
                throw new ConcurrentModificationException("Reload the hiatus before changing it.");
            }
            $next = $on === null ? $current->void() : $current->withEnd($on);
            if (!$next->voided) {
                $this->noOverlap($next);
            }
            $this->repository->save($next, $current->version);
            $this->record($operation, $next, $actor, $action, $request, $current->dates->end?->format("Y-m-d"));
        });
    }

    /** @return array<string, mixed> */
    public function history(EntityId $student, EntityId $id, ?EntityId $after = null): array
    {
        $actor = $this->access->requirePermission("sis:registration:manage");
        $this->forStudent($student, $id);
        $rows = $this->repository->history($id, $after, 51);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        $items = [];
        foreach ($rows as $row) {
            $data = $this->payload($row);
            $items[] = [
                "id" => $row["id"],
                "action" => $row["action"],
                "actor_id" => $row["actor_id"],
                "actor_name" => trim((string) $row["actor_given_name"] . " " . (string) $row["actor_family_name"]),
                "created_at" => $row["created_at"],
                "note" => $data["request"]["note"],
                "previous_ends_on" => $data["previous_ends_on"],
                "new_ends_on" => $data["new_ends_on"],
                "return_result" => $data["return_result"] ?? null,
            ];
        }
        $this->audit->record($actor, "student.hiatus_history_read", $id);
        return ["items" => $items, "next_after" => $more ? (string) $rows[49]["id"] : null];
    }

    private function forStudent(EntityId $student, EntityId $id): StudentHiatus
    {
        $hiatus = $this->repository->find($id);
        if ($hiatus === null || $hiatus->studentId->value !== $student->value) {
            throw new RecordNotFoundException("Hiatus record not found.");
        }
        return $hiatus;
    }

    private function noOverlap(StudentHiatus $hiatus): void
    {
        if ($this->repository->overlaps($hiatus)) {
            throw new ValidationException([
                ["field" => "ends_on", "message" => "Dates overlap another non-voided record of this hiatus type."],
            ]);
        }
    }

    /** @param array<string, mixed> $request */
    private function repeated(
        EntityId $operation,
        EntityId $id,
        EntityId $actor,
        string $action,
        #[\SensitiveParameter] array $request,
    ): bool {
        $record = $this->repository->action($operation);
        if ($record === null) {
            return false;
        }
        if (
            $record["hiatus_id"] !== $id->value ||
            $record["actor_id"] !== $actor->value ||
            $record["action"] !== $action ||
            $this->payload($record)["request"] !== $request
        ) {
            throw new ConcurrentModificationException("The operation belongs to a different hiatus change.");
        }
        return true;
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed>|null $impactResult
     */
    private function record(
        EntityId $operation,
        StudentHiatus $hiatus,
        EntityId $actor,
        string $action,
        #[\SensitiveParameter] array $request,
        ?string $previousEnd,
        ?array $impactResult = null,
    ): void {
        $this->repository->addAction(
            $operation,
            $hiatus->id,
            $actor,
            $action,
            $this->cipher->seal(
                json_encode(
                    [
                        "impact_result" => $impactResult,
                        "request" => $request,
                        "previous_ends_on" => $previousEnd,
                        "new_ends_on" => $hiatus->dates->end?->format("Y-m-d"),
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
            $this->clock
                ->now()
                ->setTimezone(new DateTimeZone("UTC"))
                ->format(DATE_ATOM),
        );
        $this->audit->record($actor, "student.hiatus_" . $action, $hiatus->id);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function payload(#[\SensitiveParameter] array $row): array
    {
        return json_decode($this->cipher->open($row["encrypted_content"]), true, 16, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function describe(StudentHiatus $hiatus): array
    {
        return [
            "id" => $hiatus->id->value,
            "student_id" => $hiatus->studentId->value,
            "code" => $hiatus->code->value,
            "label" => $hiatus->code->label(),
            "starts_on" => $hiatus->dates->start->format("Y-m-d"),
            "ends_on" => $hiatus->dates->end?->format("Y-m-d"),
            "state" => $hiatus->state($this->clock->now()),
            "voided" => $hiatus->voided,
            "revision" => $hiatus->revision(),
        ];
    }

    private function note(#[\SensitiveParameter] string $value): string
    {
        $value = trim($value);
        if (
            !mb_check_encoding($value, "UTF-8") ||
            $value === "" ||
            mb_strlen($value) > 10000 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)
        ) {
            throw new ValidationException([
                ["field" => "note", "message" => "Enter a staff comment of up to 10,000 characters."],
            ]);
        }
        return $value;
    }
}
