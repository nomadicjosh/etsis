<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\Enum\ApplicationStatus;
use Domain\Admissions\Event\StudentWasMatriculated;
use Domain\Admissions\Matriculation;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Admissions\ValueObject\MatriculationId;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogYearRepository;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\Service\ProgramEntryContext;
use Domain\Student\Service\StudentActivityLedger;
use Domain\Student\Service\StudentPrimaryProgramLedger;
use Domain\Student\Service\StudentProgramStatusLedger;
use Domain\Student\StudentProgram;

final readonly class MatriculationService
{
    public function __construct(
        private AdmissionsRepository $admissions,
        private StudentRecordRepository $students,
        private CatalogYearRepository $catalogs,
        private ProgramEntryContext $entry,
        private StudentProgramStatusLedger $programs,
        private StudentActivityLedger $activity,
        private StudentPrimaryProgramLedger $primary,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** @return array<string,mixed> */
    public function review(EntityId $application, #[\SensitiveParameter] string $reason): array
    {
        $actor = $this->access->requirePermission("sis:admissions:manage");
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
        $route = $this->admissions->application($application);
        return $this->transactions->run(function () use ($route, $actor, $reason): array {
            $a = $this->lock($route, $actor);
            $this->eligible($a);
            $source = $this->entry->proposal($a->programId, $a->entryTermId);
            $person = $this->admissions->person($a->personId);
            $token = $this->cipher->seal(
                json_encode(
                    [
                        "purpose" => "matriculation.v1",
                        "application" => $a->id->value,
                        "actor" => $actor->value,
                        "student" => EntityId::generate()->value,
                        "assignment" => EntityId::generate()->value,
                        "operation" => EntityId::generate()->value,
                        "reason" => trim($reason),
                        "context" => $this->context($a, $source),
                        "expires" => $this->clock->now()->getTimestamp() + 1200,
                    ],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
            );
            return [
                "source" => $source,
                "person" => [
                    "given_name" => $person->givenName,
                    "family_name" => $person->familyName,
                    "institutional_id" => $person->institutionalId->value,
                ],
                "effective_on" => $this->clock->now()->format("Y-m-d"),
                "review_token" => $token,
            ];
        });
    }

    /** @return array<string,mixed> */
    public function commit(EntityId $application, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->access->requirePermission("sis:admissions:manage");
        try {
            if (strlen($token) > 100000) {
                throw new \InvalidArgumentException();
            }
            $p = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($p) ||
                count($p) !== 9 ||
                ($p["purpose"] ?? null) !== "matriculation.v1" ||
                ($p["application"] ?? null) !== $application->value ||
                ($p["actor"] ?? null) !== $actor->value ||
                !is_string($p["student"] ?? null) ||
                !is_string($p["assignment"] ?? null) ||
                !is_string($p["operation"] ?? null) ||
                !is_string($p["reason"] ?? null) ||
                !is_string($p["context"] ?? null) ||
                !is_int($p["expires"] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
            $studentId = new EntityId($p["student"]);
            $assignmentId = new EntityId($p["assignment"]);
            $operation = new EntityId($p["operation"]);
        } catch (\Throwable) {
            throw new ValidationException([
                ["field" => "review_token", "message" => "Review matriculation again."],
            ]);
        }
        $route = $this->admissions->application($application);
        return $this->transactions->run(function () use (
            $route,
            $actor,
            $p,
            $studentId,
            $assignmentId,
            $operation,
        ): array {
            $a = $this->lock($route, $actor);
            $recorded = $this->recorded($a, $actor);
            if ($recorded !== null) {
                if ($recorded["facts"]["decision_id"] !== $operation->value) {
                    throw new ConcurrentModificationException(
                        "Another confirmation matriculated this application. Reload it.",
                    );
                }
                if ($recorded["actor"] !== $actor->value) {
                    throw new AccessDeniedException("This matriculation receipt belongs to another actor.");
                }
                return $this->result($recorded["facts"], $recorded["effective_at"]);
            }
            $this->eligible($a);
            $source = $this->entry->proposal($a->programId, $a->entryTermId);
            if (
                $p["expires"] < $this->clock->now()->getTimestamp() ||
                !hash_equals($p["context"], $this->context($a, $source))
            ) {
                throw new ConcurrentModificationException(
                    "This matriculation review expired or its context changed. Review it again.",
                );
            }
            $student = $a->matriculate($studentId);
            $assignment = new StudentProgram($assignmentId, $studentId, $a->programId, $a->entryTermId);
            $this->admissions->projectMatriculation($a, $student, $assignment);
            $source = $this->entry->initialize($assignment, $actor, $source);
            $this->programs->assigned($assignment, $source, $actor, $p["reason"], $operation);
            $this->activity->start($student, $a->id, $operation, $actor, $p["reason"]);
            $this->primary->initialize($student, $a->id, $operation, $actor, $p["reason"]);
            $facts = [
                "decision_id" => $operation->value,
                "application_id" => $a->id->value,
                "person_id" => $a->personId->value,
                "program_id" => $a->programId->value,
                "entry_term_id" => $a->entryTermId->value,
                "student_id" => $studentId->value,
                "assignment_id" => $assignmentId->value,
                "accepted_version" => $a->version,
            ];
            $e = StudentWasMatriculated::occur(new MatriculationId($a->id->value), $facts, [
                "__aggregate_type" => "matriculation",
            ])->withPlayhead(1);
            Matriculation::root(new MatriculationId($a->id->value))->acceptHistorical($e);
            $at = $this->clock->now();
            $this->history->append(
                $this->stream($a->id),
                $operation,
                0,
                new EventContext($actor, $at, $operation, reason: $p["reason"]),
                [$e],
            );
            $this->audit->record($actor, "student.matriculated", $studentId);
            return $this->result($facts, $at->format(DATE_ATOM));
        });
    }

    /** Curated staff history; older rows remain explicitly unknown, never converted into invented events.
     * @return array<string,mixed> */
    public function history(EntityId $application): array
    {
        $actor = $this->access->requirePermission("sis:admissions:manage");
        $route = $this->admissions->application($application);
        return $this->transactions->run(function () use ($route, $actor): array {
            $a = $this->lock($route, $actor);
            $r = $this->recorded($a, $actor);
            if ($r === null) {
                return ["history_recorded" => false, "earlier_history_unknown" => true];
            }
            $f = $r["facts"];
            $assignment = $this->students->assignment(new EntityId($f["assignment_id"]));
            return [
                "history_recorded" => true,
                "earlier_history_unknown" => false,
                "acceptance_history_unknown" => true,
                "matriculation" => $this->result($f, $r["effective_at"]),
                "source" => $this->programs->timeline($assignment, null)["assignment_source"],
            ];
        });
    }

    private function lock(AdmissionApplication $route, EntityId $actor): AdmissionApplication
    {
        // The person lock serializes different accepted applications belonging to the same person.
        $this->admissions->lockPerson($route->personId);
        $this->admissions->lockApplication($route->id);
        if ($this->access->requirePermission("sis:admissions:manage")->value !== $actor->value) {
            throw new AccessDeniedException("The matriculation actor changed.");
        }
        $a = $this->admissions->application($route->id);
        if (
            $a->personId->value !== $route->personId->value ||
            $a->programId->value !== $route->programId->value ||
            $a->entryTermId->value !== $route->entryTermId->value
        ) {
            throw new ConcurrentModificationException("The application changed. Review it again.");
        }
        if ($a->status === ApplicationStatus::Accepted) {
            $this->catalogs->lockTerm($a->entryTermId);
            $this->catalogs->lockProgram($a->programId);
            if ($this->access->requirePermission("sis:admissions:manage")->value !== $actor->value) {
                throw new AccessDeniedException("The matriculation actor changed.");
            }
        }
        return $a;
    }
    private function eligible(AdmissionApplication $a): void
    {
        if ($a->status !== ApplicationStatus::Accepted) {
            throw new ValidationException([
                [
                    "field" => "application",
                    "message" => "Only an accepted, unmatriculated application can create a student.",
                ],
            ]);
        }
        if ($this->admissions->studentForPerson($a->personId) !== null) {
            throw new ValidationException([
                [
                    "field" => "person",
                    "message" =>
                        "This person already has a student record. Review their existing programs instead.",
                ],
            ]);
        }
    }
    /** @param array<string,mixed> $source */
    private function context(AdmissionApplication $a, array $source): string
    {
        $p = $this->admissions->person($a->personId);
        return hash(
            "sha256",
            json_encode(
                [
                    $a->id->value,
                    $a->personId->value,
                    $a->programId->value,
                    $a->entryTermId->value,
                    $a->status->value,
                    $a->version,
                    $p->identityVersion,
                    $p->givenName,
                    $p->familyName,
                    $p->institutionalId->value,
                    $source,
                    $this->clock->now()->format("Y-m-d"),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
    /** @return array{facts:array<string,mixed>,actor:?string,effective_at:string}|null */
    private function recorded(AdmissionApplication $a, EntityId $actor): ?array
    {
        $root = Matriculation::root(new MatriculationId($a->id->value));
        $page = $this->history->page($this->stream($a->id), null, 2);
        if ($page->nextCursor !== null) {
            throw new CorruptHistoryException();
        }
        $recordedActor = null;
        $at = null;
        foreach ($page->events as $stored) {
            $root->acceptHistorical($stored->event);
            $recordedActor = $stored->context->actor?->value;
            $at = $stored->context->effectiveAt?->format(DATE_ATOM);
        }
        $f = $root->facts();
        if ($f === null) {
            return null;
        }
        if (
            $a->status !== ApplicationStatus::Matriculated ||
            $a->version !== $f["accepted_version"] + 1 ||
            $a->personId->value !== $f["person_id"] ||
            $a->programId->value !== $f["program_id"] ||
            $a->entryTermId->value !== $f["entry_term_id"] ||
            $recordedActor === null ||
            $at === null
        ) {
            throw new CorruptHistoryException();
        }
        $student = $this->admissions->student(new EntityId($f["student_id"]));
        // Hold the student lock while verifying its current projections, including legitimate later decisions.
        $this->students->lock($student->id);
        if ($this->access->requirePermission("sis:admissions:manage")->value !== $actor->value) {
            throw new AccessDeniedException("The matriculation actor changed.");
        }
        $student = $this->admissions->student($student->id);
        $p = $this->students->assignment(new EntityId($f["assignment_id"]));
        if (
            $student->personId->value !== $f["person_id"] ||
            $student->entryTermId->value !== $f["entry_term_id"] ||
            $p->studentId->value !== $student->id->value ||
            $p->programId->value !== $f["program_id"] ||
            $p->entryTermId->value !== $f["entry_term_id"] ||
            $this->programs->verify($p) < 1 ||
            $this->activity->verify($student) < 1 ||
            $this->primary->verify($student) < 1
        ) {
            throw new CorruptHistoryException();
        }
        return ["facts" => $f, "actor" => $recordedActor, "effective_at" => $at];
    }
    /** @param array<string,mixed> $f
     * @return array<string,mixed> */
    private function result(array $f, string $at): array
    {
        return [
            "application_id" => $f["application_id"],
            "student_id" => $f["student_id"],
            "assignment_id" => $f["assignment_id"],
            "program_id" => $f["program_id"],
            "entry_term_id" => $f["entry_term_id"],
            "effective_at" => $at,
        ];
    }
    private function stream(EntityId $id): StreamReference
    {
        return new StreamReference(new MatriculationId($id->value), "matriculation");
    }
}
