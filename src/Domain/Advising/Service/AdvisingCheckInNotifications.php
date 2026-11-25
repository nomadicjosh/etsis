<?php

declare(strict_types=1);

namespace Domain\Advising\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Advising\AdvisingCheckIn;
use Domain\Advising\Repository\AdvisingUpdatesRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;

/** Only newly accepted actions call this inside their existing authorized transaction. */
final readonly class AdvisingCheckInNotifications
{
    public function __construct(private AdvisingUpdatesRepository $updates, private AdmissionsRepository $people)
    {
    }

    public static function timestamp(string $at): string
    {
        return new DateTimeImmutable($at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    public function record(AdvisingCheckIn $checkin, EntityId $actor, string $action): ?array
    {
        $facts = $checkin->facts();
        $person = $this->people->person($this->people->student(new EntityId($facts['student_id']))->personId);
        if ($person->ownerUserId?->value !== $facts['owner_user_id']) {
            // An earlier conversation never transfers to a replacement account.
            return $this->updates->current(new EntityId($facts['id']));
        }
        $at = self::timestamp($facts[match ($action) {
            'record' => 'created_at', 'respond' => 'responded_at', 'close', 'cancel' => 'resolved_at',
        }]);
        if ($action === 'respond') {
            $this->updates->respond(new EntityId($facts['id']), $person->ownerUserId, $at);
            return $this->updates->current(new EntityId($facts['id']));
        }
        $reference = [
            'id' => EntityId::generate()->value, 'owner_user_id' => $person->ownerUserId->value,
            'person_id' => $person->id->value, 'student_id' => $facts['student_id'],
            'pursuit_id' => $facts['pursuit_id'], 'checkin_id' => $facts['id'],
            'revision' => $facts['version'], 'status' => $facts['status'], 'recorded_at' => $at,
            'read_at' => $actor->value === $person->ownerUserId->value ? $at : null,
        ];
        $this->updates->replace($reference);
        return $reference;
    }

    public function verify(AdvisingCheckIn $checkin, ?array $reference): void
    {
        if ($this->updates->current(new EntityId($checkin->facts()['id'])) != $reference) {
            throw new ConcurrentModificationException(\Codefy\Framework\Helpers\trans(
                'The advising notification changed before completion. Refresh check-ins.'
            ));
        }
    }
}
