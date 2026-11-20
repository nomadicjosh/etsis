<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Advising\Service\AdvisorProgressAccess;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Event\StudentStandingWasRecorded;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\Repository\StudentStandingRepository;
use Domain\Student\StudentStanding;
use Domain\Student\ValueObject\StandingPeriods;
use Domain\Student\ValueObject\StudentStandingId;

final readonly class StudentStandingService
{
    public const string MANAGE = "sis:standing:manage";
    public const string CONFIGURE = "sis:standing:configure";

    public function __construct(
        private StudentStandingRepository $records,
        private AdmissionsRepository $admissions,
        private StudentRecordRepository $students,
        private StudentProgramStatusLedger $programs,
        private StudentPrimaryProgramLedger $primary,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs,
        private AdvisorProgressAccess $advisors,
    ) {
    }

    public function definitions(string $search = "", ?EntityId $after = null, bool $setup = false): array
    {
        $permission = $setup ? self::CONFIGURE : self::MANAGE;
        $actor = $this->authorize($permission);
        if (
            !mb_check_encoding($search, "UTF-8") ||
            mb_strlen($search) > 80 ||
            preg_match('/[\x00-\x1F\x7F]/', $search)
        ) {
            throw new ValidationException([
                ["field" => "search", "message" => "Use a search of up to eighty characters."],
            ]);
        }
        $result = $this->records->definitions(trim($search), $after, !$setup);
        $this->authorize($permission, $actor);
        return $result;
    }

    public function saveDefinition(
        ?EntityId $id,
        string $code,
        string $title,
        bool $active,
        ?int $version,
    ): array {
        $actor = $this->authorize(self::CONFIGURE);
        $code = strtoupper(trim($code));
        $title = trim($title);
        try {
            StandingPeriods::label($code, $title);
        } catch (\InvalidArgumentException $invalid) {
            throw new ValidationException([["field" => "code", "message" => $invalid->getMessage()]]);
        }
        if (($id === null) !== ($version === null) || ($version !== null && $version < 1)) {
            throw new ConcurrentModificationException("Reload the standing definition before saving.");
        }
        return $this->transactions->run(function () use (
            $id,
            $code,
            $title,
            $active,
            $version,
            $actor,
        ): array {
            if ($id !== null) {
                $this->records->lockDefinition($id);
            }
            $id ??= EntityId::generate();
            $result = $this->records->saveDefinition($id, $code, $title, $active, $version);
            $this->audit->record(
                $actor,
                $version === null ? "standing.definition_created" : "standing.definition_updated",
                $id,
            );
            $this->authorize(self::CONFIGURE, $actor);
            if ($result !== $this->records->definition($id)) {
                throw new ConcurrentModificationException("The standing definition changed during saving.");
            }
            return $result;
        });
    }

    public function view(EntityId $student, EntityId $pursuit): array
    {
        $actor = $this->staff();
        [$s, $p] = $this->scope($student, $pursuit);
        $root = $this->load($student, $pursuit);
        $result = [
            "rows" => $root->rows(),
            "revision" => $this->revision($s, $p, $root),
            "program" => $this->students->program($p->programId),
            "status" => $p->status,
            "known_history" => $root->playhead() > 0,
            "editable" => $this->isCurrent($s, $p),
        ];
        $this->staff($actor);
        return $result;
    }

    public function review(
        EntityId $student,
        EntityId $pursuit,
        #[\SensitiveParameter] array $rows,
        string $revision,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->staff();
        $next = $this->validateRows($rows);
        $this->reason($reason);
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $next,
            $revision,
            $reason,
            $actor,
        ): array {
            $this->students->lock($student);
            [$s, $p] = $this->scope($student, $pursuit);
            $this->current($s, $p);
            $root = $this->load($student, $pursuit);
            $this->unchanged($revision, $s, $p, $root);
            $this->eligible($root->rows(), $next->rows);
            if ($root->rows() === $next->rows) {
                throw new ValidationException([
                    ["field" => "rows", "message" => "Change a standing or its dates before reviewing."],
                ]);
            }
            $operation = EntityId::generate();
            $intent = $this->intent($student, $pursuit, $next, $revision, trim($reason));
            $this->staff($actor);
            return [
                "operation_id" => $operation->value,
                "rows" => $next->rows,
                "revision" => $revision,
                "before" => $root->rows(),
                "after" => $next->rows,
                "reason" => trim($reason),
                "review_token" => $this->proofs->issue(
                    "standing",
                    "registrar",
                    $pursuit,
                    $actor,
                    $operation,
                    $intent,
                ),
            ];
        });
    }

    public function commit(
        EntityId $student,
        EntityId $pursuit,
        EntityId $operation,
        #[\SensitiveParameter] array $rows,
        string $revision,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->staff();
        $next = $this->validateRows($rows);
        $this->reason($reason);
        $intent = $this->intent($student, $pursuit, $next, $revision, trim($reason));
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $operation,
            $next,
            $revision,
            $reason,
            $token,
            $actor,
            $intent,
        ): array {
            $this->students->lock($student);
            [$s, $p] = $this->scope($student, $pursuit);
            $root = $this->load($student, $pursuit);
            $receipt = $root->receipt($operation, $actor, $intent);
            $this->proofs->validate(
                $token,
                "standing",
                "registrar",
                $pursuit,
                $actor,
                $operation,
                $intent,
                $receipt !== null,
            );
            $this->staff($actor);
            if ($receipt !== null) {
                return $receipt;
            }
            $this->current($s, $p);
            $this->unchanged($revision, $s, $p, $root);
            $ids = array_unique(array_column($next->rows, "definition_id"));
            sort($ids, SORT_STRING);
            foreach ($ids as $id) {
                $this->records->lockDefinition(new EntityId($id));
            }
            $this->eligible($root->rows(), $next->rows);
            if ($root->rows() === $next->rows) {
                throw new ConcurrentModificationException(
                    "Review a changed standing list before confirming.",
                );
            }
            $event = StudentStandingWasRecorded::occur(
                new StudentStandingId($pursuit->value),
                [
                    "student_id" => $student->value,
                    "pursuit_id" => $pursuit->value,
                    "operation_id" => $operation->value,
                    "previous_operation_id" => $root->operation(),
                    "before_hash" => new StandingPeriods($root->rows())->fingerprint(),
                    "rows" => $next->rows,
                    "intent" => $intent,
                ],
                ["__aggregate_type" => "student_standing"],
            )->withPlayhead($root->playhead() + 1);
            $this->history->append(
                $this->stream($pursuit),
                $operation,
                $root->playhead(),
                new EventContext($actor, $this->clock->now(), $operation, reason: trim($reason)),
                [$event],
            );
            $this->records->project($student, $pursuit, $next->rows);
            $this->audit->record($actor, "student.standing_recorded", $student);
            $this->staff($actor);
            [$s, $p] = $this->scope($student, $pursuit);
            $this->current($s, $p);
            $this->unchanged($revision, $s, $p, $root);
            $this->proofs->validate($token, "standing", "registrar", $pursuit, $actor, $operation, $intent);
            $this->eligible($root->rows(), $next->rows);
            $verified = $this->latest($student, $pursuit);
            if ($verified === null || $verified->event->payload()["operation_id"] !== $operation->value) {
                throw new CorruptHistoryException();
            }
            $root->accept($verified);
            return $root->receipt($operation, $actor, $intent) ?? throw new CorruptHistoryException();
        });
    }

    /** Minimal owner/advisor presentation; staff narratives and policy references stay private. */
    public function read(EntityId $student, EntityId $pursuit, bool $advisor = false): array
    {
        $actor = $this->reader($student, $pursuit, $advisor);
        [, $p] = $this->scope($student, $pursuit);
        $this->programs->verify($p);
        $latest = $this->latest($student, $pursuit);
        $rows = $latest === null ? [] : new StandingPeriods($latest->event->payload()["rows"])->rows;
        $result = [
            "items" => array_map(
                static fn(array $row): array => array_intersect_key(
                    $row,
                    array_flip(["code", "title", "starts_on", "ends_on"]),
                ),
                $rows,
            ),
            "pursuit_status" => $p->status,
            "as_of" => $this->clock->now()->format("Y-m-d"),
        ];
        if ($advisor) {
            $this->audit->record($actor, "advising.standing_read", $student);
        }
        if ($actor->value !== $this->reader($student, $pursuit, $advisor)->value) {
            throw new AccessDeniedException();
        }
        return $result;
    }

    public function timeline(EntityId $student, EntityId $pursuit, ?string $cursor = null): array
    {
        $actor = $this->staff();
        $this->authorize("sis:audit:read", $actor);
        $this->scope($student, $pursuit);
        $page = $this->history->page($this->stream($pursuit), $cursor, 20);
        $items = [];
        foreach ($page->events as $stored) {
            StudentStanding::validate($data = $stored->event->payload());
            if ($data["student_id"] !== $student->value) {
                throw new CorruptHistoryException();
            }
            $items[] = [
                "version" => $stored->event->playhead(),
                "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
                "reason" => $stored->context->reason,
                "rows" => $data["rows"],
            ];
        }
        $this->staff($actor);
        $this->authorize("sis:audit:read", $actor);
        return ["items" => $items, "next_cursor" => $page->nextCursor];
    }

    private function eligible(array $before, array $after): void
    {
        $old = array_column($before, null, "id");
        foreach ($after as $row) {
            $previous = $old[$row["id"]] ?? null;
            $sameLabel =
            $previous !== null &&
            array_intersect_key(
                $previous,
                array_flip(["definition_id", "code", "title", "policy_reference"]),
            ) ===
            array_intersect_key(
                $row,
                array_flip(["definition_id", "code", "title", "policy_reference"]),
            );
            $narrowed =
            $sameLabel &&
            $row["starts_on"] >= $previous["starts_on"] &&
            $row["ends_on"] !== null &&
            $row["ends_on"] <= ($previous["ends_on"] ?? "9999-12-31");
            if ($previous === $row || $narrowed) {
                continue;
            }
            $definition = $this->records->definition(new EntityId($row["definition_id"]));
            if (
                !$definition["active"] ||
                $definition["code"] !== $row["code"] ||
                $definition["title"] !== $row["title"]
            ) {
                throw new ValidationException([
                    [
                        "field" => "rows",
                        "message" =>
                            "A standing definition changed or was retired. " .
                            "Choose its current active definition before reviewing.",
                    ],
                ]);
            }
        }
    }

    private function latest(EntityId $student, EntityId $pursuit): ?\Domain\Shared\EventSourcing\StoredEvent
    {
        $latest = $this->history->latest($this->stream($pursuit));
        $expected = [];
        if ($latest !== null) {
            StudentStanding::validate($data = $latest->event->payload());
            if (
                $data["student_id"] !== $student->value ||
                $data["pursuit_id"] !== $pursuit->value ||
                $latest->context->origin !== "command" ||
                $latest->context->actor === null ||
                $latest->context->reason === null ||
                $latest->context->effectiveAt === null ||
                $latest->context->correlation?->value !== $data["operation_id"]
            ) {
                throw new CorruptHistoryException();
            }
            $expected = new StandingPeriods($data["rows"])->rows;
        }
        if ($expected !== $this->records->rows($student, $pursuit)) {
            throw new CorruptHistoryException();
        }
        return $latest;
    }

    private function load(EntityId $student, EntityId $pursuit): StudentStanding
    {
        $root = StudentStanding::root(new StudentStandingId($pursuit->value));
        $budget = new HistoryReadBudget();
        $cursor = null;
        do {
            $page = $budget->page($this->history, $this->stream($pursuit), $cursor);
            foreach ($page->events as $stored) {
                if ($stored->event->payload()["student_id"] !== $student->value) {
                    throw new CorruptHistoryException();
                }
                $root->accept($stored);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if ($root->rows() !== $this->records->rows($student, $pursuit)) {
            throw new CorruptHistoryException();
        }
        return $root;
    }

    private function scope(EntityId $student, EntityId $pursuit): array
    {
        $s = $this->admissions->student($student);
        $p = $this->students->assignment($pursuit);
        if ($p->studentId->value !== $student->value) {
            throw new RecordNotFoundException("Student program not found.");
        }
        return [$s, $p];
    }

    private function current(\Domain\Student\Student $student, \Domain\Student\StudentProgram $pursuit): void
    {
        $budget = new HistoryReadBudget();
        $this->programs->verify($pursuit, $budget);
        if ($pursuit->status !== "active") {
            throw new AccessDeniedException("New standing decisions require an active pursuit.");
        }
        $this->primary->verify($student, $budget);
        if (
            $pursuit->status !== "active" ||
            $this->primary->current($student)["assignment_id"] !== $pursuit->id->value
        ) {
            throw new AccessDeniedException(
                "New standing decisions require the current primary, active pursuit.",
            );
        }
    }

    private function isCurrent(
        \Domain\Student\Student $student,
        \Domain\Student\StudentProgram $pursuit,
    ): bool {
        try {
            $this->current($student, $pursuit);
            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }

    private function revision(
        \Domain\Student\Student $s,
        \Domain\Student\StudentProgram $p,
        StudentStanding $root,
    ): string {
        $budget = new HistoryReadBudget();
        return hash(
            "sha256",
            json_encode(
                [
                    $s->id->value,
                    $s->personId->value,
                    $s->programId->value,
                    $p->id->value,
                    $p->status,
                    $this->programs->verify($p, $budget),
                    $this->primary->documentContext($s, $budget),
                    $root->playhead(),
                    $root->rows(),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function unchanged(
        string $revision,
        \Domain\Student\Student $s,
        \Domain\Student\StudentProgram $p,
        StudentStanding $root,
    ): void {
        if (!hash_equals($this->revision($s, $p, $root), $revision)) {
            throw new ConcurrentModificationException(
                "The pursuit or standing changed. Reload and review again.",
            );
        }
    }

    private function reader(EntityId $student, EntityId $pursuit, bool $advisor): EntityId
    {
        if ($advisor) {
            return $this->advisors->requireAdvisor($student, $pursuit);
        }
        $actor = $this->access->authenticatedUserId();
        $person = $this->admissions->person($this->admissions->student($student)->personId);
        if ($person->ownerUserId?->value !== $actor->value) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    private function staff(?EntityId $expected = null): EntityId
    {
        $actor = $this->authorize(self::MANAGE, $expected);
        $this->authorize("sis:student:read", $actor);
        return $actor;
    }

    private function authorize(string $permission, ?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission($permission);
        if (
            $actor->value !== $this->access->authenticatedUserId()->value ||
            ($expected !== null && $expected->value !== $actor->value)
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    private function validateRows(#[\SensitiveParameter] array $rows): StandingPeriods
    {
        try {
            return new StandingPeriods($rows);
        } catch (\InvalidArgumentException $invalid) {
            throw new ValidationException([["field" => "rows", "message" => $invalid->getMessage()]]);
        }
    }

    private function reason(#[\SensitiveParameter] string $reason): void
    {
        if (
            !mb_check_encoding($reason, "UTF-8") ||
            trim($reason) === "" ||
            mb_strlen($reason) > 10000 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            throw new ValidationException([
                ["field" => "reason", "message" => "Enter a reason for the standing decision."],
            ]);
        }
    }

    private function stream(EntityId $pursuit): StreamReference
    {
        return new StreamReference(new StudentStandingId($pursuit->value), "student_standing");
    }

    private function intent(
        EntityId $student,
        EntityId $pursuit,
        StandingPeriods $next,
        string $revision,
        #[\SensitiveParameter] string $reason,
    ): string {
        return hash(
            "sha256",
            json_encode(
                [$student->value, $pursuit->value, $revision, $next->rows, $reason],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
