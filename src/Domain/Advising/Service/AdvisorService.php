<?php

declare(strict_types=1);

namespace Domain\Advising\Service;

use Codefy\Domain\Metadata;
use Domain\Advising\AdvisorHistory;
use Domain\Advising\Event\StudentAdvisorsWereAssigned;
use Domain\Advising\Repository\AdvisorRepository;
use Domain\Advising\ValueObject\AdvisorAssignments;
use Domain\Advising\ValueObject\AdvisorHistoryId;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\Service\StudentPrimaryProgramLedger;
use Domain\Student\Service\StudentProgramStatusLedger;

final readonly class AdvisorService implements AdvisorProgressAccess
{
    public const string PERMISSION = "sis:advising:manage";

    public function __construct(
        private AdvisorRepository $records,
        private AdmissionsRepository $admissions,
        private StudentRecordRepository $students,
        private PersonAccountRepository $persons,
        private StudentProgramStatusLedger $programs,
        private StudentPrimaryProgramLedger $primary,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs,
    ) {
    }

    public function view(EntityId $student, EntityId $pursuit): array
    {
        $actor = $this->staff();
        [$s, $p] = $this->scope($student, $pursuit);
        $root = $this->load($student, $pursuit);
        $result = [
            "rows" => $this->labels($root->rows()),
            "revision" => $this->revision($s, $p, $root),
            "program" => $this->students->program($p->programId),
            "status" => $p->status,
            "known_history" => $root->playhead() > 0,
            "editable" => $this->isCurrent($s, $p),
        ];
        $this->staff($actor);
        return $result;
    }

    public function candidates(string $search = "", ?EntityId $after = null): array
    {
        $actor = $this->staff();
        $this->search($search);
        $result = $this->records->candidates(trim($search), $after);
        $this->staff($actor);
        return $result;
    }

    public function review(
        EntityId $student,
        EntityId $pursuit,
        array $rows,
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
            if (!hash_equals($this->revision($s, $p, $root), $revision)) {
                throw new ConcurrentModificationException("Advising changed. Reload before reviewing.");
            }
            $this->eligible($root->rows(), $next->rows, $s->personId);
            if ($root->rows() === $next->rows) {
                throw new \InvalidArgumentException("Change an advisor or its dates before reviewing.");
            }
            $operation = EntityId::generate();
            $intent = $this->intent($student, $pursuit, $next, $revision, trim($reason));
            $this->staff($actor);
            return [
                "operation_id" => $operation->value,
                "revision" => $revision,
                "rows" => $next->rows,
                "before" => $this->labels($root->rows()),
                "after" => $this->labels($next->rows),
                "reason" => trim($reason),
                "review_token" => $this->proofs->issue(
                    "advisors",
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
        array $rows,
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
                "advisors",
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
            if (!hash_equals($this->revision($s, $p, $root), $revision)) {
                throw new ConcurrentModificationException(
                    "Advising changed. Review the current assignments again.",
                );
            }
            $ids = array_unique([$s->personId->value, ...array_column($next->rows, "advisor_person_id")]);
            sort($ids, SORT_STRING);
            foreach ($ids as $id) {
                $this->admissions->lockPerson(new EntityId($id));
            }
            $this->eligible($root->rows(), $next->rows, $s->personId);
            if ($root->rows() === $next->rows) {
                throw new \InvalidArgumentException("Change the advising assignments first.");
            }
            $payload = [
                "student_id" => $student->value,
                "pursuit_id" => $pursuit->value,
                "operation_id" => $operation->value,
                "previous_operation_id" => $root->operation(),
                "before_hash" => new AdvisorAssignments($root->rows())->fingerprint(),
                "rows" => $next->rows,
                "intent" => $intent,
            ];
            $event = StudentAdvisorsWereAssigned::occur(new AdvisorHistoryId($pursuit->value), $payload, [
                Metadata::AGGREGATE_TYPE => "student_advisors",
            ])->withPlayhead($root->playhead() + 1);
            $this->history->append(
                $this->stream($pursuit),
                $operation,
                $root->playhead(),
                new EventContext($actor, $this->clock->now(), $operation, reason: trim($reason)),
                [$event],
            );
            $this->records->project($student, $pursuit, $next->rows);
            $this->audit->record($actor, "student.advisors_assigned", $student);
            $this->staff($actor);
            [$s, $p] = $this->scope($student, $pursuit);
            $this->current($s, $p);
            if (!hash_equals($this->revision($s, $p, $root), $revision)) {
                throw new ConcurrentModificationException(
                    "The advising context changed before confirmation completed.",
                );
            }
            $this->proofs->validate($token, "advisors", "registrar", $pursuit, $actor, $operation, $intent);
            $this->eligible($root->rows(), $next->rows, $s->personId);
            $verified = $this->latest($student, $pursuit);
            if ($verified === null || $verified->event->payload()["operation_id"] !== $operation->value) {
                throw new CorruptHistoryException();
            }
            $root->accept($verified);
            return $root->receipt($operation, $actor, $intent) ?? throw new CorruptHistoryException();
        });
    }

    public function ownerView(EntityId $student, EntityId $pursuit): array
    {
        $actor = $this->owner($student);
        [$s, $p] = $this->scope($student, $pursuit);
        $latest = $this->latest($student, $pursuit);
        $rows = $latest === null ? [] : new AdvisorAssignments($latest->event->payload()["rows"])->rows;
        $this->owner($student, $actor);
        return [
            "items" => $this->labels($rows),
            "pursuit_status" => $p->status,
            "as_of" => $this->clock->now()->format("Y-m-d"),
        ];
    }

    public function requireAdvisor(EntityId $student, EntityId $pursuit): EntityId
    {
        $actor = $this->access->authenticatedUserId();
        [$s, $p] = $this->scope($student, $pursuit);
        $this->current($s, $p);
        $person = $this->persons->findByUser($actor);
        if ($person === null || !$this->records->candidate($person->id)) {
            throw new AccessDeniedException("Current advisor access is required.");
        }
        $latest = $this->latest($student, $pursuit);
        $on =
        $latest === null
        ? []
        : new AdvisorAssignments($latest->event->payload()["rows"])->on(
            $this->clock->now()->format("Y-m-d"),
        );
        if (
            !in_array($person->id->value, array_column($on, "advisor_person_id"), true) ||
            $actor->value !== $this->access->authenticatedUserId()->value
        ) {
            throw new AccessDeniedException("Current advisor access is required.");
        }
        return $actor;
    }

    /** Internal scheduling source: verified assignments and current canonical account links. */
    public function appointmentHosts(EntityId $student, EntityId $pursuit, string $channel): array
    {
        $actor = $this->access->requirePermission('sis:advising:appointments:manage');
        if ($channel === 'staff') {
            $this->access->requirePermission('sis:student:read');
        } elseif ($channel === 'advisor') {
            $this->requireAdvisor($student, $pursuit);
        } else {
            throw new AccessDeniedException();
        }
        $latest = $this->latest($student, $pursuit);
        $rows = $latest === null ? [] : new AdvisorAssignments($latest->event->payload()['rows'])->rows;
        $hosts = [];
        foreach ($this->labels($rows) as $row) {
            $person = $this->admissions->person(new EntityId($row['advisor_person_id']));
            if (
                !$this->records->candidate($person->id) || $person->ownerUserId === null
                || ($channel === 'advisor' && $person->ownerUserId->value !== $actor->value)
                || ($row['ends_on'] !== null && $row['ends_on'] < $this->clock->now()->format('Y-m-d'))
                || $row['starts_on'] > $this->clock->now()->modify('+366 days')->format('Y-m-d')
            ) {
                continue;
            }
            $hosts[] = $row + ['user_id' => $person->ownerUserId->value];
        }
        if ($actor->value !== $this->access->authenticatedUserId()->value) {
            throw new AccessDeniedException();
        }
        return $hosts;
    }

    public function mine(string $search = "", ?EntityId $after = null): array
    {
        $actor = $this->access->authenticatedUserId();
        $this->search($search);
        $person = $this->persons->findByUser($actor);
        if ($person === null || !$this->records->candidate($person->id)) {
            return ["items" => [], "next_after" => null];
        }
        $page = $this->records->mine($actor, $this->clock->now()->format("Y-m-d"), trim($search), $after);
        $items = [];
        foreach ($page["items"] as $row) {
            $student = new EntityId($row["student_id"]);
            $pursuit = new EntityId($row["pursuit_id"]);
            if ($this->requireAdvisor($student, $pursuit)->value !== $actor->value) {
                throw new AccessDeniedException();
            }
            [$s, $p] = $this->scope($student, $pursuit);
            $person = $this->admissions->person($s->personId);
            $items[] = [
                "student_id" => $student->value,
                "pursuit_id" => $pursuit->value,
                "name" => $person->givenName . " " . $person->familyName,
                "institutional_id" => $person->institutionalId->value,
                "program" => $this->students->program($p->programId),
                "entry_term" => $this->students->term($p->entryTermId),
            ];
        }
        if ($actor->value !== $this->access->authenticatedUserId()->value) {
            throw new AccessDeniedException();
        }
        return ["items" => $items, "next_after" => $page["next_after"]];
    }

    public function recordProgressRead(EntityId $student, EntityId $pursuit, EntityId $actor): void
    {
        if ($this->requireAdvisor($student, $pursuit)->value !== $actor->value) {
            throw new AccessDeniedException();
        }
        $this->audit->record($actor, "advising.degree_progress_read", $student);
        if ($this->requireAdvisor($student, $pursuit)->value !== $actor->value) {
            throw new AccessDeniedException();
        }
    }

    public function timeline(EntityId $student, EntityId $pursuit, ?string $cursor = null): array
    {
        $actor = $this->staff();
        $this->access->requirePermission("sis:audit:read");
        $this->scope($student, $pursuit);
        $page = $this->history->page($this->stream($pursuit), $cursor, 20);
        $items = [];
        foreach ($page->events as $stored) {
            if ($stored->event->payload()["student_id"] !== $student->value) {
                throw new CorruptHistoryException();
            }
            $items[] = [
                "version" => $stored->event->playhead(),
                "recorded_at" => $stored->event->recordedAt()->format(DATE_ATOM),
                "rows" => $this->labels(new AdvisorAssignments($stored->event->payload()["rows"])->rows),
                "reason" => $stored->context->reason,
            ];
        }
        $this->staff($actor);
        $this->access->requirePermission("sis:audit:read");
        return ["items" => $items, "next_cursor" => $page->nextCursor];
    }

    private function latest(EntityId $student, EntityId $pursuit): ?\Domain\Shared\EventSourcing\StoredEvent
    {
        $latest = $this->history->latest($this->stream($pursuit));
        $expected = [];
        if ($latest !== null) {
            AdvisorHistory::validate($p = $latest->event->payload());
            if (
                $p["student_id"] !== $student->value ||
                $p["pursuit_id"] !== $pursuit->value ||
                $latest->context->origin !== "command" ||
                $latest->context->actor === null ||
                $latest->context->reason === null ||
                $latest->context->effectiveAt === null ||
                $latest->context->correlation?->value !== $p["operation_id"]
            ) {
                throw new CorruptHistoryException();
            }
            $expected = new AdvisorAssignments($p["rows"])->rows;
        }
        if ($expected !== $this->records->rows($pursuit)) {
            throw new CorruptHistoryException();
        }
        return $latest;
    }

    private function load(EntityId $student, EntityId $pursuit): AdvisorHistory
    {
        return new AdvisorHistoryReader($this->records, $this->history)->read(
            $student,
            $pursuit,
            new HistoryReadBudget(),
        );
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
        $this->primary->verify($student, $budget);
        if (
            $pursuit->status !== "active" ||
            $this->primary->current($student)["assignment_id"] !== $pursuit->id->value
        ) {
            throw new AccessDeniedException(
                "Advising changes and advisor access require the current primary pursuit.",
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
        \Domain\Student\Student $student,
        \Domain\Student\StudentProgram $pursuit,
        AdvisorHistory $root,
    ): string {
        return hash(
            "sha256",
            json_encode(
                [
                    $student->id->value,
                    $student->personId->value,
                    $student->programId->value,
                    $pursuit->id->value,
                    $pursuit->status,
                    $this->programs->verify($pursuit),
                    $this->primary->verify($student),
                    $root->playhead(),
                    $root->rows(),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function eligible(array $before, array $after, EntityId $studentPerson): void
    {
        $old = array_column($before, null, "id");
        foreach ($after as $row) {
            if ($row["advisor_person_id"] === $studentPerson->value) {
                throw new \Domain\Shared\Exception\ValidationException([
                    ['field' => 'rows', 'message' => 'Choose another person as the academic advisor.'],
                ]);
            }
            $previous = $old[$row["id"]] ?? null;
            $narrowed =
            $previous !== null &&
            $previous["advisor_person_id"] === $row["advisor_person_id"] &&
            $previous["role"] === $row["role"] &&
            $row["starts_on"] >= $previous["starts_on"] &&
            $row["ends_on"] !== null &&
            $row["ends_on"] <= ($previous["ends_on"] ?? "9999-12-31");
            if (
                $previous !== $row &&
                !$narrowed &&
                !$this->records->candidate(new EntityId($row["advisor_person_id"]))
            ) {
                throw new \Domain\Shared\Exception\ValidationException([
                    ['field' => 'rows', 'message' => 'Choose an enabled faculty member or active employee.'],
                ]);
            }
        }
    }

    private function validateRows(#[\SensitiveParameter] array $rows): AdvisorAssignments
    {
        try {
            return new AdvisorAssignments($rows);
        } catch (\InvalidArgumentException $invalid) {
            throw new \Domain\Shared\Exception\ValidationException([
                ['field' => 'rows', 'message' => $invalid->getMessage()],
            ]);
        }
    }

    private function labels(array $rows): array
    {
        $labels = $this->records->labels(
            array_values(array_unique(array_column($rows, "advisor_person_id"))),
        );
        return array_map(
            static fn(array $row): array => $row + [
                "name" => isset($labels[$row["advisor_person_id"]])
                    ? $labels[$row["advisor_person_id"]]["given_name"] .
                        " " .
                        $labels[$row["advisor_person_id"]]["family_name"]
                    : "Advisor name unavailable",
            ],
            $rows,
        );
    }
    private function stream(EntityId $pursuit): StreamReference
    {
        return new StreamReference(new AdvisorHistoryId($pursuit->value), "student_advisors");
    }
    private function owner(EntityId $student, ?EntityId $expected = null): EntityId
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->admissions->person($this->admissions->student($student)->personId);
        if (
            $person->ownerUserId?->value !== $actor->value ||
            ($expected !== null && $expected->value !== $actor->value)
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }
    private function staff(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission(self::PERMISSION);
        if (
            $this->access->requirePermission("sis:student:read")->value !== $actor->value ||
            $this->access->authenticatedUserId()->value !== $actor->value ||
            ($expected !== null && $expected->value !== $actor->value)
        ) {
            throw new AccessDeniedException("The advising staff account changed.");
        }
        return $actor;
    }
    private function reason(#[\SensitiveParameter] string $reason): void
    {
        if (
            !mb_check_encoding($reason, "UTF-8") ||
            trim($reason) === "" ||
            mb_strlen($reason) > 10000 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            throw new \InvalidArgumentException("Enter a reason for the advising change.");
        }
    }
    private function search(string $search): void
    {
        if (
            !mb_check_encoding($search, "UTF-8") ||
            mb_strlen($search) > 80 ||
            preg_match('/[\x00-\x1F\x7F]/', $search)
        ) {
            throw new \InvalidArgumentException("Use a search of up to eighty characters.");
        }
    }
    private function intent(
        EntityId $student,
        EntityId $pursuit,
        AdvisorAssignments $next,
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
