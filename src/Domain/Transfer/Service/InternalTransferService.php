<?php

declare(strict_types=1);

namespace Domain\Transfer\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\AcademicRecord\Service\WorkPursuitLedger;
use Domain\AcademicRecord\Service\WorkPursuitService;
use Domain\AcademicRecord\ValueObject\GradeSnapshot;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Service\PublishedCourseReader;
use Domain\DegreeAudit\Service\AcademicInputVerifier;
use Domain\Gradebook\Service\AcademicGradeCatalog;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Graduation\Service\GraduationReader;
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
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Transfer\Event\TransferCreditWasEstablished;
use Domain\Transfer\Event\TransferCreditWasChanged;
use Domain\Transfer\Repository\TransferCreditRepository;
use Domain\Transfer\ValueObject\InternalTransferSource;
use Domain\Transfer\ValueObject\TransferCreditId;
use Domain\Transfer\TransferCredit;

use function Codefy\Framework\Helpers\trans;

/** Explicit receiving decisions for institutionally earned work from a genuinely awarded pursuit. */
final readonly class InternalTransferService
{
    public function __construct(
        private TransferCreditRepository $records,
        private TransferCreditLedger $credits,
        private HistoricalEventStore $history,
        private WorkPursuitLedger $scopes,
        private WorkPursuitService $pursuits,
        private AcademicInputVerifier $inputs,
        private GraduationReader $graduation,
        private StudentRecordRepository $students,
        private AdmissionsRepository $admissions,
        private AcademicGradeCatalog $grades,
        private PublishedCourseReader $courses,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs,
        private \Domain\Catalog\Repository\CourseSearchRepository $search,
        private \Domain\Student\Service\StudentProgramStatusLedger $statuses,
        private \Domain\Person\Service\ProfileLedger $profiles,
    ) {
    }
    public function candidates(
        EntityId $student,
        EntityId $assignment,
        ?EntityId $after = null,
    ): array {
        $actor = $this->authorize();
        return $this->transactions->run(function () use (
            $student,
            $assignment,
            $after,
            $actor,
        ): array {
            $this->lock($student, $actor);
            $selection = $this->pursuits->activeSelection(
                $student,
                $assignment,
            );
            $budget = new HistoryReadBudget();
            $work = $this->inputs->verify($student, $budget);
            $states = $this->graduation->student($student, $budget);
            $items = [];
            $sorted = $work->items;
            usort(
                $sorted,
                static fn(array $a, array $b): int => strcmp(
                    $a["id"],
                    $b["id"],
                ),
            );
            foreach ($sorted as $item) {
                if (
                    $after !== null &&
                    strcmp($item["id"], $after->value) <= 0
                ) {
                    continue;
                }
                $scope = $this->scopes->load(
                    new EntityId($item["id"]),
                    $budget,
                );
                $owner = $scope->projection()["owner_assignment_id"] ?? null;
                $state = $owner === null ? null : $states[$owner] ?? null;
                if (
                    !$this->eligible(
                        $item,
                        $state,
                        $owner,
                        $assignment->value,
                    ) ||
                    $this->records->internalExisting(
                        $student,
                        $assignment,
                        new EntityId($item["id"]),
                    ) !== null
                ) {
                    continue;
                }
                $items[] = $this->sourceSummary($item, $state, $owner);
                if (count($items) === 11) {
                    break;
                }
            }
            $this->authorize($actor);
            return [
                "items" => array_slice($items, 0, 10),
                "next_after" =>
                    count($items) > 10 ? $items[9]["source_credit_id"] : null,
                "receiving" => $this->targetSummary($student, $assignment),
                "grades" => $this->gradeChoices($student, $assignment),
            ];
        });
    }
    public function page(
        EntityId $student,
        EntityId $assignment,
        ?EntityId $after = null,
    ): array {
        $actor = $this->authorize();
        return $this->transactions->run(function () use (
            $student,
            $assignment,
            $after,
            $actor,
        ): array {
            $this->lock($student, $actor);
            $target = $this->targetSummary($student, $assignment);
            $rows = $this->records->internalPage($student, $assignment, $after);
            $items = [];
            $budget = new HistoryReadBudget();
            foreach (array_slice($rows, 0, 10) as $row) {
                $items[] = $this->read(
                    $student,
                    $assignment,
                    new EntityId($row["id"]),
                    $budget,
                );
            }
            $this->authorize($actor);
            return [
                "items" => $items,
                "next_after" => count($rows) > 10 ? $rows[9]["id"] : null,
                "receiving" => $target,
            ];
        });
    }
    public function view(
        EntityId $student,
        EntityId $assignment,
        EntityId $award,
    ): array {
        $actor = $this->authorize();
        return $this->transactions->run(function () use (
            $student,
            $assignment,
            $award,
            $actor,
        ): array {
            $this->lock($student, $actor);
            $data = $this->read($student, $assignment, $award);
            $this->authorize($actor);
            return $data;
        });
    }
    public function search(
        EntityId $student,
        EntityId $assignment,
        string $query,
        ?EntityId $after = null,
    ): array {
        $actor = $this->authorize();
        $this->targetSummary($student, $assignment);
        if (
            !mb_check_encoding($query, "UTF-8") ||
            mb_strlen($query) > 80 ||
            preg_match('/[\x00-\x1f\x7f]/', $query)
        ) {
            throw new \InvalidArgumentException("Use a bounded course search.");
        }
        $rows =
        trim($query) === ""
        ? []
        : $this->search->page(trim($query), $after);
        $this->authorize($actor);
        return [
            "items" => array_slice($rows, 0, 20),
            "next_after" => count($rows) > 20 ? $rows[19]["id"] : null,
        ];
    }
    public function publications(
        EntityId $student,
        EntityId $assignment,
        EntityId $course,
        ?string $cursor = null,
    ): array {
        $actor = $this->authorize();
        $this->targetSummary($student, $assignment);
        $page = $this->courses->page($course, $cursor);
        $this->authorize($actor);
        return $page;
    }
    public function review(
        EntityId $student,
        EntityId $assignment,
        string $action,
        array $request,
        string $reason,
    ): array {
        $actor = $this->authorize();
        $reason = $this->reason($reason);
        $this->request($action, $request);
        return $this->transactions->run(function () use (
            $student,
            $assignment,
            $action,
            $request,
            $reason,
            $actor,
        ): array {
            $this->lock($student, $actor);
            $context = $this->context($student, $assignment, $action, $request);
            $operation = EntityId::generate();
            $revision = InternalTransferSource::hash($context);
            $intent = $this->intent(
                $student,
                $assignment,
                $action,
                $request,
                $reason,
            );
            return [
                "operation_id" => $operation->value,
                "action" => $action,
                "request" => $request,
                "reason" => $reason,
                "revision" => $revision,
                "review_token" => $this->proofs->issue(
                    "internal-transfer",
                    $action,
                    $assignment,
                    $actor,
                    $operation,
                    InternalTransferSource::hash([$intent, $revision]),
                ),
                "summary" => $this->summary($context),
            ];
        });
    }
    public function confirm(
        EntityId $student,
        EntityId $assignment,
        EntityId $operation,
        string $action,
        array $request,
        string $reason,
        string $revision,
        string $token,
    ): array {
        $actor = $this->authorize();
        $reason = $this->reason($reason);
        $this->request($action, $request);
        return $this->transactions->run(function () use (
            $student,
            $assignment,
            $operation,
            $action,
            $request,
            $reason,
            $revision,
            $token,
            $actor,
        ): array {
            $this->lock($student, $actor);
            $this->targetSummary($student, $assignment);
            $award =
                $action === "grant"
                    ? $operation
                    : new EntityId($request["award_id"]);
            $intent = $this->intent(
                $student,
                $assignment,
                $action,
                $request,
                $reason,
            );
            $old = $this->receipt($student, $assignment, $award, $operation);
            $this->proofs->validate(
                $token,
                "internal-transfer",
                $action,
                $assignment,
                $actor,
                $operation,
                InternalTransferSource::hash([$intent, $revision]),
                $old !== null,
            );
            if ($old !== null) {
                $p = $old->event->payload();
                $same =
                    $action === "grant"
                        ? ($p["source"]["internal"]["intent"] ?? null) ===
                            $intent
                        : $old->event instanceof TransferCreditWasChanged &&
                            $p["action"] ===
                                ($action === "amend" ? "amend" : $action) &&
                            $p["credits_milli"] ===
                                ($request["credits_milli"] ?? null) &&
                            ($p["grade"]["code"] ?? null) ===
                                ($request["grade_code"] ?? null);
                if (
                    !$same ||
                    $old->context->actor?->value !== $actor->value ||
                    $old->context->reason !== $reason
                ) {
                    throw new ConcurrentModificationException(
                        "This receiving decision was already used.",
                    );
                }
                return [
                    "award_id" => $award->value,
                    "operation_id" => $operation->value,
                    "status" => "recorded",
                ];
            }
            $context = $this->context($student, $assignment, $action, $request);
            $this->same($revision, InternalTransferSource::hash($context));
            $this->audit->record(
                $actor,
                "transfer.internal_" . $action,
                $award,
            );
            $this->authorize($actor);
            $this->proofs->validate(
                $token,
                "internal-transfer",
                $action,
                $assignment,
                $actor,
                $operation,
                InternalTransferSource::hash([$intent, $revision]),
            );
            $this->same(
                $revision,
                InternalTransferSource::hash(
                    $this->context($student, $assignment, $action, $request),
                ),
            );
            if ($action === "grant") {
                $this->establish(
                    $student,
                    $assignment,
                    $award,
                    $context,
                    $intent,
                    $reason,
                    $actor,
                );
            } else {
                $this->change(
                    $student,
                    $award,
                    $operation,
                    $action,
                    $context,
                    $request,
                    $reason,
                    $actor,
                );
            }
            $this->authorize($actor);
            return [
                "award_id" => $award->value,
                "operation_id" => $operation->value,
                "status" => "recorded",
            ];
        });
    }
    public function timeline(
        EntityId $student,
        EntityId $assignment,
        EntityId $award,
        ?string $cursor = null,
    ): array {
        $actor = $this->authorize();
        return $this->transactions->run(function () use (
            $student,
            $assignment,
            $award,
            $cursor,
            $actor,
        ): array {
            $this->lock($student, $actor);
            $this->read($student, $assignment, $award);
            $page = $this->history->page($this->stream($award), $cursor, 20);
            $items = [];
            foreach ($page->events as $stored) {
                $p = $stored->event->payload();
                $items[] = [
                    "action" =>
                        $stored->event instanceof TransferCreditWasEstablished
                            ? "grant"
                            : ($stored->event instanceof
                            \Domain\Transfer\Event\TransferPostingTermWasChanged
                                ? "posting_term_changed"
                                : $p["action"] ?? "course"),
                    "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
                    "effective_at" => $stored->context->effectiveAt?->format(
                        DATE_ATOM,
                    ),
                    "reason" => $stored->context->reason,
                    "credits_milli" =>
                        $p["credits_milli"] ??
                        ($p["credit"]["completed_milli"] ?? null),
                    "grade_code" => $p["grade"]["code"] ?? null,
                ];
            }
            $this->authorize($actor);
            return ["items" => $items, "next_cursor" => $page->nextCursor];
        });
    }
    private function context(
        EntityId $student,
        EntityId $assignment,
        string $action,
        array $request,
    ): array {
        $budget = new HistoryReadBudget();
        $target = $this->targetSummary($student, $assignment);
        $record = null;
        if ($action === "grant") {
            $this->pursuits->activeSelection($student, $assignment);
            $source = new EntityId($request["source_credit_id"]);
            if (
                $this->records->internalExisting(
                    $student,
                    $assignment,
                    $source,
                ) !== null
            ) {
                self::invalid(
                    trans(
                        "This source already has a receiving evaluation. Review that award instead.",
                    ),
                );
            }
        } else {
            $record = $this->read(
                $student,
                $assignment,
                new EntityId($request["award_id"]),
            );
            if (
                ($action === "reinstate") !==
                ($record["credit"]["status"] === "V")
            ) {
                self::invalid(
                    trans(
                        "Choose a decision available for the current transfer status.",
                    ),
                );
            }
            if ($action === "void") {
                return [
                    "receiving" => $target,
                    "record" => $record,
                    "source" => null,
                    "publication" => null,
                    "grade" => null,
                    "quantity" => null,
                ];
            }
            if (
                $action === "amend" &&
                $request["credits_milli"] ===
                    $record["credit"]["completed_milli"] &&
                $request["grade_code"] === $record["credit"]["grade_code"]
            ) {
                self::invalid(
                    trans(
                        "Change the receiving credit amount or grade before reviewing a correction.",
                    ),
                );
            }
            $source = new EntityId($record["source"]["source_credit_id"]);
        }
        $set =
        $this->inputs->selected($source, $budget) ??
        throw new RecordNotFoundException("Source coursework not found.");
        $work = $set->items[0] ?? null;
        if (
            $work === null ||
            ($set->references[0]["origin"] ?? null) !== "institutional" ||
            ($set->references[0]["student_id"] ?? $student->value) !==
                $student->value
        ) {
            self::invalid(
                trans(
                    "Choose verified institutional coursework for this student.",
                ),
            );
        }
        $scope = $this->scopes->load($source, $budget);
        $p = $scope->projection();
        if ($p === null || $p["source"]["student_id"] !== $student->value) {
            throw new AccessDeniedException();
        }
        $owner = $p["owner_assignment_id"];
        $state = $this->graduation
            ->load($student, new EntityId($owner), $budget)
            ->state();
        if (!$this->eligible($work, $state, $owner, $assignment->value)) {
            self::invalid(
                trans(
                    "Use passed, dated academic credit from a genuinely conferred different pursuit.",
                ),
            );
        }
        $reference = $set->references[0];
        $sourceFacts = $this->sourceSummary($work, $state, $owner) + [
            "graduation_version" => $state["version"],
            "academic_version" => $reference["academic_version"],
            "registration_version" => $reference["registration_version"],
            "pursuit_version" => $scope->playhead(),
            "source_hash" => InternalTransferSource::hash([
                $work,
                $reference,
                $p,
                $state["credential"],
                $state["version"],
            ]),
            "grade" => $work["grade"],
        ];
        $course = new EntityId(
            $action === "grant"
                ? $request["course_id"]
                : $record["credit"]["course_id"],
        );
        $version = new EntityId(
            $action === "grant"
                ? $request["course_version_id"]
                : $record["course_version_id"],
        );
        $publication = $this->courses->version($course, $version, $budget);
        $definition = $publication->definition;
        $quantity = $request["credits_milli"];
        $maximum =
        $definition->creditPolicy?->maximumMilli ??
        $definition->creditsMilli;
        if (
            $maximum < 1 ||
            $quantity > $work["completed_milli"] ||
            $quantity > $maximum
        ) {
            self::invalid(
                trans(
                    "The receiving academic quantity must fit both the earned source and the published " .
                        "receiving course.",
                ),
            );
        }
        $gradeRow = $this->grades->byCode(
            $request["grade_code"],
            $student,
            $record === null
                ? null
                : new EntityId($record["retained_grade_id"]),
            $assignment,
        );
        $grade = GradeSnapshot::fromRow($gradeRow)->facts;
        if (!$grade["passes"] || $grade["scale_version_id"] === null) {
            self::invalid(
                trans(
                    "Choose a passing grade from a published receiving grading scale.",
                ),
            );
        }
        $term = $this->records->term(
            new EntityId(
                $action === "grant"
                    ? $request["term_id"]
                    : $record["credit"]["term_id"],
            ),
            true,
        );
        return [
            "receiving" => $target,
            "source" => $sourceFacts,
            "record" => $record,
            "publication" => $publication->describe(),
            "grade" => $grade,
            "quantity" => $quantity,
            "term" => ["id" => $term["id"], "code" => $term["code"]],
        ];
    }
    private function establish(
        EntityId $student,
        EntityId $assignment,
        EntityId $award,
        array $context,
        string $intent,
        string $reason,
        EntityId $actor,
    ): void {
        $source = $context["source"];
        $lineage = $source + [
            "person" => $context["receiving"]["identity_reference"],
            "receiving_assignment_id" => $assignment->value,
            "intent" => $intent,
        ];
        $lineage = array_intersect_key(
            $lineage,
            array_flip(InternalTransferSource::FIELDS),
        );
        InternalTransferSource::validate($lineage);
        $publication = \Domain\Catalog\ValueObject\PublishedCourseDefinition::fromPublication(
            new EntityId($context["publication"]["course_id"]),
            [
                "version_id" => $context["publication"]["version_id"],
                "definition" => $context["publication"]["definition"],
            ],
        );
        $creditId = PublishedGradeId::forVersion(
            $award,
            "internal.transfer.credit",
        );
        $quantity = $context["quantity"];
        $grade = $context["grade"];
        $credit = [
            "id" => $creditId->value,
            "student_id" => $student->value,
            "course_id" => $publication->courseId->value,
            "term_id" => $context["term"]["id"],
            "source_id" => $award->value,
            "credit_type" => "transfer",
            "course_code" => $publication->definition->code,
            "course_title" => $publication->definition->title,
            "attempted_milli" => $quantity,
            "completed_milli" => $quantity,
            "grade_points_milli" => $grade["counts_gpa"]
                ? intdiv($quantity * $grade["points_milli"], 1000)
                : 0,
            "grade_id" => $grade["id"],
            "status" => "A",
            "version" => 0,
        ];
        $s = [
            "award_id" => $award->value,
            "student_id" => $student->value,
            "equivalency_id" => null,
            "course_id" => $credit["course_id"],
            "term_id" => $credit["term_id"],
            "completed_on" => $source["completed_on"],
            "institution_id" => null,
            "institution_code" => null,
            "institution_name" => null,
            "external_course_id" => null,
            "external_code" => null,
            "external_title" => null,
            "term_code" => $context["term"]["code"],
            "equivalency" => null,
            "grade_rank" => null,
            "internal" => $lineage,
        ];
        $event = TransferCreditWasEstablished::occur(
            new TransferCreditId($award->value),
            [
                "decision_id" => $award->value,
                "provenance" => "internal_award",
                "source" => $s,
                "credit" => $credit,
                "grade" => $grade,
            ],
            [
                "__aggregate_type" => "transfer_credit",
                "course_version_id" => $publication->versionId->value,
            ],
        )->withPlayhead(1);
        $this->history->append(
            $this->stream($award),
            $award,
            0,
            new EventContext(
                $actor,
                $this->clock->now(),
                $award,
                reason: $reason,
            ),
            [$event],
        );
        $root = TransferCredit::root(new TransferCreditId($award->value));
        $root->acceptHistorical($event);
        $this->records->create($s, $credit, $publication);
        $this->scopes->attributeNew($credit, $assignment, $actor);
        $this->read($student, $assignment, $award);
    }
    private function change(
        EntityId $student,
        EntityId $award,
        EntityId $operation,
        string $action,
        array $context,
        array $request,
        string $reason,
        EntityId $actor,
    ): void {
        [$root, $before] = $this->credits->decisionRecord(
            $student,
            $award,
            new HistoryReadBudget(),
        );
        $event = TransferCreditWasChanged::occur(
            new TransferCreditId($award->value),
            [
                "decision_id" => $operation->value,
                "previous_decision_id" => $root->decision(),
                "action" => $action,
                "credits_milli" =>
                    $action === "void" ? null : $request["credits_milli"],
                "grade" => $action === "void" ? null : $context["grade"],
            ],
            ["__aggregate_type" => "transfer_credit"],
        )->withPlayhead($root->playhead() + 1);
        $this->history->append(
            $this->stream($award),
            $operation,
            $root->playhead(),
            new EventContext(
                $actor,
                $this->clock->now(),
                $operation,
                new EntityId($root->decision()),
                $reason,
            ),
            [$event],
        );
        $root->acceptHistorical($event);
        $this->records->project($root->credit(), $before["version"]);
        $this->records->track($root->source(), $root->credit());
    }
    private function read(
        EntityId $student,
        EntityId $assignment,
        EntityId $award,
        ?HistoryReadBudget $budget = null,
    ): array {
        $budget ??= new HistoryReadBudget();
        $this->targetSummary($student, $assignment);
        $row =
        $this->records->internal($award) ??
        throw new RecordNotFoundException("Receiving transfer not found.");
        if (
            $row["student_id"] !== $student->value ||
            $row["receiving_assignment_id"] !== $assignment->value
        ) {
            throw new AccessDeniedException();
        }
        [$root, $credit] = $this->credits->decisionRecord(
            $student,
            $award,
            $budget,
        );
        $s = $root->source()["internal"] ?? throw new CorruptHistoryException();
        $scope = $this->scopes
            ->load(new EntityId($credit["id"]), $budget)
            ->projection();
        if (
            ($scope["owner_assignment_id"] ?? null) !== $assignment->value ||
            ($scope["reuse"] ?? []) !== []
        ) {
            throw new CorruptHistoryException();
        }
        return [
            "id" => $award->value,
            "credit" => $root->display(),
            "source" => $s,
            "completed_on" => $root->source()["completed_on"],
            "retained_grade_id" => $root->grade()["id"],
            "retained_grade_code" => $root->grade()["code"],
            "retained_credits_milli" => $root->retainedQuantity(),
            "term_code" => $root->source()["term_code"],
            "course_version_id" => $root->courseVersion(),
            "history_version" => $root->playhead(),
            "academic_on" => $root->academicOn(),
            "effective_on_default" => $this->clock->now()->format("Y-m-d"),
            "grades" => $this->gradeChoices(
                $student,
                $assignment,
                new EntityId($root->grade()["id"]),
            ),
        ];
    }
    private function receipt(
        EntityId $student,
        EntityId $assignment,
        EntityId $award,
        EntityId $operation,
    ): ?\Domain\Shared\EventSourcing\StoredEvent {
        if ($this->records->internal($award) === null) {
            return null;
        }
        $this->read($student, $assignment, $award);
        $budget = new HistoryReadBudget();
        $cursor = null;
        do {
            $page = $budget->page(
                $this->history,
                $this->stream($award),
                $cursor,
            );
            foreach ($page->events as $event) {
                if (
                    ($event->event->payload()["decision_id"] ?? null) ===
                    $operation->value
                ) {
                    return $event;
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        return null;
    }
    private function eligible(
        array $work,
        ?array $state,
        ?string $owner,
        string $target,
    ): bool {
        return $work["credit_type"] === "institutional" &&
        ($work["basis"] ?? "standard") === "standard" &&
        empty($work["continuing_education_only"]) &&
        $work["history_recorded"] &&
        !$work["earlier_history_unknown"] &&
        $work["completed_milli"] > 0 &&
        $work["status"] === "A" &&
        ($work["grade"]["passes"] ?? false) &&
        is_string($work["completed_on"]) &&
        $work["completed_on"] <= $this->clock->now()->format("Y-m-d") &&
        $owner !== null &&
        $owner !== $target &&
        ($state["credential"] ?? null) !== null &&
        $work["completed_on"] <=
        $state["credential"]["proposal"]["awarded_on"];
    }
    private function sourceSummary(
        array $work,
        array $state,
        string $owner,
    ): array {
        return [
            "source_credit_id" => $work["id"],
            "source_pursuit_id" => $owner,
            "source_credential_id" => $state["credential"]["id"],
            "course_id" => $work["course_id"],
            "course_code" => $work["course_code"],
            "course_title" => $work["course_title"],
            "credits_milli" => $work["completed_milli"],
            "completed_on" => $work["completed_on"],
            "grade" => $work["grade"],
            "source_program_code" => $state["credential"]["program"]["code"],
            "source_program_title" => $state["credential"]["program"]["title"],
            "credential_title" => $state["credential"]["proposal"]["title"],
            "credential_awarded_on" =>
                $state["credential"]["proposal"]["awarded_on"],
        ];
    }
    private function targetSummary(
        EntityId $student,
        EntityId $assignment,
    ): array {
        $p = $this->students->assignment($assignment);
        if ($p->studentId->value !== $student->value) {
            throw new AccessDeniedException();
        }
        $program = $this->students->program($p->programId);
        $person = $this->admissions->person(
            $this->admissions->student($student)->personId,
        );
        $identity = $this->profiles->coreReference(
            $person,
            new HistoryReadBudget(),
        );
        return [
            "identity" => [
                "name" => trim($person->givenName . " " . $person->familyName),
                "institutional_id" => $person->institutionalId->value,
            ],
            "identity_reference" => $identity,
            "student_id" => $student->value,
            "assignment_id" => $assignment->value,
            "program_id" => $p->programId->value,
            "program_code" => $program["code"],
            "program_title" => $program["title"],
            "status" => $p->status,
            "version" => $this->statuses->verify($p, new HistoryReadBudget()),
        ];
    }
    private function gradeChoices(
        EntityId $student,
        EntityId $assignment,
        ?EntityId $retained = null,
    ): array {
        return array_values(
            array_map(
                static fn(array $r): array => [
                    "code" => $r["code"],
                    "counts_gpa" => (bool) $r["counts_gpa"],
                ],
                array_filter(
                    $this->grades->choices($student, $retained, $assignment),
                    static fn(array $r): bool => (bool) $r["passes"] &&
                        $r["scale_version_id"] !== null,
                ),
            ),
        );
    }
    private function summary(array $context): array
    {
        return [
            "receiving" => $context["receiving"],
            "source" =>
                $context["source"] ?? ($context["record"]["source"] ?? null),
            "before" => $context["record"]["credit"] ?? null,
            "course" => $context["publication"],
            "credits_milli" => $context["quantity"],
            "grade_code" => $context["grade"]["code"] ?? null,
            "counts_gpa" => $context["grade"]["counts_gpa"] ?? false,
            "term" => $context["term"] ?? null,
        ];
    }
    private function request(string $action, array $r): void
    {
        $keys = match ($action) {
            "grant" => [
                "source_credit_id",
                "course_id",
                "course_version_id",
                "term_id",
                "credits_milli",
                "grade_code",
            ],
            "amend", "reinstate" => ["award_id", "credits_milli", "grade_code"],
            "void" => ["award_id"],
            default => throw new \InvalidArgumentException(
                "Unknown receiving action.",
            ),
        };
        if (
            count($keys) !== count($r) ||
            array_diff(array_keys($r), $keys) !== []
        ) {
            throw new \InvalidArgumentException(
                "Explicit receiving decision details are required.",
            );
        }
        foreach ($r as $key => $value) {
            if (str_ends_with($key, "_id")) {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException(
                        "Use a valid receiving reference.",
                    );
                }
                new EntityId($value);
            }
        }
        if (
            $action !== "void" &&
            (!is_int($r["credits_milli"]) ||
                $r["credits_milli"] < 1 ||
                $r["credits_milli"] > 999999 ||
                !is_string($r["grade_code"]) ||
                preg_match('/^[A-Z0-9+.-]{1,12}$/D', $r["grade_code"]) !== 1)
        ) {
            throw new \InvalidArgumentException(
                "Choose valid academic units and a published grade.",
            );
        }
    }
    private function intent(
        EntityId $student,
        EntityId $assignment,
        string $action,
        array $request,
        string $reason,
    ): string {
        return InternalTransferSource::hash([
            $student->value,
            $assignment->value,
            $action,
            $request,
            $reason,
        ]);
    }
    private function reason(string $reason): string
    {
        if (
            !mb_check_encoding($reason, "UTF-8") ||
            trim($reason) === "" ||
            mb_strlen($reason) > 1000 ||
            preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $reason)
        ) {
            self::invalid(
                trans("Enter an evaluation reason of 1 to 1,000 characters."),
            );
        }
        return trim($reason);
    }
    private function authorize(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission("sis:transfer:manage");
        if (
            $this->access->requirePermission("sis:student:read")->value !==
                $actor->value ||
            $this->access->authenticatedUserId()->value !== $actor->value ||
            ($expected !== null && $expected->value !== $actor->value)
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }
    private function lock(EntityId $student, EntityId $actor): void
    {
        $person = $this->admissions->student($student)->personId;
        $this->admissions->lockPerson($person);
        $this->students->lock($student);
        $this->authorize($actor);
    }
    private function stream(EntityId $award): StreamReference
    {
        return new StreamReference(
            new TransferCreditId($award->value),
            "transfer_credit",
        );
    }
    private function same(string $a, string $b): void
    {
        if (!hash_equals($a, $b)) {
            throw new ConcurrentModificationException(
                "The source or receiving evaluation changed. Review it again.",
            );
        }
    }
    private static function invalid(string $message): never
    {
        throw new ValidationException([
            ["field" => "evaluation", "message" => $message],
        ]);
    }
}
