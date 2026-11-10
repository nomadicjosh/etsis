<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\AdmissionDecision;
use Domain\Admissions\Enum\ApplicationStatus;
use Domain\Admissions\Event\AdmissionApplicationWasObserved;
use Domain\Admissions\Event\AdmissionWasDecided;
use Domain\Admissions\Matriculation;
use Domain\Admissions\ValueObject\AdmissionDecisionId;
use Domain\Admissions\ValueObject\AdmissionDecisionSchema;
use Domain\Admissions\ValueObject\MatriculationId;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

/** Internal ledger. Entry points authorize, hold the application lock and own the transaction. */
final readonly class AdmissionDecisionLedger
{
    public function __construct(
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private Clock $clock,
        private ApplicationContext $context,
    ) {
    }
    public function verify(AdmissionApplication $a): int
    {
        return $this->load($a)["root"]->playhead();
    }
    /** Bind a selected answer artifact to the actual submission, not merely its application row. */
    public function verifySubmission(AdmissionApplication $a, EntityId $submission): void
    {
        $birth = $this->load($a)["root"]->submission();
        if ($birth !== null && ($birth["source"]["submission"]["id"] ?? null) !== $submission->value) {
            throw new CorruptHistoryException();
        }
    }
    /** @return array<string,mixed>|null */
    public function receipt(AdmissionApplication $a, EntityId $operation, EntityId $actor): ?array
    {
        return $this->load($a, $operation, $actor)["receipt"];
    }
    /** @param array<string,mixed> $source */
    public function submitted(
        AdmissionApplication $a,
        array $source,
        string $channel,
        string $authorization,
        ?string $formHash,
        EntityId $operation,
        EntityId $actor,
    ): void {
        if (
            $this->access->authenticatedUserId()->value !== $actor->value ||
            $this->load($a)["root"]->playhead() !== 0
        ) {
            throw new CorruptHistoryException();
        }
        $e = \Domain\Admissions\Event\AdmissionApplicationWasSubmitted::occur(
            new AdmissionDecisionId($a->id->value),
            [
                "decision_id" => $operation->value,
                "application" => AdmissionDecisionSchema::facts($a),
                "source" => $source,
                "channel" => $channel,
                "authorization" => $authorization,
                "form_definition_hash" => $formHash,
            ],
            ["__aggregate_type" => "admission_decision"],
        )->withPlayhead(1);
        $root = AdmissionDecision::root(new AdmissionDecisionId($a->id->value));
        $root->acceptHistorical($e);
        $this->history->append(
            $this->stream($a->id),
            $operation,
            0,
            new EventContext($actor, $this->clock->now(), $operation, origin: "command"),
            [$e],
        );
        $this->verify($a);
    }
    /** Internal creation receipt. Permission/ownership is always checked by the calling service.
     * @return array<string,mixed>|null */
    public function submissionReceipt(AdmissionApplication $a, EntityId $operation, EntityId $actor): ?array
    {
        $r = $this->load($a);
        $p = $r["root"]->submission();
        if ($p === null || $p["decision_id"] !== $operation->value) {
            return null;
        }
        if ($r["birth_actor"] !== $actor->value) {
            throw new AccessDeniedException("This submission receipt belongs to another actor.");
        }
        return $p;
    }
    /** Called after projection CAS succeeds in the same transaction.
     * @param array<string,mixed> $source
     * @return array<string,mixed> */
    public function record(
        AdmissionApplication $before,
        AdmissionApplication $after,
        array $source,
        EntityId $operation,
        EntityId $actor,
        #[\SensitiveParameter] string $reason,
    ): array {
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException("The admissions actor changed.");
        }
        $root = $this->load($before)["root"];
        $at = $this->clock->now();
        if ($root->playhead() === 0) {
            $observation = PublishedGradeId::forVersion($operation, "admissions.observation");
            $observed = AdmissionApplicationWasObserved::occur(
                new AdmissionDecisionId($before->id->value),
                [
                    "decision_id" => $observation->value,
                    "application" => AdmissionDecisionSchema::facts($before),
                ],
                ["__aggregate_type" => "admission_decision"],
            )->withPlayhead(1);
            $root->acceptHistorical($observed);
            $this->history->append(
                $this->stream($before->id),
                $observation,
                0,
                new EventContext(
                    $actor,
                    $at,
                    $operation,
                    reason: "Submitted application observed before this decision; earlier history is unknown.",
                ),
                [$observed],
            );
        }
        $previous = new EntityId($root->decision() ?? throw new CorruptHistoryException());
        $version = $root->playhead();
        $event = AdmissionWasDecided::occur(
            new AdmissionDecisionId($before->id->value),
            [
                "decision_id" => $operation->value,
                "previous_decision_id" => $previous->value,
                "status" => $after->status->value,
                "application_version" => $after->version,
                "source" => $source,
            ],
            ["__aggregate_type" => "admission_decision"],
        )->withPlayhead($version + 1);
        $root->acceptHistorical($event);
        $this->history->append(
            $this->stream($before->id),
            $operation,
            $version,
            new EventContext($actor, $at, $operation, $previous, $reason),
            [$event],
        );
        $this->verify($after);
        return $this->result($root, $at->format(DATE_ATOM));
    }
    /** @return array<string,mixed> */
    public function current(AdmissionApplication $a): array
    {
        $r = $this->load($a);
        return [
            "status" => $a->status->value,
            "version" => $a->version,
            "history_recorded" => $r["root"]->source() !== null,
            "decision_id" => $r["root"]->decision(),
            "effective_at" => $r["at"],
            "source" => AdmissionDecisionSchema::displaySource(
                $r["root"]->source() ?? $this->context->source($a),
            ),
        ];
    }
    /** Projection CAS has succeeded; the authorized entry point owns the transaction.
     * @param array<string,mixed> $source
     * @return array<string,mixed> */
    public function correct(
        AdmissionApplication $before,
        AdmissionApplication $after,
        array $source,
        EntityId $operation,
        EntityId $actor,
        #[\SensitiveParameter] string $reason,
    ): array {
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException("The correction actor changed.");
        }
        $root = $this->load($before)["root"];
        $at = $this->clock->now();
        if ($root->playhead() === 0) {
            $observed = PublishedGradeId::forVersion($operation, "admissions.decision.observation");
            $e = \Domain\Admissions\Event\AdmissionDecisionWasObserved::occur(
                new AdmissionDecisionId($before->id->value),
                [
                    "decision_id" => $observed->value,
                    "application" => AdmissionDecisionSchema::facts($before),
                    "source" => $source,
                ],
                ["__aggregate_type" => "admission_decision"],
            )->withPlayhead(1);
            $root->acceptHistorical($e);
            $this->history->append(
                $this->stream($before->id),
                $observed,
                0,
                new EventContext(
                    $actor,
                    $at,
                    $operation,
                    reason: "Current admissions outcome observed before correction; " .
                        "its earlier decision history is unknown.",
                ),
                [$e],
            );
        }
        $previous = new EntityId($root->decision() ?? throw new CorruptHistoryException());
        $version = $root->playhead();
        $e = \Domain\Admissions\Event\AdmissionDecisionWasCorrected::occur(
            new AdmissionDecisionId($before->id->value),
            [
                "decision_id" => $operation->value,
                "previous_decision_id" => $previous->value,
                "previous_status" => $before->status->value,
                "status" => $after->status->value,
                "application_version" => $after->version,
                "source" => $source,
            ],
            ["__aggregate_type" => "admission_decision"],
        )->withPlayhead($version + 1);
        $root->acceptHistorical($e);
        $this->history->append(
            $this->stream($before->id),
            $operation,
            $version,
            new EventContext($actor, $at, $operation, $previous, $reason),
            [$e],
        );
        $this->verify($after);
        return $this->result($root, $at->format(DATE_ATOM));
    }
    /** @return array<string,mixed> */
    public function timeline(AdmissionApplication $a, ?string $cursor = null): array
    {
        $r = $this->load($a);
        $birth = $r["root"]->submission();
        $submission =
        $birth === null
        ? null
        : [
                    "source" => AdmissionDecisionSchema::displaySource($birth["source"]),
                    "channel" => $birth["channel"],
                    "authorization" => $birth["authorization"],
                    "effective_at" => $r["birth_at"],
                    "recorded_at" => $r["birth_recorded_at"],
                ];
        $source = $r["root"]->source();
        $page = $this->history->page($this->stream($a->id), $cursor, 10);
        $items = [];
        foreach ($page->events as $stored) {
            $e = $stored->event;
            $p = $e->payload();
            $observed =
            $e instanceof AdmissionApplicationWasObserved ||
            $e instanceof \Domain\Admissions\Event\AdmissionDecisionWasObserved;
            $initial = $e instanceof \Domain\Admissions\Event\AdmissionApplicationWasSubmitted;
            $correction = $e instanceof \Domain\Admissions\Event\AdmissionDecisionWasCorrected;
            $items[] = [
                "kind" => $observed
                    ? "observation"
                    : ($initial
                        ? "submission"
                        : ($correction
                            ? "correction"
                            : "decision")),
                "status" => $p["status"] ?? $p["application"]["status"],
                "previous_status" => $p["previous_status"] ?? null,
                "source" => isset($p["source"]) ? AdmissionDecisionSchema::displaySource($p["source"]) : null,
                "effective_at" => $observed ? null : $stored->context->effectiveAt?->format(DATE_ATOM),
                "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
            ];
        }
        $base = [
            "items" => $items,
            "next_cursor" => $page->nextCursor,
            "history_recorded" => $source !== null,
            "earlier_history_unknown" => $birth === null,
            "current_status" => $a->status->value,
            "submission" => $submission,
        ];
        if ($source === null) {
            return $base;
        }
        return $base + [
            "decision" => $this->result($r["root"], $r["at"] ?? throw new CorruptHistoryException()),
            "source" => AdmissionDecisionSchema::displaySource($source),
            "recorded_at" => $r["recorded_at"],
        ];
    }
    /** @return array{root:AdmissionDecision,actor:?string,at:?string,recorded_at:?string,birth_actor:?string,birth_at:?string,birth_recorded_at:?string,receipt:?array<string,mixed>} */
    private function load(
        AdmissionApplication $a,
        ?EntityId $operation = null,
        ?EntityId $receiptActor = null,
    ): array {
        $root = AdmissionDecision::root(new AdmissionDecisionId($a->id->value));
        $cursor = null;
        $receipt = null;
        $birthActor = null;
        $birthAt = null;
        $birthRecordedAt = null;
        $actor = null;
        $at = null;
        $recordedAt = null;
        do {
            $page = $this->history->page($this->stream($a->id), $cursor, 20);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                if ($stored->event instanceof \Domain\Admissions\Event\AdmissionApplicationWasSubmitted) {
                    $birthActor = $stored->context->actor?->value;
                    $birthAt = $stored->context->effectiveAt?->format(DATE_ATOM);
                    $birthRecordedAt = $stored->recordedAt->format(DATE_ATOM);
                }
                if (
                    $stored->event instanceof AdmissionWasDecided ||
                    $stored->event instanceof \Domain\Admissions\Event\AdmissionDecisionWasCorrected
                ) {
                    $actor = $stored->context->actor?->value;
                    $at = $stored->context->effectiveAt?->format(DATE_ATOM);
                    $recordedAt = $stored->recordedAt->format(DATE_ATOM);
                    if ($root->decision() === $operation?->value) {
                        if ($actor !== $receiptActor?->value) {
                            throw new AccessDeniedException(
                                "This admissions receipt belongs to another actor.",
                            );
                        }
                        $receipt = $this->result($root, $at ?? throw new CorruptHistoryException());
                    }
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $facts = $root->application();
        if ($facts !== null) {
            $birth = $root->submission();
            if ($birth !== null) {
                if ($birthActor === null || $birthAt === null || $birthRecordedAt === null) {
                    throw new CorruptHistoryException();
                }
                $this->context->verify($a, $birth, new EntityId($birthActor));
            }
            if (
                ($root->source() === null && ($birth === null || $facts["status"] !== "submitted")) ||
                ($root->source() !== null && ($actor === null || $at === null))
            ) {
                throw new CorruptHistoryException();
            }
            $expected = AdmissionDecisionSchema::facts($a);
            if ($a->status === ApplicationStatus::Matriculated) {
                if ($facts["status"] !== "accepted" || $a->version !== $facts["version"] + 1) {
                    throw new CorruptHistoryException();
                }
                $this->verifyMatriculation($a, $facts["version"]);
                $expected["status"] = "accepted";
                $expected["version"] = $facts["version"];
            }
            foreach ($expected as $key => $value) {
                if ($facts[$key] !== $value) {
                    throw new CorruptHistoryException();
                }
            }
        }
        return [
            "root" => $root,
            "actor" => $actor,
            "at" => $at,
            "recorded_at" => $recordedAt,
            "receipt" => $receipt,
            "birth_actor" => $birthActor,
            "birth_at" => $birthAt,
            "birth_recorded_at" => $birthRecordedAt,
        ];
    }
    private function verifyMatriculation(AdmissionApplication $a, int $version): void
    {
        $root = Matriculation::root(new MatriculationId($a->id->value));
        $page = $this->history->page(
            new StreamReference(new MatriculationId($a->id->value), "matriculation"),
            null,
            2,
        );
        if ($page->nextCursor !== null) {
            throw new CorruptHistoryException();
        }
        foreach ($page->events as $stored) {
            $root->acceptHistorical($stored->event);
        }
        $f = $root->facts();
        if (
            $f === null ||
            $f["accepted_version"] !== $version ||
            $f["person_id"] !== $a->personId->value ||
            $f["program_id"] !== $a->programId->value ||
            $f["entry_term_id"] !== $a->entryTermId->value
        ) {
            throw new CorruptHistoryException();
        }
    }
    /** @return array<string,mixed> */
    private function result(AdmissionDecision $root, string $at): array
    {
        $a = $root->application() ?? throw new CorruptHistoryException();
        return [
            "application_id" => $a["id"],
            "decision_id" => $root->decision(),
            "status" => $a["status"],
            "version" => $a["version"],
            "effective_at" => $at,
        ];
    }
    private function stream(EntityId $id): StreamReference
    {
        return new StreamReference(new AdmissionDecisionId($id->value), "admission_decision");
    }
}
