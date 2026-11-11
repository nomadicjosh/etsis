<?php

declare(strict_types=1);

namespace Domain\Transfer\Service;

use DateTimeImmutable;
use DomainException;
use Domain\AcademicRecord\ValueObject\GradeSnapshot;
use Domain\Catalog\Service\PublishedCourseReader;
use Domain\Gradebook\Service\AcademicGradeCatalog;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Event\TransferCreditWasEstablished;
use Domain\Transfer\Event\TransferCreditWasChanged;
use Domain\Transfer\Repository\TransferCreditRepository;
use Domain\Transfer\TransferCredit;
use Domain\Transfer\TransferEquivalency;
use Domain\Transfer\ValueObject\AwardChange;
use Domain\Transfer\ValueObject\TransferCreditId;
use Domain\Transfer\ValueObject\TransferCreditFacts;

/** Internal academic ledger. Entry-point services authorize and own student/catalog locks. */
final readonly class TransferCreditLedger
{
    public function __construct(
        private TransferCreditRepository $records,
        private HistoricalEventStore $history,
        private AcademicGradeCatalog $grades,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private Clock $clock,
        private PublishedCourseReader $courses,
    ) {
    }
    /** @param array<string,mixed> $reviewed */
    public function award(
        EntityId $student,
        TransferEquivalency $rule,
        EntityId $term,
        DateTimeImmutable $completed,
        int $rank,
        #[\SensitiveParameter] array $reviewed,
        EntityId $actor,
        EntityId $courseVersion,
    ): EntityId {
        return $this->transactions->run(function () use (
            $student,
            $rule,
            $term,
            $completed,
            $rank,
            $reviewed,
            $actor,
            $courseVersion,
        ): EntityId {
            $this->actor($actor);
            $existing = $this->records->existing($student, $rule->id, $completed->format("Y-m-d"));
            if ($existing !== null) {
                $retained = $this->load(new EntityId($existing["id"]), new HistoryReadBudget());
                if ($retained->courseVersion() !== $courseVersion->value) {
                    throw new DomainException(
                        "This award retains a different or unknown course publication. " .
                        "Review the existing award; a retry cannot replace its history.",
                    );
                }
                $credit = $this->records->credit(new EntityId($existing["credit_id"]));
                if (
                    $credit["source_id"] !== $existing["id"] ||
                    $credit["student_id"] !== $student->value ||
                    $credit["term_id"] !== $existing["term_id"]
                ) {
                    throw new CorruptHistoryException();
                }
                if ($existing["term_id"] !== $term->value) {
                    throw new DomainException("This transfer award already belongs to another term.");
                }
                if ($credit["status"] === "V") {
                    throw new DomainException(
                        "This award is voided. Use the reviewed reinstatement workflow.",
                    );
                }
                return new EntityId($credit["id"]);
            }
            $id = EntityId::generate();
            $creditId = EntityId::generate();
            $publication = $this->courses->version(
                $rule->institutionalCourseId,
                $courseVersion,
                new HistoryReadBudget(),
            );
            $definition = $publication->definition;
            $termRow = $this->records->term($term);
            $grade = GradeSnapshot::fromRow($this->grades->byCode($rule->awardedGrade, $student))->facts;
            if (!$grade["passes"]) {
                throw new DomainException(
                    "The applicable grading policy does not award credit for this grade.",
                );
            }
            $source = [
                "award_id" => $id->value,
                "student_id" => $student->value,
                "equivalency_id" => $rule->id->value,
                "course_id" => $rule->institutionalCourseId->value,
                "term_id" => $term->value,
                "completed_on" => $completed->format("Y-m-d"),
                "institution_id" => $reviewed["institution_id"],
                "institution_code" => $reviewed["institution_code"],
                "institution_name" => $reviewed["institution_name"],
                "external_course_id" => $reviewed["external_course_id"],
                "external_code" => $reviewed["external_code"],
                "external_title" => $reviewed["external_title"],
                "term_code" => $termRow["code"],
                "equivalency" => array_intersect_key(
                    $reviewed,
                    array_flip([
                        "starts_on",
                        "ends_on",
                        "minimum_grade_rank",
                        "credits_milli",
                        "grade_id",
                        "awarded_grade",
                    ]),
                ),
                "grade_rank" => $rank,
            ];
            $credit = new TransferCreditFacts([
                "id" => $creditId->value,
                "student_id" => $student->value,
                "course_id" => $rule->institutionalCourseId->value,
                "term_id" => $term->value,
                "source_id" => $id->value,
                "credit_type" => "transfer",
                "course_code" => $definition->code,
                "course_title" => $definition->text->transcriptTitle ?? $definition->title,
                "attempted_milli" => $rule->creditThousandths,
                "completed_milli" => $rule->creditThousandths,
                "grade_points_milli" => $grade["counts_gpa"]
                    ? intdiv($rule->creditThousandths * $grade["points_milli"], 1000)
                    : 0,
                "grade_id" => $grade["id"],
                "status" => "A",
                "version" => 0,
            ]);
            $event = TransferCreditWasEstablished::occur(
                new TransferCreditId($id->value),
                [
                    "decision_id" => $id->value,
                    "provenance" => "award",
                    "source" => $source,
                    "credit" => $credit->facts,
                    "grade" => $grade,
                ],
                ["__aggregate_type" => "transfer_credit", "course_version_id" => $courseVersion->value],
            )->withPlayhead(1);
            $this->history->append(
                $this->stream($id),
                $id,
                0,
                new EventContext(
                    $actor,
                    $this->clock->now(),
                    $id,
                    reason: "Transfer credit awarded from the reviewed equivalency.",
                ),
                [$event],
            );
            $root = TransferCredit::root(new TransferCreditId($id->value));
            $root->acceptHistorical($event);
            $this->records->create(
                $root->source() ?? throw new CorruptHistoryException(),
                $root->credit() ?? throw new CorruptHistoryException(),
                $publication,
            );
            return $creditId;
        });
    }
    /** Authorized callers may use the retained source labels, never today's labels as earlier facts.
     * @param array<string,mixed> $record
     * @return array<string,mixed> */
    public function verified(#[\SensitiveParameter] array $record): array
    {
        $root = $this->load(new EntityId($record["id"]));
        $known = $root->playhead() > 0;
        if ($known) {
            $s = $root->source() ?? throw new CorruptHistoryException();
            $record = array_replace(
                $record,
                array_intersect_key(
                    $s,
                    array_flip([
                        "institution_id",
                        "institution_code",
                        "institution_name",
                        "external_course_id",
                        "external_code",
                        "external_title",
                        "course_id",
                        "term_code",
                    ]),
                ),
            );
            $record["credit"] = $root->credit();
            $display = $root->display();
            $record["grade_code"] = $display["grade_code"];
            $record["counts_gpa"] = $display["counts_gpa"];
        }
        $record["retained_grade_id"] = $root->grade()["id"] ?? $record["credit"]["grade_id"];
        $record["academic_on"] = $root->academicOn();
        $record["course_version_id"] = $root->courseVersion();
        $record["history_recorded"] = $known;
        $record["earlier_history_unknown"] = !$known || $root->provenance() === "observed_existing";
        $record["effective_on_default"] = $this->clock->now()->format("Y-m-d");
        return $record;
    }
    /** Internal audit input, after the caller authorizes and locks the student.
     * @return array<string,mixed>
     */
    public function auditFacts(EntityId $credit, EntityId $student, ?HistoryReadBudget $budget = null): array
    {
        $facts = $this->records->credit($credit);
        if ($facts["student_id"] !== $student->value) {
            throw new \Domain\Shared\Exception\AccessDeniedException(
                "The transfer record belongs to another student.",
            );
        }
        $award = new EntityId($facts["source_id"]);
        $row = $this->records->award($award);
        if (
            $row["student_id"] !== $student->value ||
            $row["credit_id"] !== $credit->value ||
            $row["term_id"] !== $facts["term_id"]
        ) {
            throw new CorruptHistoryException();
        }
        $root = $this->load($award, $budget);
        return [
            "credit" => $root->credit() ?? $facts,
            "grade" => $facts["grade_id"] === null ? null : $root->grade(),
            "history_recorded" => $root->playhead() > 0,
            "earlier_history_unknown" =>
                $root->playhead() === 0 || $root->provenance() === "observed_existing",
            "history_version" => $root->playhead(),
            "section_version_id" => null,
            "completed_on" => $root->source()["completed_on"] ?? $row["completed_on"],
            "academic_on" => $root->academicOn(),
            "course_version_id" => $root->courseVersion(),
            "retained_term_code" => $root->source()["term_code"] ?? null,
        ];
    }
    /** Called inside the authorized correction's unit of work, after its review revision is checked.
     * @param array<string,mixed> $record
     * @param array<string,mixed>|null $grade */
    public function change(
        #[\SensitiveParameter] array $record,
        #[\SensitiveParameter] AwardChange $change,
        #[\SensitiveParameter] ?array $grade,
        EntityId $actor,
        EntityId $operation,
        DateTimeImmutable $effective,
    ): void {
        $this->actor($actor);
        $id = new EntityId($record["id"]);
        $root = $this->load($id);
        $before = $this->records->credit(new EntityId($record["credit"]["id"]));
        if ($root->playhead() === 0) {
            $observation = PublishedGradeId::forVersion($operation, "transfer.observation." . $id->value);
            $s = array_intersect_key(
                $record,
                array_flip([
                    "student_id",
                    "equivalency_id",
                    "course_id",
                    "term_id",
                    "completed_on",
                    "institution_id",
                    "institution_code",
                    "institution_name",
                    "external_course_id",
                    "external_code",
                    "external_title",
                    "term_code",
                ]),
            );
            $s += ["award_id" => $id->value, "equivalency" => null, "grade_rank" => null];
            $base = TransferCreditWasEstablished::occur(
                new TransferCreditId($id->value),
                [
                    "decision_id" => $observation->value,
                    "provenance" => "observed_existing",
                    "source" => $s,
                    "credit" => $before,
                    "grade" =>
                        $before["grade_id"] === null
                            ? null
                            : GradeSnapshot::fromRow(
                                $this->grades->snapshot(new EntityId($before["grade_id"])),
                            )->facts,
                ],
                ["__aggregate_type" => "transfer_credit"],
            )->withPlayhead(1);
            $this->history->append(
                $this->stream($id),
                $observation,
                0,
                new EventContext(
                    $actor,
                    $this->clock->now(),
                    $operation,
                    reason: "Existing transfer facts observed before a reviewed correction; " .
                        "earlier decision context is unknown.",
                ),
                [$base],
            );
            $root->acceptHistorical($base);
        }
        $event = TransferCreditWasChanged::occur(
            new TransferCreditId($id->value),
            [
                "decision_id" => $operation->value,
                "previous_decision_id" => $root->decision(),
                "action" => $change->kind,
                "credits_milli" => $change->credits,
                "grade" => $grade === null ? null : GradeSnapshot::fromRow($grade)->facts,
            ],
            ["__aggregate_type" => "transfer_credit"],
        )->withPlayhead($root->playhead() + 1);
        $this->history->append(
            $this->stream($id),
            $operation,
            $root->playhead(),
            new EventContext(
                $actor,
                $effective,
                $operation,
                new EntityId($root->decision() ?? throw new CorruptHistoryException()),
                $change->reason,
            ),
            [$event],
        );
        $root->acceptHistorical($event);
        $facts = $root->credit() ?? throw new CorruptHistoryException();
        $this->records->project($facts, $before["version"]);
        $this->records->track($root->source() ?? throw new CorruptHistoryException(), $facts);
    }
    /** Reconstruct only the requested receipt decisions in one bounded replay.
     * @param list<string> $operations
     * @return array<string,array<string,mixed>> */
    public function details(EntityId $award, array $operations): array
    {
        if (count($operations) > 50) {
            throw new \InvalidArgumentException("Use a bounded correction history page.");
        }
        $wanted = array_fill_keys($operations, true);
        $items = [];
        $root = TransferCredit::root(new TransferCreditId($award->value));
        $cursor = null;
        do {
            $page = $this->history->page($this->stream($award), $cursor, 20);
            foreach ($page->events as $stored) {
                $event = $stored->event;
                $before = $root->credit() === null ? null : $root->display();
                $root->acceptHistorical($event);
                $p = $event->payload();
                if ($event instanceof TransferCreditWasChanged && isset($wanted[$p["decision_id"]])) {
                    $items[$p["decision_id"]] = [
                        "before" => $before,
                        "after" => $root->display(),
                        "reason" => $stored->context->reason,
                        "effective_at" => $stored->context->describe()["effective_at"],
                        "history_source" => "journal",
                        "actor_id" => $stored->context->actor?->value,
                        "action" => $p["action"],
                    ];
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $this->verify($award, $root);
        return $items;
    }
    /** Internal command/read context; caller must authorize and hold the student lock.
     * @return array{TransferCredit,array<string,mixed>}
     */
    public function periodRecord(EntityId $student, EntityId $award, HistoryReadBudget $budget): array
    {
        $row = $this->records->award($award);
        if ($row["student_id"] !== $student->value) {
            throw new \Domain\Shared\Exception\AccessDeniedException("The transfer award belongs to another student.");
        }
        $root = $this->load($award, $budget);
        $credit = $root->credit() ?? $this->records->credit(new EntityId($row["credit_id"]));
        if (
            $credit["student_id"] !== $student->value || $credit["source_id"] !== $award->value ||
            $credit["id"] !== $row["credit_id"] || $credit["term_id"] !== $row["term_id"]
        ) {
            throw new CorruptHistoryException();
        }
        return [$root, $credit];
    }
    private function load(EntityId $award, ?HistoryReadBudget $budget = null): TransferCredit
    {
        $root = TransferCredit::root(new TransferCreditId($award->value));
        $cursor = null;
        do {
            $page = $budget === null
            ? $this->history->page($this->stream($award), $cursor, 20)
            : $budget->page($this->history, $this->stream($award), $cursor);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $this->verify($award, $root);
        return $root;
    }
    private function verify(EntityId $award, TransferCredit $root): void
    {
        $m = $this->records->mapping($award);
        $c = $root->credit();
        if ($c === null) {
            if ($m !== null) {
                throw new CorruptHistoryException();
            }
            $row = $this->records->award($award);
            if ($this->records->courseVersion(new EntityId($row["credit_id"])) !== null) {
                throw new CorruptHistoryException();
            }
            return;
        }
        $s = $root->source() ?? throw new CorruptHistoryException();
        if ($this->records->courseVersion(new EntityId($c["id"]))?->value !== $root->courseVersion()) {
            throw new CorruptHistoryException();
        }
        $a = $this->records->award($award);
        if (
            $m === null ||
            count($m) !== 3 ||
            $m["credit_id"] !== $c["id"] ||
            $m["student_id"] !== $c["student_id"] ||
            $a["credit_id"] !== $c["id"] ||
            $a["student_id"] !== $c["student_id"] ||
            $a["equivalency_id"] !== $s["equivalency_id"] ||
            $a["term_id"] !== $c["term_id"] ||
            $a["completed_on"] !== $s["completed_on"] ||
            !$this->same($this->records->credit(new EntityId($c["id"])), $c)
        ) {
            throw new CorruptHistoryException();
        }
    }
    /** @param array<string,mixed> $a
     * @param array<string,mixed> $b */
    private function same(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $key => $v) {
            if (!array_key_exists($key, $b) || $b[$key] !== $v) {
                return false;
            }
        }
        return true;
    }
    private function actor(EntityId $actor): void
    {
        if ($actor->value !== $this->access->authenticatedUserId()->value) {
            throw new \Domain\Shared\Exception\AccessDeniedException("The transfer decision actor changed.");
        }
    }
    /** Internal curated choices; the calling transfer workflow authorizes its staff scope.
     * @return array<string,mixed>
     */
    public function courseChoices(EntityId $course, ?string $cursor = null): array
    {
        return $this->courses->page($course, $cursor);
    }
    private function stream(EntityId $id): StreamReference
    {
        return new StreamReference(new TransferCreditId($id->value), "transfer_credit");
    }
}
