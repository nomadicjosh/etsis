<?php

declare(strict_types=1);

namespace Domain\User\Service;

use Codefy\Framework\Support\Password;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Repository\UserAggregateRepository;
use Domain\User\User;
use Domain\User\ValueObject\UserId;
use Domain\User\ValueObject\UserToken;
use DomainException;
use Qubus\ValueObjects\StringLiteral\StringLiteral;
use Qubus\ValueObjects\Web\EmailAddress;

final readonly class PersonAccountEditor
{
    public function __construct(
        private AdmissionsRepository $people,
        private UserAggregateRepository $users,
        private UserAccountAccess $accounts,
        private StudentAccess $access,
        private AuditTrail $audit,
        private int $minimumPasswordLength = 26,
    ) {
    }

    /** @return array{username: string, email: string, version: int, minimum_password_length: int, is_self: bool} */
    public function view(EntityId $person): array
    {
        $owner = $this->owner($person);
        $this->accounts->authorizeUpdate($owner);
        $user = $this->load($owner);

        return ['username' => (string) $user->username(), 'email' => (string) $user->emailAddress(),
            'version' => $user->playhead(), 'minimum_password_length' => $this->minimumPasswordLength,
            'is_self' => $owner->value === $this->access->authenticatedUserId()->value];
    }

    public function email(EntityId $person, string $email, int $version): void
    {
        $owner = $this->owner($person);
        $this->accounts->update($owner, function () use ($person, $owner, $email, $version): void {
            $user = $this->current($owner, $version);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 191) {
                throw new ValidationException([['field' => 'email', 'message' => 'Enter a valid email address.']]);
            }
            $user->changeEmailAddress(new EmailAddress($email));
            $this->users->saveAggregateRoot($user);
            $this->audit->record($this->access->authenticatedUserId(), 'person.account_email_changed', $person);
        });
    }

    public function password(
        EntityId $person,
        #[\SensitiveParameter] string $password,
        int $version,
        #[\SensitiveParameter] ?string $currentPassword = null,
    ): void {
        $owner = $this->owner($person);
        $this->accounts->update($owner, function () use ($person, $owner, $password, $version, $currentPassword): void {
            $user = $this->current($owner, $version);
            if (
                $owner->value === $this->access->authenticatedUserId()->value
                && ($currentPassword === null || !Password::verify($currentPassword, (string) $user->password()))
            ) {
                throw new AccessDeniedException('The current password is required.');
            }
            if (
                mb_strlen($password) < $this->minimumPasswordLength || strlen($password) > 4096
                || str_contains($password, "\0")
            ) {
                throw new ValidationException([['field' => 'password', 'message' => 'Use at least '
                    . $this->minimumPasswordLength . ' characters, within the supported password length.']]);
            }
            $user->changePassword(new StringLiteral(Password::hash($password)), new UserToken());
            $this->users->saveAggregateRoot($user);
            $this->audit->record($this->access->authenticatedUserId(), 'person.account_password_changed', $person);
        });
    }

    private function owner(EntityId $person): EntityId
    {
        $this->access->requirePermission('sis:person:manage');

        return $this->people->person($person)->ownerUserId
        ?? throw new DomainException('Enable sign-in access on this person first.');
    }

    private function current(EntityId $owner, int $version): User
    {
        $user = $this->load($owner);
        if ($user->playhead() !== $version) {
            throw new ConcurrentModificationException('Reload the account before saving.');
        }

        return $user;
    }

    private function load(EntityId $owner): User
    {
        /** @var User $user */
        $user = $this->users->loadAggregateRoot(new UserId($owner->value));

        return $user;
    }
}
