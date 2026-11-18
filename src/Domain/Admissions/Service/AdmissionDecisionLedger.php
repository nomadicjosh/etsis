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
use Domain\Admissions\ValueObject\AdmissionChecklistProof;
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
use Qubus\Exception\Data\TypeException;

/** Internal ledger. Entry points authorize, hold the application lock and own the transaction. */
final readonly class AdmissionDecisionLedger
{
    public function __construct(
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private Clock $clock,
        private ApplicationContext $context,
        private \Domain\Admissions\Repository\SubmissionProjection $submissions,
    ) {
    }
    public function verify(AdmissionApplication $a): int
    {
        return $this->load($a)["root"]->playhead();
    }
    public function retainsDocument(AdmissionApplication $application, EntityId $document): bool
    {
        return isset($this->load($application)['evidence_documents'][$document->value]);
    }
    /** Bind a selected answer artifact to the actual submission, not merely its application row. */
    public function verifySubmission(AdmissionApplication $a, EntityId $submission): ?array
    {
        $birth = $this->load($a)["root"]->submission();
        if ($birth !== null && ($birth["source"]["submission"]["id"] ?? null) !== $submission->value) {
            throw new CorruptHistoryException();
        }
        return $birth === null ? null : ['entry_term_id' => $birth['application']['entry_term_id'],
            'entry_term_code' => $birth['source']['entry_term_code']];
    }
    /** @return array<string,mixed>|null */
    public function receipt(AdmissionApplication $a, EntityId $operation, EntityId $actor): ?array
    {
        return $this->load($a, $operation, $actor)["receipt"];
    }
    /**
     * @param array<string,mixed> $source
     * @throws TypeException
     */
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
        $this->submissions->project($this->load($a)['submission_fact'] ?? throw new CorruptHistoryException());
    }
    /**
     * Internal creation receipt. Permission/ownership is always checked by the calling service.
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
     * @return array<string,mixed>
     * @throws TypeException
     */
    public function record(
        AdmissionApplication $before,
        AdmissionApplication $after,
        array $source,
        EntityId $operation,
        EntityId $actor,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] array $checklist,
        #[\SensitiveParameter] array $evidence = [],
    ): array {
        return $this->append(
            $before,
            $after,
            $source,
            $operation,
            $actor,
            $reason,
            AdmissionWasDecided::class,
            $checklist,
            $evidence
        );
    }
    public function answerReview(AdmissionApplication $a): ?array
    {
        $refs = $this->load($a)['root']->answerRevisions();
        $reference = $refs[array_key_last($refs)] ?? null;
        return $reference === null ? null : $this->context->revisionReview($a, $reference);
    }
    public function revisionState(AdmissionApplication $a): array
    {
        $root = $this->load($a)['root'];
        return ['birth' => $root->submission(), 'revisions' => $root->answerRevisions(),
            'head' => $root->playhead(), 'initial_entry_term' => $root->initialEntryTerm()];
    }
    /** Artifact/projection CAS already succeeded in the same complete transaction. */
    public function resubmitted(
        AdmissionApplication $before,
        AdmissionApplication $after,
        #[\SensitiveParameter] array $reference,
        EntityId $operation,
        EntityId $actor,
        #[\SensitiveParameter] string $reason,
        \DateTimeImmutable $at
    ): array {
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException('The resubmission actor changed.');
        }
        $root = $this->load($before)['root'];
        $version = $root->playhead();
        $p = ['decision_id' => $operation->value, 'previous_decision_id' => $root->decision(),
            'application_version' => $after->version, 'revision' => $reference];
        $e = \Domain\Admissions\Event\ApplicationAnswersWereResubmitted::occur(
            new AdmissionDecisionId($before->id->value),
            $p,
            ['__aggregate_type' => 'admission_decision']
        )->withPlayhead($version + 1);
        $root->acceptHistorical($e);
        $this->history->append(
            $this->stream($before->id),
            $operation,
            $version,
            new EventContext($actor, $at, $operation, reason: $reason),
            [$e]
        );
        $this->verify($after);
        return $this->revisionResult($after->id, $p);
    }
    private function revisionResult(EntityId $id, array $p): array
    {
        return ['application_id' => $id->value, 'revision_id' => $p['revision']['id'],
            'status' => 'submitted', 'version' => $p['application_version'],
            'submitted_at' => $p['revision']['submitted_at']];
    }
    /** Verified, selected-application reads only; never replayed for a listing. */
    public function offer(AdmissionApplication $a): ?array
    {
        return $this->load($a)['root']->offer();
    }
    public function entryApproval(AdmissionApplication $a): ?array
    {
        return $this->load($a)['root']->entryApproval();
    }
    /** One restoration for offer/entry eligibility on a selected application. */
    public function offerState(AdmissionApplication $a): array
    {
        $root = $this->load($a)['root'];
        return ['offer' => $root->offer(), 'entry' => $root->entryApproval()];
    }
    /** Projection CAS succeeded in the caller's complete transaction. */
    public function approveEntryTerm(
        AdmissionApplication $before,
        AdmissionApplication $after,
        array $terms,
        array $source,
        EntityId $operation,
        EntityId $actor,
        #[\SensitiveParameter] string $reason
    ): array {
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException('The intake approval actor changed.');
        }
        $root = $this->observe(
            $before,
            $operation,
            $actor,
            $this->context->source($before),
            \Domain\Admissions\Event\AdmissionEntryTermWasApproved::class
        );
        $p = ['decision_id' => $operation->value, 'previous_decision_id' => $root->decision(),
            'application_version' => $after->version, 'kind' => $terms['kind'],
            'before' => $terms['before'], 'after' => $terms['after'], 'source' => $source];
        $version = $root->playhead();
        $event = \Domain\Admissions\Event\AdmissionEntryTermWasApproved::occur(
            new AdmissionDecisionId($before->id->value),
            $p,
            ['__aggregate_type' => 'admission_decision']
        )->withPlayhead($version + 1);
        $root->acceptHistorical($event);
        $at = $this->clock->now();
        $this->history->append(
            $this->stream($before->id),
            $operation,
            $version,
            new EventContext($actor, $at, $operation, reason: $reason),
            [$event]
        );
        $this->verify($after);
        return $this->entryResult($before->id, $p, $at->format(DATE_ATOM));
    }
    private function entryResult(EntityId $id, array $p, string $at): array
    {
        $before = $p['before'];
        $after = $p['after'];
        ksort($before);
        ksort($after);
        return ['application_id' => $id->value, 'decision_id' => $p['decision_id'], 'kind' => $p['kind'],
            'status' => 'accepted', 'version' => $p['application_version'], 'before' => $before,
            'after' => $after, 'effective_at' => $at];
    }
    public function hasRecordedAcceptance(AdmissionApplication $a): bool
    {
        return $this->load($a)['root']->acceptanceKnown();
    }
    /** Projection CAS has succeeded in the caller's transaction. @return array<string,mixed> */
    public function recordOffer(
        AdmissionApplication $before,
        AdmissionApplication $after,
        #[\SensitiveParameter] array $payload,
        EntityId $operation,
        EntityId $actor,
        #[\SensitiveParameter] string $reason,
    ): array {
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException('The admissions actor changed.');
        }
        $root = $this->observe(
            $before,
            $operation,
            $actor,
            $this->context->source($before),
            \Domain\Admissions\Event\AdmissionOfferWasRecorded::class
        );
        $payload['operation_id'] = $operation->value;
        $payload['previous_operation_id'] = $root->lastOfferOperation();
        $payload['admission_context_id'] = $root->decision();
        $payload['application_version'] = $after->version;
        $version = $root->playhead();
        $event = \Domain\Admissions\Event\AdmissionOfferWasRecorded::occur(
            new AdmissionDecisionId($before->id->value),
            $payload,
            ['__aggregate_type' => 'admission_decision']
        )->withPlayhead($version + 1);
        $root->acceptHistorical($event);
        $at = $this->clock->now();
        $this->history->append(
            $this->stream($before->id),
            $operation,
            $version,
            new EventContext($actor, $at, $operation, reason: $reason),
            [$event]
        );
        $this->verify($after);
        return $this->offerResult($before->id, $payload, $at->format(DATE_ATOM));
    }
    /** @return array<string,mixed> */
    private function offerResult(EntityId $application, #[\SensitiveParameter] array $payload, string $at): array
    {
        ksort($payload['offer']);
        return ['application_id' => $application->value, 'operation_id' => $payload['operation_id'],
            'action' => $payload['action'], 'offer' => $payload['offer'],
            'version' => $payload['application_version'], 'effective_at' => $at];
    }
    /** Observe older current state explicitly; never fabricate its earlier acceptance. */
    private function observe(
        AdmissionApplication $before,
        EntityId $operation,
        EntityId $actor,
        array $source,
        string $class
    ): AdmissionDecision {
        $root = $this->load($before)['root'];
        $at = $this->clock->now();
        if ($root->playhead() === 0) {
            $submitted = $before->status === ApplicationStatus::Submitted;
            $observation = PublishedGradeId::forVersion(
                $operation,
                $submitted ? 'admissions.observation' : 'admissions.decision.observation'
            );
            $payload = ['decision_id' => $observation->value, 'application' => AdmissionDecisionSchema::facts($before)];
            $observationClass = AdmissionApplicationWasObserved::class;
            if (!$submitted) {
                $payload['source'] = $source;
                $observationClass = \Domain\Admissions\Event\AdmissionDecisionWasObserved::class;
            }
            $observed = $observationClass::occur(
                new AdmissionDecisionId($before->id->value),
                $payload,
                ['__aggregate_type' => 'admission_decision']
            )->withPlayhead(1);
            $root->acceptHistorical($observed);
            $this->history->append(
                $this->stream($before->id),
                $observation,
                0,
                new EventContext($actor, $at, $operation, reason: $submitted
                    ? 'Submitted application observed before this decision; earlier history is unknown.'
                    : ($class === \Domain\Admissions\Event\AdmissionEntryTermWasApproved::class
                        ? 'Current admission outcome observed before intake approval; '
                            . 'earlier decision history is unknown.'
                        : ($class === \Domain\Admissions\Event\AdmissionOfferWasRecorded::class
                        ? 'Current admissions outcome observed before an offer; earlier decision history is unknown.'
                        : ($class === \Domain\Admissions\Event\AdmissionReviewStateWasChanged::class
                        ? 'Current admissions outcome observed before a review-state change; '
                            . 'earlier decision history is unknown.'
                        : 'Current admissions outcome observed before correction; '
                            . 'its earlier decision history is unknown.')))),
                [$observed]
            );
        }
        return $root;
    }
    /** @return array<string,mixed> */
    public function current(AdmissionApplication $a): array
    {
        $r = $this->load($a);
        return [
            "status" => $a->status->value,
            "version" => $a->version,
            "history_recorded" => $r["at"] !== null,
            "decision_id" => $r["root"]->decision(),
            "effective_at" => $r["at"],
            "source" => AdmissionDecisionSchema::displaySource(
                $r["root"]->source() ?? $this->context->source($a),
            ),
        ];
    }
    /**
     * Projection CAS has succeeded; the authorized entry point owns the transaction.
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     * @throws TypeException
     */
    public function correct(
        AdmissionApplication $before,
        AdmissionApplication $after,
        array $source,
        EntityId $operation,
        EntityId $actor,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] array $checklist,
        #[\SensitiveParameter] array $evidence = [],
    ): array {
        return $this->append(
            $before,
            $after,
            $source,
            $operation,
            $actor,
            $reason,
            \Domain\Admissions\Event\AdmissionDecisionWasCorrected::class,
            $checklist,
            $evidence
        );
    }
    /** @param array<string,mixed> $source @return array<string,mixed> */
    public function changeReviewState(
        AdmissionApplication $before,
        AdmissionApplication $after,
        array $source,
        EntityId $operation,
        EntityId $actor,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] ?array $withdrawal = null,
    ): array {
        return $this->append(
            $before,
            $after,
            $source,
            $operation,
            $actor,
            $reason,
            \Domain\Admissions\Event\AdmissionReviewStateWasChanged::class,
            null,
            [],
            $withdrawal
        );
    }
    /** @param array<string,mixed> $source @param list<array<string,mixed>> $evidence
     * @param class-string<\Codefy\Domain\EventSourcing\AggregateChanged> $class
     * @return array<string,mixed> */
    private function append(
        AdmissionApplication $before,
        AdmissionApplication $after,
        array $source,
        EntityId $operation,
        EntityId $actor,
        #[\SensitiveParameter] string $reason,
        string $class,
        #[\SensitiveParameter] ?array $checklist,
        #[\SensitiveParameter] array $evidence = [],
        #[\SensitiveParameter] ?array $withdrawal = null,
    ): array {
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException('The admissions actor changed.');
        }
        $at = $this->clock->now();
        $root = $this->observe($before, $operation, $actor, $source, $class);
        $previous = new EntityId($root->decision() ?? throw new CorruptHistoryException());
        $version = $root->playhead();
        $payload = ['decision_id' => $operation->value, 'previous_decision_id' => $previous->value,
            'status' => $after->status->value, 'application_version' => $after->version, 'source' => $source];
        if ($class !== AdmissionWasDecided::class) {
            $payload['previous_status'] = $before->status->value;
        }
        $metadata = ['__aggregate_type' => 'admission_decision'];
        if ($checklist !== null) {
            $metadata['admission_checklist'] = $checklist;
        }
        if ($withdrawal !== null) {
            $metadata['admission_withdrawal'] = $withdrawal;
        }
        if ($class !== \Domain\Admissions\Event\AdmissionReviewStateWasChanged::class) {
            $metadata['admission_evidence'] = $evidence;
        }
        $event = $class::occur(new AdmissionDecisionId($before->id->value), $payload, $metadata)
            ->withPlayhead($version + 1);
        $root->acceptHistorical($event);
        $this->history->append(
            $this->stream($before->id),
            $operation,
            $version,
            new EventContext($actor, $at, $operation, $previous, $reason),
            [$event]
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
            $reviewState = $e instanceof \Domain\Admissions\Event\AdmissionReviewStateWasChanged;
            $offer = $e instanceof \Domain\Admissions\Event\AdmissionOfferWasRecorded;
            $revision = $e instanceof \Domain\Admissions\Event\ApplicationAnswersWereResubmitted;
            $entry = $e instanceof \Domain\Admissions\Event\AdmissionEntryTermWasApproved;
            $items[] = [
                "kind" => $revision ? "resubmission"
                    : ($entry ? "entry_" . $p["kind"] : ($offer ? "offer_" . $p["action"] : ($observed
                    ? "observation"
                    : ($initial
                        ? "submission"
                        : ($correction
                            ? "correction"
                            : ($reviewState
                                ? ($p['status'] === 'withdrawn' ? 'withdrawal' : 'review_resumed')
                                : "decision")))))),
                "status" => $revision ? "submitted" : ($entry ? "accepted"
                    : ($offer ? $p["offer"]["status"] : ($p["status"] ?? $p["application"]["status"]))),
                "checklist" => array_key_exists('admission_checklist', $e->metadata())
                    ? AdmissionChecklistProof::summary($e->metaParam('admission_checklist')) : null,
                "answer_revision_id" => $revision ? $p["revision"]["id"] : null,
                "entry" => $entry ? ["before" => $p["before"], "after" => $p["after"]] : null,
                "offer" => $offer ? $p["offer"] : null,
                "previous_status" => $p["previous_status"] ?? null,
                "withdrawal_channel" => $e->metaParam('admission_withdrawal')['channel'] ?? null,
                "source" => isset($p["source"]) ? AdmissionDecisionSchema::displaySource($p["source"]) : null,
                "effective_at" => $observed ? null : $stored->context->effectiveAt?->format(DATE_ATOM),
                "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
                'evidence' => \Domain\Admissions\ValueObject\AdmissionEvidence::display(
                    $e->metadata()['admission_evidence'] ?? null
                ),
            ];
        }
        $base = [
            "items" => $items,
            "next_cursor" => $page->nextCursor,
            "history_recorded" => $r["at"] !== null,
            "earlier_history_unknown" => $birth === null,
            "current_status" => $a->status->value,
            "submission" => $submission,
        ];
        if ($source === null || $r["at"] === null) {
            return $base;
        }
        return $base + [
            "decision" => $this->result($r["root"], $r["at"] ?? throw new CorruptHistoryException()),
            "source" => AdmissionDecisionSchema::displaySource($source),
            "recorded_at" => $r["recorded_at"],
        ];
    }
    /**
     * @return array{root:AdmissionDecision,actor:?string,at:?string,recorded_at:?string,
         birth_actor:?string,birth_at:?string,birth_recorded_at:?string,
             submission_fact:?\Domain\Admissions\ValueObject\SubmissionFact,receipt:?array<string,mixed>}
     * @throws TypeException
     */
    private function load(
        AdmissionApplication $a,
        ?EntityId $operation = null,
        ?EntityId $receiptActor = null,
    ): array {
        $root = AdmissionDecision::root(new AdmissionDecisionId($a->id->value));
        $budget = new \Domain\Shared\EventSourcing\HistoryReadBudget(25, 500);
        $evidence = [];
        $cursor = null;
        $receipt = null;
        $birthActor = null;
        $birthAt = null;
        $birthRecordedAt = null;
        $submissionFact = null;
        $actor = null;
        $at = null;
        $recordedAt = null;
        $observedDecision = null;
        do {
            $page = $budget->page($this->history, $this->stream($a->id), $cursor);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                if ($stored->event instanceof \Domain\Admissions\Event\ApplicationAnswersWereResubmitted) {
                    $p = $stored->event->payload();
                    $ref = $p['revision'];
                    $this->context->verifyRevision($a, $ref);
                    if (
                        $stored->context->actor?->value !== $ref['submitted_by']
                        || $stored->context->effectiveAt?->format(DATE_ATOM) !== $ref['submitted_at']
                    ) {
                        throw new CorruptHistoryException();
                    }
                    if ($p['decision_id'] === $operation?->value) {
                        if ($ref['submitted_by'] !== $receiptActor?->value) {
                            throw new AccessDeniedException('This resubmission receipt belongs to another actor.');
                        }
                        $receipt = $this->revisionResult($a->id, $p);
                    }
                }
                $withdrawal = $stored->event->metaParam('admission_withdrawal');
                if (
                    $withdrawal !== null && $withdrawal['channel'] === 'applicant'
                    && $stored->context->actor?->value !== $withdrawal['requested_by']
                ) {
                    throw new CorruptHistoryException();
                }
                foreach ($stored->event->metaParam('admission_evidence', []) as $file) {
                    $evidence[$file['id']] = true;
                }
                if ($stored->event instanceof \Domain\Admissions\Event\AdmissionApplicationWasSubmitted) {
                    $submissionFact = \Domain\Admissions\ValueObject\SubmissionFact::fromEvent($stored);
                    $birthActor = $stored->context->actor?->value;
                    $birthAt = $stored->context->effectiveAt?->format(DATE_ATOM);
                    $birthRecordedAt = $stored->recordedAt->format(DATE_ATOM);
                }
                if ($stored->event instanceof \Domain\Admissions\Event\AdmissionOfferWasRecorded) {
                    $p = $stored->event->payload();
                    if ($p['operation_id'] === $operation?->value) {
                        if ($stored->context->actor?->value !== $receiptActor?->value) {
                            throw new AccessDeniedException('This offer receipt belongs to another actor.');
                        }
                        $receipt = $this->offerResult(
                            $a->id,
                            $p,
                            $stored->context->effectiveAt?->format(DATE_ATOM) ?? throw new CorruptHistoryException()
                        );
                    }
                }
                if ($stored->event instanceof \Domain\Admissions\Event\AdmissionDecisionWasObserved) {
                    // Validate observation provenance without inventing an original acceptance time.
                    if ($stored->context->actor === null || $stored->context->effectiveAt === null) {
                        throw new CorruptHistoryException();
                    }
                    $observedDecision = $stored->event->payload()['decision_id'];
                }
                if (
                    $stored->event instanceof \Domain\Admissions\Event\AdmissionEntryTermWasApproved ||
                    $stored->event instanceof AdmissionWasDecided ||
                    $stored->event instanceof \Domain\Admissions\Event\AdmissionDecisionWasCorrected ||
                    $stored->event instanceof \Domain\Admissions\Event\AdmissionReviewStateWasChanged
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
                        $receipt = $stored->event instanceof \Domain\Admissions\Event\AdmissionEntryTermWasApproved
                        ? $this->entryResult(
                            $a->id,
                            $stored->event->payload(),
                            $at ?? throw new CorruptHistoryException()
                        )
                        : $this->result($root, $at ?? throw new CorruptHistoryException());
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
                ($root->source() === null &&
                ($birth === null ||
                $facts["status"] !== "submitted")) ||
                ($root->source() !== null &&
                $root->decision() !== $observedDecision &&
                    ($actor === null ||
                    $at === null))
            ) {
                throw new CorruptHistoryException();
            }
            if ($birth === null && $root->entryApproval() !== null) {
                $this->context->verifyRetained(
                    $a,
                    $root->source(),
                    new EntityId($root->initialEntryTerm() ?? throw new CorruptHistoryException())
                );
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
        if ($facts === null && $a->status === ApplicationStatus::Withdrawn) {
            throw new CorruptHistoryException();
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
            'submission_fact' => $submissionFact,
            'evidence_documents' => $evidence,
        ];
    }
    /**
     * @throws TypeException
     */
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
    /**
     * @throws TypeException
     */
    private function stream(EntityId $id): StreamReference
    {
        return new StreamReference(new AdmissionDecisionId($id->value), "admission_decision");
    }
}
