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
        private \Domain\Person\Service\ProfileLedger $profiles,
        private \Domain\Shared\EventSourcing\HistoricalEventStore $history,
        private int $minimumPasswordLength = 26,
    ) {
    }

    /** @return array{username: string, email: string, version: int, minimum_password_length: int, is_self: bool, sign_in_enabled: bool} */
    public function view(EntityId $person): array
    {
        $owner = $this->owner($person);
        $this->accounts->authorizeUpdate($owner);
        $user = $this->load($owner);

        return ['username' => (string) $user->username(), 'email' => (string) $user->emailAddress(),
            'sign_in_enabled' => $user->signInEnabled(),
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
            $this->users->saveProfile($user, $this->profiles->accountContext(
                $this->access->authenticatedUserId(),
                EntityId::generate()
            ));
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
            $this->users->saveProfile($user, $this->profiles->accountContext(
                $this->access->authenticatedUserId(),
                EntityId::generate()
            ));
            $this->audit->record($this->access->authenticatedUserId(), 'person.account_password_changed', $person);
        });
    }

    public function signIn(
        EntityId $person,
        bool $enabled,
        int $version,
        EntityId $operation,
        #[\SensitiveParameter] string $reason
    ): void {
        $owner = $this->owner($person);
        $reason = trim($reason);
        if (
            $reason === '' || !mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) > 1000
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $reason)
        ) {
            throw new ValidationException([['field' => 'reason',
                'message' => 'Enter a reason within 1,000 characters.']]);
        }
        $actor = $this->access->authenticatedUserId();
        $intent = \Domain\Person\Service\ProfileLedger::intent([$enabled, $version, $reason]);
        $this->accounts->updateSignIn($owner, function () use (
            $person,
            $owner,
            $enabled,
            $version,
            $operation,
            $reason,
            $actor,
            $intent
        ): void {
            if ($owner->value === $actor->value) {
                throw new AccessDeniedException('Ask another authorized administrator to change your sign-in access.');
            }
            if ($this->owner($person)->value !== $owner->value) {
                throw new AccessDeniedException('The linked account changed.');
            }
            if ($this->signInReceipt($owner, $operation, $actor, $intent)) {
                return;
            }
            $user = $this->current($owner, $version);
            if ($user->signInEnabled() === $enabled) {
                return;
            }
            $user->changeSignIn($enabled, $operation, $intent);
            $context = $this->profiles->accountContext($actor, $operation);
            $this->users->saveProfile($user, new \Domain\Shared\EventSourcing\EventContext(
                $context->actor,
                $context->effectiveAt,
                $context->correlation,
                reason: $reason
            ));
            $this->audit->record($actor, $enabled ? 'person.sign_in_enabled' : 'person.sign_in_disabled', $person);
        });
    }
    private function signInReceipt(EntityId $owner, EntityId $operation, EntityId $actor, string $intent): bool
    {
        $stream = new \Domain\Shared\EventSourcing\StreamReference(new UserId($owner->value), 'user');
        $cursor = null;
        $count = 0;
        do {
            $page = $this->history->page($stream, $cursor, 20);
            foreach ($page->events as $stored) {
                if (++$count > 2000) {
                    throw new \Domain\Shared\EventSourcing\HistoryReadLimitException();
                }
                if ($stored->context->correlation?->value !== $operation->value) {
                    continue;
                }
                if (
                    !$stored->event instanceof \Domain\User\Event\UserSignInWasChanged
                    || $stored->context->actor?->value !== $actor->value
                    || ($stored->event->payload()['operation_id'] ?? null) !== $operation->value
                    || ($stored->event->payload()['intent'] ?? null) !== $intent
                ) {
                    throw new AccessDeniedException('This operation belongs to another account decision.');
                }
                return true;
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        return false;
    }

    private function owner(EntityId $person): EntityId
    {
        $this->access->requirePermission('sis:person:manage');

        $record = $this->people->person($person);
        $this->profiles->verify($record);
        return $record->ownerUserId
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
