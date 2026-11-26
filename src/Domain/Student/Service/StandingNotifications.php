<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use DateTimeZone;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\DerivedIdentity;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StandingUpdatesRepository;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\Student;
use Domain\Student\StudentProgram;

use function Codefy\Framework\Helpers\trans_html;

/** Genuine accepted commands only; replay and original-result retries never recreate notices. */
final readonly class StandingNotifications
{
    public function __construct(
        private StandingUpdatesRepository $updates,
        private AdmissionsRepository $people,
        private StudentRecordRepository $students,
        private Clock $clock,
    ) {
    }

    public function record(Student $student, StudentProgram $pursuit, EntityId $operation, int $revision): array
    {
        $this->people->lockPerson($student->personId);
        $person = $this->people->person($student->personId);
        $reference = null;
        if ($person->ownerUserId !== null) {
            $this->updates->replace([
                'id' => DerivedIdentity::from($operation, 'standing.outcome-notification')->value,
                'owner_user_id' => $person->ownerUserId->value,
                'person_id' => $person->id->value,
                'student_id' => $student->id->value,
                'pursuit_id' => $pursuit->id->value,
                'program_id' => $pursuit->programId->value,
                'operation_id' => $operation->value,
                'revision' => $revision,
                'status' => $pursuit->status === 'closed' ? 'corrected' : 'recorded',
                'recorded_at' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                'read_at' => null,
            ]);
            $reference = $this->updates->current($pursuit->id);
            if ($reference === null) {
                throw new ConcurrentModificationException(trans_html('The standing notification was not saved.'));
            }
        }
        return ['student' => $student, 'pursuit' => $pursuit, 'owner' => $person->ownerUserId?->value,
            'reference' => $reference];
    }

    public function verify(#[\SensitiveParameter] array $context): void
    {
        $student = $context['student'];
        $pursuit = $context['pursuit'];
        if (
            $this->people->student($student->id) != $student
            || $this->people->person($student->personId)->ownerUserId?->value !== $context['owner']
            || $this->students->assignment($pursuit->id) != $pursuit
            || ($context['reference'] !== null && $this->updates->current($pursuit->id) !== $context['reference'])
        ) {
            throw new ConcurrentModificationException(
                trans_html('The standing notification or owner changed. Review again.')
            );
        }
    }
}
