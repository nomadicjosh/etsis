<?php

declare(strict_types=1);

namespace Domain\Person;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\EventSourcing\DomainEvent;
use Domain\Person\Event\PersonProfileWasRecorded;
use Domain\Person\ValueObject\ProfileFacts;
use Domain\Shared\EventSourcing\CorruptHistoryException;

final class ProfileHistory extends EventSourcedAggregate
{
    private ?Person $person = null;
    private ?string $operation = null;
    public function acceptHistorical(#[\SensitiveParameter] DomainEvent $e): void
    {
        if (
            !$e instanceof PersonProfileWasRecorded || !$e->aggregateId()->equals($this->aggregateId()) ||
            $e->playhead() !== $this->playhead() + 1
        ) {
            throw new CorruptHistoryException();
        }
        ProfileFacts::validate($e->payload());
        $this->applyThat($e);
        $this->playhead = $e->playhead();
    }
    public function whenPersonProfileWasRecorded(PersonProfileWasRecorded $e): void
    {
        $p = $e->payload();
        $next = ProfileFacts::person($p['facts']);
        if (
            $next->id->value !== (string) $this->aggregateId() || $p['previous_operation_id'] !== $this->operation ||
            $p['operation_id'] === $this->operation || (($this->person === null) !== ($p['provenance'] !== 'changed'))
        ) {
            throw new CorruptHistoryException();
        }
        if ($this->person !== null) {
            $before = $this->person;
            $idChanged = $before->institutionalId->value !== $next->institutionalId->value;
            if (
                $before->ownerUserId?->value !== $next->ownerUserId?->value ||
                $next->identityVersion !== $before->identityVersion + (int) $idChanged ||
                ProfileFacts::of($before) === ProfileFacts::of($next)
            ) {
                throw new CorruptHistoryException();
            }
        }
        $this->person = $next;
        $this->operation = $p['operation_id'];
    }
    public function person(): ?Person
    {
        return $this->person;
    }
    public function operation(): ?string
    {
        return $this->operation;
    }
}
