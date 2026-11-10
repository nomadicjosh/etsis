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
    public function acceptHistorical(#[\SensitiveParameter] DomainEvent $event): void
    {
        if (
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        if ($event instanceof \Domain\Admissions\Event\AdmissionApplicationWasSubmitted) {
            \Domain\Admissions\ValueObject\ApplicationSubmissionSchema::validate($event->payload());
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
        if ($this->submission !== null) {
            $before = $this->submission["source"]["submission"];
            $after = $p["source"]["submission"];
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
        $this->application["status"] = $p["status"];
        $this->application["version"] = $p["application_version"];
        $this->source = $p["source"];
        $this->decision = $p["decision_id"];
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
