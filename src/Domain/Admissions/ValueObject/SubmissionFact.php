<?php

declare(strict_types=1);

namespace Domain\Admissions\ValueObject;

use DateTimeImmutable;
use Domain\Admissions\AdmissionDecision;
use Domain\Admissions\Event\AdmissionApplicationWasSubmitted;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\ValueObject\EntityId;

/** Minimal query facts from an authenticated submission event; never from an observed application. */
final readonly class SubmissionFact
{
    private function __construct(
        public EntityId $application,
        public EntityId $event,
        public DateTimeImmutable $submittedAt,
        public DateTimeImmutable $recordedAt,
        public string $channel,
        public EntityId $entryTerm,
        public string $entryTermCode,
    ) {
    }

    public static function fromEvent(StoredEvent $stored): self
    {
        $event = $stored->event;
        if (
            !$event instanceof AdmissionApplicationWasSubmitted || $event->playhead() !== 1 ||
            $stored->context->actor === null || $stored->context->effectiveAt === null
        ) {
            throw new CorruptHistoryException();
        }
        $root = AdmissionDecision::root($event->aggregateId());
        $root->acceptHistorical($event);
        return new self(
            new EntityId((string) $event->aggregateId()),
            $stored->id,
            $stored->context->effectiveAt,
            $stored->recordedAt,
            $event->payload()['channel'],
            new EntityId($event->payload()['application']['entry_term_id']),
            $event->payload()['source']['entry_term_code'],
        );
    }
}
