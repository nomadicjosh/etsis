<?php

declare(strict_types=1);

namespace Domain\User\Service;

use Codefy\Framework\Support\Password;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Person\Repository\PersonProfileRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Repository\UserAggregateRepository;
use Domain\User\User;
use Domain\User\ValueObject\UserId;
use Domain\User\ValueObject\Username;
use Domain\User\ValueObject\UserRole;
use Domain\User\ValueObject\UserToken;
use DomainException;
use InvalidArgumentException;
use Qubus\ValueObjects\Person\Name;
use Qubus\ValueObjects\StringLiteral\StringLiteral;
use Qubus\ValueObjects\Web\EmailAddress;

final readonly class PersonAccountService
{
    public function __construct(
        private AdmissionsRepository $people,
        private UserAggregateRepository $users,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private PersonProfileRepository $profiles,
        private int $minimumPasswordLength = 26
    ) {
    }

    public function enable(
        EntityId $personId,
        string $username,
        string $email,
        #[\SensitiveParameter] string $password
    ): EntityId {
        $actor = $this->access->requirePermission('admin:create:user');
        $this->access->requirePermission('sis:person:manage');
        return $this->transactions->run(function () use ($personId, $username, $email, $password, $actor): EntityId {
            $this->profiles->lock($personId);
            $person = $this->people->person($personId);
            if ($person->ownerUserId !== null) {
                throw new DomainException('Sign-in access is already enabled for this person.');
            }
            if (
                mb_strlen($username) < 6 || mb_strlen($password) < $this->minimumPasswordLength
                || strlen($password) > 4096
            ) {
                throw new InvalidArgumentException('Use a username of at least six letters and a password of at least '
                    . $this->minimumPasswordLength . ' characters.');
            }
            $id = new UserId();
            $user = User::createUser(
                $id,
                new Username($username),
                new UserToken(),
                new Name(
                    new StringLiteral($person->givenName),
                    new StringLiteral(''),
                    new StringLiteral($person->familyName)
                ),
                new EmailAddress($email),
                new UserRole('user'),
                new StringLiteral(Password::hash($password)),
                new \DateTimeImmutable(),
                $personId
            );
            $this->users->saveAggregateRoot($user);
            $this->audit->record($actor, 'person.account_enabled', $personId);

            return new EntityId((string) $id);
        });
    }
}
