<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use DateTimeZone;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Registration\Enrollment;
use Domain\Registration\Repository\EnrollmentHistoryRepository;
use Domain\Registration\Repository\EnrollmentUpdatesRepository;
use Domain\Registration\ValueObject\EnrollmentFacts;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\DerivedIdentity;
use Domain\Shared\ValueObject\EntityId;

/** New accepted commands only, inside their existing transaction; no replay or retry delivery. */
final readonly class EnrollmentNotifications
{
    public function __construct(
        private EnrollmentUpdatesRepository $updates,
        private EnrollmentHistoryRepository $records,
        private AdmissionsRepository $people,
        private EnrollmentLedger $ledger,
        private Clock $clock,
    ) {
    }

    public function record(Enrollment $enrollment, HistoryReadBudget $budget): array
    {
        $source = $this->ledger->notificationFacts($enrollment->id, $enrollment->studentId, $budget);
        $actual = $source['facts'];
        $expected = EnrollmentFacts::fromEnrollment($enrollment)->facts;
        ksort($actual, SORT_STRING);
        ksort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        $person = $this->people->person($this->people->student($enrollment->studentId)->personId);
        $reference = null;
        if ($person->ownerUserId !== null) {
            $this->updates->replace([
                'id' => DerivedIdentity::from(new EntityId($source['decision_id']), 'enrollment.notice')->value,
                'owner_user_id' => $person->ownerUserId->value,
                'person_id' => $person->id->value,
                'student_id' => $enrollment->studentId->value,
                'enrollment_id' => $enrollment->id->value,
                'section_id' => $enrollment->sectionId->value,
                'decision_id' => $source['decision_id'],
                'revision' => $source['revision'],
                'status' => $source['status'],
                'recorded_at' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s.u\Z'),
                'read_at' => null,
            ]);
            $reference = $this->updates->current($enrollment->id);
        }
        return ['enrollment' => $enrollment, 'source' => $source, 'person' => $person->id->value,
            'owner' => $person->ownerUserId?->value, 'reference' => $reference];
    }

    public function verify(array $context, HistoryReadBudget $budget): void
    {
        $enrollment = $context['enrollment'];
        $person = $this->people->person($this->people->student($enrollment->studentId)->personId);
        $current = $this->records->current($enrollment->id);
        $expected = $enrollment->status->value === 'D' ? null : EnrollmentFacts::fromEnrollment($enrollment)->facts;
        if ($current !== null) {
            ksort($current, SORT_STRING);
        }
        if ($expected !== null) {
            ksort($expected, SORT_STRING);
        }
        if (
            $person->id->value !== $context['person'] || $person->ownerUserId?->value !== $context['owner'] ||
            $current !== $expected ||
            $this->ledger->notificationFacts($enrollment->id, $enrollment->studentId, $budget) !== $context['source'] ||
            ($context['owner'] !== null &&
                ($context['reference'] === null ||
                    $this->updates->current($enrollment->id) !== $context['reference']))
        ) {
            throw new ConcurrentModificationException(\Codefy\Framework\Helpers\trans(
                'The registration notification or its owner changed. Review again.',
            ));
        }
    }
}
