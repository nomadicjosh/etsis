<?php

declare(strict_types=1);

namespace Domain\Graduation\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\DegreeAudit\Service\DegreeProgressService;
use Domain\DegreeAudit\ValueObject\DegreeProgressSnapshot;
use Domain\Graduation\Event\GraduationDecisionWasRecorded;
use Domain\Graduation\Repository\GraduationRepository;
use Domain\Graduation\ValueObject\GraduationId;
use Domain\Graduation\ValueObject\GraduationSchema;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\Service\StudentProgramStatusLedger;
use Domain\Student\Service\StudentSpecializationService;

final readonly class GraduationService
{
    public const string REVIEW = "sis:graduation:review";
    public const string CONFER = "sis:graduation:confer";

    public function __construct(
        private GraduationReader $reader,
        private GraduationRepository $records,
        private HistoricalEventStore $history,
        private DegreeProgressService $progress,
        private StudentRecordRepository $students,
        private StudentProgramStatusLedger $statuses,
        private StudentSpecializationService $curriculum,
        private AdmissionsRepository $admissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private ReviewProof $proofs,
        private Clock $clock,
        private \Domain\Person\Service\ProfileLedger $profiles,
        private \Domain\Person\Repository\PersonProfileRepository $people,
    ) {
    }

    public function current(
        EntityId $student,
        EntityId $pursuit,
        string $channel,
    ): array {
        $actor = $this->authorize($student, $pursuit, $channel);
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $channel,
            $actor,
        ): array {
            $this->students->lock($student);
            $budget = new HistoryReadBudget();
            $personId = $this->admissions->student($student)->personId;
            $this->people->lock($personId);
            $person = $this->admissions->person($personId);
            $this->profiles->coreReference($person, $budget);
            $root = $this->reader->load($student, $pursuit, $budget);
            $state = $root->state();
            $eligibility = $state["eligibility"];
            $result = [
                "identity" => ['name' => trim($person->givenName . ' ' . $person->familyName),
                    'institutional_id' => $person->institutionalId->value],
                "version" => $root->playhead(),
                "credential" => $state["credential"],
                "ever_awarded" => $state["ever_awarded"],
                "eligibility" =>
                    $eligibility === null
                        ? null
                        : [
                            "eligible" => $eligibility["eligible"],
                            "proposal" => $eligibility["proposal"],
                            "as_of_date" =>
                                $eligibility["snapshot"]["as_of_date"],
                        ],
                "can_review" =>
                    $channel === "staff" &&
                    $this->students->assignment($pursuit)->status ===
                        "active" &&
                    $state["credential"] === null &&
                    $this->can(self::REVIEW),
                "can_confer" =>
                    $channel === "staff" && $this->can(self::CONFER),
            ];
            $this->authorize($student, $pursuit, $channel, $actor);
            return $result;
        });
    }

    public function timeline(
        EntityId $student,
        EntityId $pursuit,
        string $channel,
        ?string $cursor = null,
    ): array {
        $actor = $this->authorize($student, $pursuit, $channel);
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $channel,
            $cursor,
            $actor,
        ): array {
            $this->students->lock($student);
            $budget = new HistoryReadBudget();
            $root = $this->reader->load($student, $pursuit, $budget);
            $page = $this->history->page(
                GraduationReader::stream($pursuit),
                $cursor,
                10,
            );
            if ($page->throughVersion > $root->playhead()) {
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            }
            $items = [];
            foreach ($page->events as $stored) {
                $p = $stored->event->payload();
                $items[] = [
                    "action" => $p["action"],
                    "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
                    "credential" => $p["details"]["credential"] ?? null,
                    "eligible" => $p["details"]["eligible"] ?? null,
                ];
            }
            $this->audit->record($actor, "graduation.history_read", $pursuit);
            $this->authorize($student, $pursuit, $channel, $actor);
            return ["items" => $items, "next_cursor" => $page->nextCursor];
        });
    }

    public function review(
        EntityId $student,
        EntityId $pursuit,
        string $action,
        array $request,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize($student, $pursuit, $action);
        $this->reason($reason);
        $this->request($action, $request);
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $action,
            $request,
            $reason,
            $actor,
        ): array {
            $this->students->lock($student);
            $operation = EntityId::generate();
            $context = $this->context(
                $student,
                $pursuit,
                $operation,
                $action,
                $request,
                new HistoryReadBudget(),
            );
            $revision = GraduationSchema::hash($context);
            $intent = $this->intent(
                $student,
                $pursuit,
                $action,
                $request,
                $revision,
                $reason,
            );
            $this->authorize($student, $pursuit, $action, $actor);
            $details = $context["details"];
            return [
                "student_id" => $student->value,
                "pursuit_id" => $pursuit->value,
                "operation_id" => $operation->value,
                "action" => $action,
                "request" => $request,
                "revision" => $revision,
                "reason" => trim($reason),
                "summary" => [
                    "identity" => $context["identity_label"],
                    "eligible" => $details["eligible"] ?? null,
                    "proposal" =>
                        $details["proposal"] ??
                        ($details["credential"]["proposal"] ??
                            ($context["current_credential"]["proposal"] ??
                                null)),
                    "term" => isset($details["proposal"])
                        ? $this->students->term(
                            new EntityId($details["proposal"]["term_id"]),
                        )
                        : $details["credential"]["term"] ??
                            ($context["current_credential"]["term"] ?? null),
                    "progress" => isset($details["snapshot"])
                        ? new DegreeProgressSnapshot(
                            $details["snapshot"],
                        )->summary()
                        : null,
                    "closes_pursuit" => $action === "confer",
                ],
                "review_token" => $this->proofs->issue(
                    "graduation",
                    $action,
                    $pursuit,
                    $actor,
                    $operation,
                    $intent,
                ),
            ];
        });
    }

    public function confirm(
        EntityId $student,
        EntityId $pursuit,
        EntityId $operation,
        string $action,
        array $request,
        string $revision,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->authorize($student, $pursuit, $action);
        $this->reason($reason);
        $this->request($action, $request);
        $intent = $this->intent(
            $student,
            $pursuit,
            $action,
            $request,
            $revision,
            $reason,
        );
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $operation,
            $action,
            $request,
            $revision,
            $reason,
            $token,
            $actor,
            $intent,
        ): array {
            $this->students->lock($student);
            $budget = new HistoryReadBudget();
            $root = $this->reader->load($student, $pursuit, $budget);
            $receipt = $root->receipt($operation->value);
            $this->proofs->validate(
                $token,
                "graduation",
                $action,
                $pursuit,
                $actor,
                $operation,
                $intent,
                $receipt !== null,
            );
            if ($receipt !== null) {
                if (
                    $receipt["actor"] !== $actor->value ||
                    $receipt["intent"] !== $intent ||
                    $receipt["action"] !== $action
                ) {
                    throw new AccessDeniedException();
                }
                return [
                    "status" => "recorded",
                    "operation_id" => $operation->value,
                    "action" => $action,
                ];
            }
            $context = $this->context(
                $student,
                $pursuit,
                $operation,
                $action,
                $request,
                $budget,
            );
            $this->same($revision, GraduationSchema::hash($context));
            $this->audit->record($actor, "graduation." . $action, $pursuit);
            $this->authorize($student, $pursuit, $action, $actor);
            $this->proofs->validate(
                $token,
                "graduation",
                $action,
                $pursuit,
                $actor,
                $operation,
                $intent,
            );
            $this->same(
                $revision,
                GraduationSchema::hash(
                    $this->context(
                        $student,
                        $pursuit,
                        $operation,
                        $action,
                        $request,
                        $budget,
                    ),
                ),
            );
            // All academic writers share this lock. Final source/audit checks precede intentional closure.
            $state = $root->state();
            $at = $this->clock->now();
            if ($action === "confer") {
                $this->statuses->change(
                    $this->students->assignment($pursuit),
                    "closed",
                    PublishedGradeId::forVersion(
                        $operation,
                        "graduation.close",
                    ),
                    $actor,
                    trim($reason),
                    correlation: $operation,
                );
            }
            $payload = [
                "decision_id" => $operation->value,
                "previous_decision_id" => $state["decision_id"],
                "student_id" => $student->value,
                "identity" => $context["identity"],
                "action" => $action,
                "details" => $context["details"],
                "revision" => $revision,
                "intent" => $intent,
            ];
            $event = GraduationDecisionWasRecorded::occur(
                new GraduationId($pursuit->value),
                $payload,
                ["__aggregate_type" => "graduation"],
            )->withPlayhead($root->playhead() + 1);
            $this->history->append(
                GraduationReader::stream($pursuit),
                $operation,
                $root->playhead(),
                new EventContext(
                    $actor,
                    $at,
                    $operation,
                    $state["decision_id"] === null
                        ? null
                        : new EntityId($state["decision_id"]),
                    trim($reason),
                ),
                [$event],
            );
            $stored = $this->history->latest(
                GraduationReader::stream($pursuit),
            );
            if (
                $stored === null ||
                $stored->event->payload()["decision_id"] !== $operation->value
            ) {
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            }
            $root->accept($stored);
            $this->records->save($root, $state["version"]);
            $this->authorize($student, $pursuit, $action, $actor);
            return [
                "status" => "recorded",
                "operation_id" => $operation->value,
                "action" => $action,
            ];
        });
    }

    public function reviewGroup(
        array $candidates,
        #[\SensitiveParameter] string $reason,
    ): array {
        $this->access->requirePermission(self::CONFER);
        $this->reason($reason);
        if (
            !array_is_list($candidates) ||
            count($candidates) < 1 ||
            count($candidates) > 10
        ) {
            self::invalid("Choose between one and ten candidates.");
        }
        $selected = $locks = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                self::invalid("Choose valid graduation candidates.");
            }
            if (
                count($candidate) !== 2 ||
                array_diff(array_keys($candidate), [
                    "student_id",
                    "pursuit_id",
                ]) !== [] ||
                !is_string($candidate["student_id"]) ||
                !is_string($candidate["pursuit_id"])
            ) {
                self::invalid("Choose valid graduation candidates.");
            }
            $student = new EntityId($candidate["student_id"]);
            $pursuit = new EntityId($candidate["pursuit_id"]);
            if (isset($selected[$pursuit->value])) {
                self::invalid("Choose each pursuit once.");
            }
            $selected[$pursuit->value] = [$student, $pursuit];
            $locks[$student->value] = $student;
        }
        ksort($locks, SORT_STRING);
        return $this->transactions->run(function () use (
            $locks,
            $selected,
            $reason,
        ): array {
            foreach ($locks as $student) {
                $this->students->lock($student);
            }
            $reviews = [];
            foreach ($selected as [$student, $pursuit]) {
                $reviews[] = $this->review(
                    $student,
                    $pursuit,
                    "confer",
                    [],
                    $reason,
                );
            }
            return ["reviews" => $reviews];
        });
    }

    /** One bounded, atomic ceremony selection; every entry retains its own review, authority and receipt. */
    public function confirmGroup(array $reviews): array
    {
        $this->access->requirePermission(self::CONFER);
        if (
            !array_is_list($reviews) ||
            count($reviews) < 1 ||
            count($reviews) > 10
        ) {
            self::invalid("Choose between one and ten reviewed candidates.");
        }
        $selected = $locks = [];
        foreach ($reviews as $review) {
            if (
                !is_array($review) ||
                ($review["action"] ?? null) !== "confer"
            ) {
                self::invalid("Review each selected candidate for conferral.");
            }
            foreach (
                [
                    "student_id",
                    "pursuit_id",
                    "operation_id",
                    "revision",
                    "reason",
                    "review_token",
                ] as $key
            ) {
                if (!is_string($review[$key] ?? null)) {
                    self::invalid(
                        "Review each selected candidate for conferral.",
                    );
                }
            }
            if (($review["request"] ?? null) !== []) {
                self::invalid("Review each selected candidate for conferral.");
            }
            $pursuit = new EntityId($review["pursuit_id"]);
            $student = new EntityId($review["student_id"]);
            if (isset($selected[$pursuit->value])) {
                self::invalid("Choose each pursuit once.");
            }
            $selected[$pursuit->value] = $review;
            $locks[$student->value] = $student;
        }
        ksort($locks, SORT_STRING);
        return $this->transactions->run(function () use (
            $locks,
            $selected,
        ): array {
            foreach ($locks as $student) {
                $this->students->lock($student);
            }
            $results = [];
            foreach ($selected as $review) {
                $results[] = $this->confirm(
                    new EntityId($review["student_id"]),
                    new EntityId($review["pursuit_id"]),
                    new EntityId($review["operation_id"]),
                    "confer",
                    $review["request"],
                    $review["revision"],
                    $review["reason"],
                    $review["review_token"],
                );
            }
            return ["items" => $results];
        });
    }

    public function queue(?EntityId $after = null): array
    {
        $actor = $this->access->requirePermission(self::CONFER);
        return $this->transactions->run(function () use (
            $after,
            $actor,
        ): array {
            $rows = $this->records->queue($after);
            $items = [];
            $budget = new HistoryReadBudget();
            foreach (array_slice($rows, 0, 10) as $row) {
                $student = new EntityId($row["student_id"]);
                $pursuit = new EntityId($row["id"]);
                $this->authorize($student, $pursuit, "confer", $actor);
                $state = $this->reader
                    ->load($student, $pursuit, $budget)
                    ->state();
                $items[] = [
                    "student_id" => $student->value,
                    "pursuit_id" => $pursuit->value,
                    "student_number" => $this->admissions->person(
                        $this->admissions->student($student)->personId,
                    )->institutionalId->value,
                    "name" => trim(
                        $this->admissions->person(
                            $this->admissions->student($student)->personId,
                        )->givenName .
                            " " .
                            $this->admissions->person(
                                $this->admissions->student($student)->personId,
                            )->familyName,
                    ),
                    "proposal" => $state["eligibility"]["proposal"],
                ];
            }
            $this->access->requirePermission(self::CONFER);
            if ($this->access->authenticatedUserId()->value !== $actor->value) {
                throw new AccessDeniedException();
            }
            return [
                "items" => $items,
                "next_after" => count($rows) > 10 ? $rows[9]["id"] : null,
            ];
        });
    }

    private function context(
        EntityId $student,
        EntityId $pursuit,
        EntityId $operation,
        string $action,
        array $request,
        HistoryReadBudget $budget,
    ): array {
        $personId = $this->admissions->student($student)->personId;
        $this->people->lock($personId);
        $person = $this->admissions->person($personId);
        $identity = $this->profiles->coreReference($person, $budget);
        $root = $this->reader->load($student, $pursuit, $budget);
        $state = $root->state();
        $assignment = $this->students->assignment($pursuit);
        $program = $this->statuses->documentContext($assignment, $budget);
        if (in_array($action, ["eligibility", "confer"], true)) {
            if (
                $assignment->status !== "active" ||
                $state["credential"] !== null
            ) {
                self::invalid(
                    "Choose an active pursuit without a current credential.",
                );
            }
            $proposal =
            $action === "eligibility"
            ? $request["proposal"]
            : $state["eligibility"]["proposal"] ?? null;
            if (
                $proposal === null ||
                $proposal["awarded_on"] > $this->clock->now()->format("Y-m-d")
            ) {
                self::invalid(
                    "Choose a graduation date on or before today and record eligibility first.",
                );
            }
            $snapshot = new DegreeProgressSnapshot(
                $this->progress->capture(
                    $student,
                    $pursuit,
                    $budget,
                    $proposal["awarded_on"],
                ),
            );
            foreach ($snapshot->data["inputs"] as $work) {
                if (
                    ($work["completed_milli"] > 0 ||
                        ($work["grade_recorded"] &&
                            $proposal["awarded_on"] <
                                $this->clock->now()->format("Y-m-d"))) &&
                    ($work["completed_on"] === null ||
                        $work["completed_on"] > $proposal["awarded_on"])
                ) {
                    self::invalid(
                        "Verify actual completion dates on all earned work before the graduation date.",
                    );
                }
            }
            $term = $this->students->term(new EntityId($proposal["term_id"]));
            if ($action === "eligibility") {
                $details = [
                    "proposal" => $proposal,
                    "snapshot" => $snapshot->data,
                    "attested" => $request["attested"],
                    "history_acknowledged" => $request["history_acknowledged"],
                    "eligible" => GraduationSchema::eligible(
                        $snapshot,
                        $request["attested"],
                        $request["history_acknowledged"],
                    ),
                ];
            } else {
                $eligibility = $state["eligibility"];
                if (
                    !$eligibility["eligible"] ||
                    !hash_equals(
                        new DegreeProgressSnapshot(
                            $eligibility["snapshot"],
                        )->fingerprint(),
                        $snapshot->fingerprint(),
                    )
                ) {
                    self::invalid(
                        "Graduation inputs changed or eligibility is incomplete. Record a fresh eligibility decision.",
                    );
                }
                $academic = GraduationSchema::curriculum($snapshot);
                $details = [
                    "eligibility_id" => $eligibility["decision_id"],
                    "credential" => [
                        "id" => $operation->value,
                        "proposal" => $proposal,
                        "program" => $academic["program"],
                        "term" => $term,
                        "components" => $academic["components"],
                    ],
                ];
            }
        } else {
            $credential = $state["credential"];
            if ($credential === null) {
                self::invalid("Choose a currently conferred credential.");
            }
            if ($action === "correct") {
                $credential["proposal"]["title"] = $request["title"];
                $credential["proposal"]["honors"] = $request["honors"];
                if ($credential === $state["credential"]) {
                    self::invalid(
                        "Change the credential title or honors before reviewing.",
                    );
                }
                $details = ["credential" => $credential];
            } else {
                $details = ["credential_id" => $credential["id"]];
            }
        }
        return [
            "identity" => $identity,
            "identity_label" => [
                "name" => trim($person->givenName . " " . $person->familyName),
                "institutional_id" => $person->institutionalId->value,
            ],
            "current_credential" => $state["credential"],
            "state_hash" => GraduationSchema::hash($state),
            "program" => $program,
            "details" => $details,
        ];
    }

    private function authorize(
        EntityId $student,
        EntityId $pursuit,
        string $action,
        ?EntityId $expected = null,
    ): EntityId {
        $actor = $this->access->authenticatedUserId();
        if ($action === "owner") {
            if (
                $this->students->assignment($pursuit)->studentId->value !==
                    $student->value ||
                $this->admissions->person(
                    $this->admissions->student($student)->personId,
                )->ownerUserId?->value !== $actor->value
            ) {
                throw new AccessDeniedException();
            }
        } elseif (
            in_array(
                $action,
                ["staff", "eligibility", "confer", "correct", "revoke"],
                true,
            )
        ) {
            $this->curriculum->authorizeRead($student, $pursuit, $actor);
            if ($action !== "staff") {
                $this->access->requirePermission(
                    $action === "eligibility" ? self::REVIEW : self::CONFER,
                );
            }
        } else {
            throw new AccessDeniedException();
        }
        if ($expected !== null && $actor->value !== $expected->value) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    private function can(string $permission): bool
    {
        try {
            $this->access->requirePermission($permission);
            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }

    private function request(string $action, array $request): void
    {
        try {
            if ($action === "eligibility") {
                GraduationSchema::keys($request, [
                    "proposal",
                    "attested",
                    "history_acknowledged",
                ]);
                GraduationSchema::proposal($request["proposal"]);
                if (
                    !is_bool($request["attested"]) ||
                    !is_bool($request["history_acknowledged"])
                ) {
                    throw new \InvalidArgumentException();
                }
            } elseif ($action === "correct") {
                GraduationSchema::keys($request, ["title", "honors"]);
                GraduationSchema::text($request["title"], 200);
                GraduationSchema::text($request["honors"], 200, true);
            } elseif (
                !in_array($action, ["confer", "revoke"], true) ||
                $request !== []
            ) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable) {
            self::invalid(
                "Provide valid graduation details and explicit review confirmations.",
            );
        }
    }

    private function reason(string $reason): void
    {
        if (
            !mb_check_encoding($reason, "UTF-8") ||
            trim($reason) === "" ||
            mb_strlen($reason) > 1000 ||
            preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $reason)
        ) {
            self::invalid("Enter a reason of 1–1,000 characters.");
        }
    }

    private function intent(
        EntityId $student,
        EntityId $pursuit,
        string $action,
        array $request,
        string $revision,
        string $reason,
    ): string {
        return GraduationSchema::hash([
            $student->value,
            $pursuit->value,
            $action,
            $request,
            $revision,
            trim($reason),
        ]);
    }

    private function same(string $expected, string $actual): void
    {
        if (!hash_equals($expected, $actual)) {
            throw new ConcurrentModificationException(
                "Graduation inputs changed. Review again.",
            );
        }
    }

    private static function invalid(string $message): never
    {
        throw new ValidationException([
            ["field" => "graduation", "message" => $message],
        ]);
    }
}
