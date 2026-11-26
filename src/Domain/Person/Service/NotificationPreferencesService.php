<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Repository\NotificationPreferencesRepository;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Person\ValueObject\NotificationPreferences;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans_html;

/** Ordinary personal display settings; authoritative profile history and delivery consent are separate. */
final readonly class NotificationPreferencesService
{
    public function __construct(
        private NotificationPreferencesRepository $preferences,
        private AdmissionsRepository $people,
        private PersonAccountRepository $accounts,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    public function currentPerson(): EntityId
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->accounts->findByUser($actor);
        if (
            $person?->ownerUserId?->value !== $actor->value ||
            $this->access->authenticatedUserId()->value !== $actor->value
        ) {
            throw new AccessDeniedException();
        }
        return $person->id;
    }

    public function view(EntityId $person): array
    {
        $actor = $this->access->authenticatedUserId();
        return $this->transactions->run(function () use ($actor, $person): array {
            $this->people->lockPerson($person);
            $owner = $this->authorize($person, $actor, null, false);
            $data = $this->stored($person);
            $this->authorize($person, $actor, $owner, true);
            return $data + ["person_id" => $person->value, "own" => $owner === $actor->value];
        });
    }

    public function save(EntityId $person, int $revision, NotificationPreferences $preferences): array
    {
        $actor = $this->access->authenticatedUserId();
        return $this->transactions->run(function () use ($person, $actor, $revision, $preferences): array {
            $this->people->lockPerson($person);
            $owner = $this->authorize($person, $actor, null, false);
            $before = $this->stored($person);
            if ($before["revision"] !== $revision) {
                throw new ConcurrentModificationException(
                    trans_html("Notification preferences changed. Reload them before saving."),
                );
            }
            if ($before["preferences"] !== $preferences->values) {
                $this->preferences->save($person, $preferences->values, $revision);
                $this->audit->record($actor, "person.notification_preferences_updated", $person);
            }
            $this->authorize($person, $actor, $owner, true);
            $after = $this->stored($person);
            $expected = [
                "revision" => $revision + (int) ($before["preferences"] !== $preferences->values),
                "preferences" => $preferences->values,
            ];
            if ($after !== $expected) {
                throw new ConcurrentModificationException(
                    trans_html("Notification preferences changed before saving completed."),
                );
            }
            return $after + ["person_id" => $person->value, "own" => $owner === $actor->value];
        });
    }

    private function stored(EntityId $person): array
    {
        $data = $this->preferences->current($person) ?? [
            "revision" => 0,
            "preferences" => NotificationPreferences::DEFAULTS,
        ];
        new NotificationPreferences($data["preferences"]);
        return $data;
    }

    private function authorize(EntityId $person, EntityId $actor, ?string $owner, bool $recheck): ?string
    {
        $record = $this->people->person($person);
        if (
            $this->access->authenticatedUserId()->value !== $actor->value ||
            $this->access->requireOwnerOrPermission($record->ownerUserId, "sis:person:manage")->value !==
                $actor->value ||
            ($recheck && $owner !== $record->ownerUserId?->value)
        ) {
            throw new AccessDeniedException();
        }
        return $record->ownerUserId?->value;
    }
}
