<?php

declare(strict_types=1);

namespace Domain\Admissions;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Admissions\Event\AdmissionApplicationWasObserved;
use Domain\Admissions\Event\AdmissionWasDecided;
use Domain\Admissions\ValueObject\AdmissionDecisionSchema;
use Domain\Shared\EventSourcing\CorruptHistoryException;

final class AdmissionDecision extends EventSourcedAggregate
{
    /** @var array<string,mixed>|null */
    private ?array $application = null;
    /** @var array<string,mixed>|null */
    private ?array $source = null;
    private ?string $decision = null;
    /** @var array<string,mixed>|null */
    private ?array $submission = null;
    /** @var array<string,mixed>|null */
    private ?array $offer = null;
    private bool $acceptanceKnown = false;

    /** @var list<array<string,mixed>> */
    private array $answerRevisions = [];
    public function answerRevisions(): array
    {
        return $this->answerRevisions;
    }
    public function whenApplicationAnswersWereResubmitted(
        #[\SensitiveParameter] \Domain\Admissions\Event\ApplicationAnswersWereResubmitted $event
    ): void {
        $p = $event->payload();
        $r = $p['revision'];
        if (
            $this->submission === null || $this->application['status'] !== 'submitted'
            || $p['previous_decision_id'] !== $this->decision
            || $p['application_version'] !== $this->application['version'] + 1
            || $r['original_submission_id'] !== ($this->submission['source']['submission']['id'] ?? null)
            || $r['form_id'] !== ($this->submission['source']['submission']['form_id'] ?? null)
            || $r['form_hash'] !== $this->submission['form_definition_hash'] || count($this->answerRevisions) >= 20
            || in_array($r['id'], array_column($this->answerRevisions, 'id'), true)
        ) {
            throw new CorruptHistoryException();
        }
        $this->answerRevisions[] = $r;
        $this->application['version'] = $p['application_version'];
        $this->decision = $p['decision_id'];
    }

    /** Only an offer issued under the current admissions decision is actionable. */
    public function offer(): ?array
    {
        return ($this->offer['admission_context_id'] ?? null) === $this->decision ? $this->offer : null;
    }
    public function lastOfferOperation(): ?string
    {
        return $this->offer['operation_id'] ?? null;
    }
    public function acceptanceKnown(): bool
    {
        return $this->acceptanceKnown;
    }
    public function whenAdmissionOfferWasRecorded(
        #[\SensitiveParameter] \Domain\Admissions\Event\AdmissionOfferWasRecorded $event
    ): void {
        $p = $event->payload();
        if (
            $this->application === null || $this->application['status'] !== 'accepted'
            || $p['admission_context_id'] !== $this->decision
            || $p['previous_operation_id'] !== $this->lastOfferOperation()
            || $p['application_version'] !== $this->application['version'] + 1
        ) {
            throw new CorruptHistoryException();
        }
        if ($p['action'] !== 'issue') {
            $before = $this->offer()['offer'] ?? throw new CorruptHistoryException();
            if (!in_array($before['status'], $p['action'] === 'cancel' ? ['pending', 'accepted'] : ['pending'], true)) {
                throw new CorruptHistoryException();
            }
            foreach ($before as $key => $value) {
                if ($key !== 'status' && $p['offer'][$key] !== $value) {
                    throw new CorruptHistoryException();
                }
            }
        }
        $this->offer = $p;
        $this->application['version'] = $p['application_version'];
    }
    /** @var array<string,mixed>|null */
    private ?array $entryApproval = null;
    private ?string $initialEntryTerm = null;
    public function initialEntryTerm(): ?string
    {
        return $this->initialEntryTerm;
    }
    public function entryApproval(): ?array
    {
        return $this->entryApproval;
    }
    public function whenAdmissionEntryTermWasApproved(
        #[\SensitiveParameter] \Domain\Admissions\Event\AdmissionEntryTermWasApproved $event
    ): void {
        $p = $event->payload();
        if (
            $this->application === null || $this->application['status'] !== 'accepted'
            || $p['before']['id'] !== $this->application['entry_term_id']
            || $p['previous_decision_id'] !== $this->decision
            || $p['application_version'] !== $this->application['version'] + 1
            || ($this->entryApproval !== null && $p['before'] != $this->entryApproval['after'])
            || ($p['kind'] === 'reconfirm' && $this->entryApproval === null)
        ) {
            throw new CorruptHistoryException();
        }
        $this->assertSubmission($p['source']);
        $this->application['entry_term_id'] = $p['after']['id'];
        $this->application['version'] = $p['application_version'];
        $this->decision = $p['decision_id'];
        $this->source = $p['source'];
        $this->entryApproval = $p;
    }
    public function acceptHistorical(#[\SensitiveParameter] DomainEvent $event): void
    {
        if (array_key_exists('admission_withdrawal', $event->metadata())) {
            if (!$event instanceof \Domain\Admissions\Event\AdmissionReviewStateWasChanged) {
                throw new CorruptHistoryException();
            }
            \Domain\Admissions\ValueObject\AdmissionWithdrawalFact::validate(
                $event->metaParam('admission_withdrawal'),
                $event->payload()
            );
        }
        if (array_key_exists('admission_evidence', $event->metadata())) {
            if (
                !$event instanceof AdmissionWasDecided
                && !$event instanceof \Domain\Admissions\Event\AdmissionDecisionWasCorrected
            ) {
                throw new CorruptHistoryException();
            }
            \Domain\Admissions\ValueObject\AdmissionEvidence::validate($event->metaParam('admission_evidence'));
        }
        if (
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        if ($event instanceof \Domain\Admissions\Event\ApplicationAnswersWereResubmitted) {
            \Domain\Admissions\ValueObject\ApplicationRevisionSchema::validate($event->payload());
        } elseif ($event instanceof \Domain\Admissions\Event\AdmissionEntryTermWasApproved) {
            \Domain\Admissions\ValueObject\AdmissionDeferralSchema::validate($event->payload());
        } elseif ($event instanceof \Domain\Admissions\Event\AdmissionOfferWasRecorded) {
            \Domain\Admissions\ValueObject\AdmissionOfferSchema::validate($event->payload());
        } elseif ($event instanceof \Domain\Admissions\Event\AdmissionReviewStateWasChanged) {
            \Domain\Admissions\ValueObject\AdmissionReviewStateSchema::validate($event->payload());
        } elseif ($event instanceof \Domain\Admissions\Event\AdmissionApplicationWasSubmitted) {
            \Domain\Admissions\ValueObject\ApplicationSubmissionSchema::validate($event->payload());
        } elseif (
            $event instanceof \Domain\Admissions\Event\AdmissionDecisionWasObserved ||
            $event instanceof \Domain\Admissions\Event\AdmissionDecisionWasCorrected
        ) {
            \Domain\Admissions\ValueObject\AdmissionCorrectionSchema::validate(
                $event::class,
                $event->payload(),
            );
        } else {
            AdmissionDecisionSchema::validate($event::class, $event->payload());
        }
        $this->applyThat($event);
        $this->playhead = $event->playhead();
    }
    public function whenAdmissionApplicationWasSubmitted(
        \Domain\Admissions\Event\AdmissionApplicationWasSubmitted $event,
    ): void {
        $p = $event->payload();
        if ($this->application !== null || $p["application"]["id"] !== (string) $this->aggregateId()) {
            throw new CorruptHistoryException();
        }
        $this->submission = $p;
        $this->application = $p["application"];
        $this->initialEntryTerm = $p["application"]["entry_term_id"];
        $this->decision = $p["decision_id"];
    }
    /** @return array<string,mixed>|null */
    public function submission(): ?array
    {
        return $this->submission;
    }
    public function whenAdmissionApplicationWasObserved(AdmissionApplicationWasObserved $event): void
    {
        $p = $event->payload();
        if ($this->application !== null || $p["application"]["id"] !== (string) $this->aggregateId()) {
            throw new CorruptHistoryException();
        }
        $this->application = $p["application"];
        $this->initialEntryTerm = $p["application"]["entry_term_id"];
        $this->decision = $p["decision_id"];
    }
    public function whenAdmissionWasDecided(AdmissionWasDecided $event): void
    {
        $p = $event->payload();
        if (
            $this->application === null ||
            $this->application["status"] !== "submitted" ||
            $p["previous_decision_id"] !== $this->decision ||
            $p["application_version"] !== $this->application["version"] + 1
        ) {
            throw new CorruptHistoryException();
        }
        $this->assertSubmission($p['source']);
        $this->application["status"] = $p["status"];
        $this->application["version"] = $p["application_version"];
        $this->source = $p["source"];
        $this->decision = $p["decision_id"];
        $this->acceptanceKnown = $p["status"] === "accepted";
    }
    public function whenAdmissionDecisionWasObserved(
        \Domain\Admissions\Event\AdmissionDecisionWasObserved $event,
    ): void {
        $p = $event->payload();
        if ($this->application !== null || $p["application"]["id"] !== (string) $this->aggregateId()) {
            throw new CorruptHistoryException();
        }
        $this->application = $p["application"];
        $this->initialEntryTerm = $p["application"]["entry_term_id"];
        $this->source = $p["source"];
        $this->decision = $p["decision_id"];
    }
    public function whenAdmissionDecisionWasCorrected(
        \Domain\Admissions\Event\AdmissionDecisionWasCorrected $event,
    ): void {
        $p = $event->payload();
        if (
            $this->application === null ||
            $this->source === null ||
            $this->application["status"] !== $p["previous_status"] ||
            $p["previous_decision_id"] !== $this->decision ||
            $p["application_version"] !== $this->application["version"] + 1
        ) {
            throw new CorruptHistoryException();
        }
        $this->assertSubmission($p['source']);
        $this->application["status"] = $p["status"];
        $this->application["version"] = $p["application_version"];
        $this->source = $p["source"];
        $this->decision = $p["decision_id"];
        $this->acceptanceKnown = $p["status"] === "accepted";
    }
    public function whenAdmissionReviewStateWasChanged(
        \Domain\Admissions\Event\AdmissionReviewStateWasChanged $event
    ): void {
        $p = $event->payload();
        if (
            $this->application === null || $this->application['status'] !== $p['previous_status']
            || $p['previous_decision_id'] !== $this->decision
            || $p['application_version'] !== $this->application['version'] + 1
        ) {
            throw new CorruptHistoryException();
        }
        $this->assertSubmission($p['source']);
        $this->application['status'] = $p['status'];
        $this->application['version'] = $p['application_version'];
        $this->source = $p['source'];
        $this->decision = $p['decision_id'];
        $this->acceptanceKnown = false;
    }
    /** @param array<string,mixed> $source */
    private function assertSubmission(array $source): void
    {
        $prior = $this->submission['source'] ?? $this->source;
        if ($prior === null) {
            return;
        }
        $before = $prior['submission'];
        $after = $source['submission'];
        if (($before === null) !== ($after === null)) {
            throw new CorruptHistoryException();
        }
        if ($before !== null) {
            foreach ($before as $key => $value) {
                if ($after[$key] !== $value) {
                    throw new CorruptHistoryException();
                }
            }
        }
    }
    /** @return array<string,mixed>|null */
    public function application(): ?array
    {
        return $this->application;
    }
    /** @return array<string,mixed>|null */
    public function source(): ?array
    {
        return $this->source;
    }
    public function decision(): ?string
    {
        return $this->decision;
    }
}
