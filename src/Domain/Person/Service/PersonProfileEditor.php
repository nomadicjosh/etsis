<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Person;
use Domain\Person\Repository\PersonProfileRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Repository\UserAggregateRepository;
use Domain\User\Service\UserAccountAccess;
use Domain\User\User;
use Domain\User\ValueObject\UserId;
use Qubus\ValueObjects\Person\Name;
use Qubus\ValueObjects\StringLiteral\StringLiteral;

final readonly class PersonProfileEditor
{
    public function __construct(
        private AdmissionsRepository $people,
        private PersonProfileRepository $profiles,
        private UserAggregateRepository $users,
        private UserAccountAccess $accounts,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private ProfileLedger $history,
    ) {
    }

    /** @return array{person: Person, revision: string} */
    public function view(EntityId $id): array
    {
        $this->access->requirePermission('sis:person:manage');
        $person = $this->people->person($id);

        return ['person' => $person, 'revision' => $this->revision($person)];
    }

    public function save(EntityId $id, string $givenName, string $familyName, bool $restricted, string $revision): void
    {
        $actor = $this->access->requirePermission('sis:person:manage');
        $initial = $this->people->person($id);
        if ($initial->ownerUserId === null) {
            throw new \DomainException('A person must have a sign-in account.');
        }
        $intent = ProfileLedger::intent([$revision, trim($givenName), trim($familyName), $restricted]);
        $operation = ProfileLedger::operation($id, $actor, $intent);
        $this->transactions->run(function () use (
            $id,
            $givenName,
            $familyName,
            $restricted,
            $revision,
            $actor,
            $initial,
            $intent,
            $operation
        ): void {
            $save = function () use (
                $id,
                $givenName,
                $familyName,
                $restricted,
                $revision,
                $actor,
                $initial,
                $intent,
                $operation
): void {
                $this->profiles->lock($id);
                if ($this->access->requirePermission('sis:person:manage')->value !== $actor->value) {
                    throw new \Domain\Shared\Exception\AccessDeniedException('The profile editor changed.');
                }
                if ($this->history->receipt($id, $operation, $actor, $intent) !== null) {
                    return;
                }
                $person = $this->people->person($id);
                if (
                    $person->ownerUserId?->value !== $initial->ownerUserId->value
                    || !hash_equals($this->revision($person), $revision)
                ) {
                    throw new ConcurrentModificationException('Reload this person before saving.');
                }
                $given = trim($givenName);
                $family = trim($familyName);
                $changed = new Person(
                    $id,
                    $person->ownerUserId,
                    $given,
                    $family,
                    $person->institutionalId,
                    $restricted,
                    $person->identityVersion
                );
                if (
                    $person->givenName !== $given || $person->familyName !== $family
                ) {
                    /** @var User $user */
                    $user = $this->users->loadAggregateRoot(new UserId($person->ownerUserId->value));
                    $user->changeName(new Name(
                        new StringLiteral($given),
                        $user->name()->getMiddleName(),
                        new StringLiteral($family)
                    ));
                    $this->users->saveProfile($user, $this->history->accountContext(
                        $actor,
                        \Domain\Shared\ValueObject\DerivedIdentity::from($operation, 'person.account.name')
                    ));
                }
                $this->profiles->save($id, $given, $family, $restricted);
                $this->history->record($person, $changed, $operation, $actor, $intent);
                if ($this->access->requirePermission('sis:person:manage')->value !== $actor->value) {
                    throw new \Domain\Shared\Exception\AccessDeniedException('The profile editor changed.');
                }
                $this->audit->record($actor, 'person.profile_updated', $id);
            };
            // The shared account boundary also protects super users from profile takeover.
            $this->accounts->update($initial->ownerUserId, $save);
        });
    }

    private function revision(Person $person): string
    {
        return hash('sha256', json_encode(
            [$person->id->value, $person->ownerUserId?->value,
            $person->givenName,
                $person->familyName,
                $person->directoryRestricted,
                $this->history->verify($person)],
            JSON_THROW_ON_ERROR
        ));
    }
}
