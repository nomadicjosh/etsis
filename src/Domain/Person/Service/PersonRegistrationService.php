<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use Codefy\Framework\Support\Password;
use DateTimeZone;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\ValueObject\DerivedIdentity;
use Domain\Person\Person;
use Domain\Person\Repository\AccountSetupRepository;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Person\ValueObject\InstitutionalId;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Repository\UserAggregateRepository;
use Domain\User\User;
use Domain\User\ValueObject\UserId;
use Domain\User\ValueObject\Username;
use Domain\User\ValueObject\UserRole;
use Domain\User\ValueObject\UserToken;
use Qubus\ValueObjects\Person\Name;
use Qubus\ValueObjects\StringLiteral\StringLiteral;
use Qubus\ValueObjects\Web\EmailAddress;

final readonly class PersonRegistrationService
{
    public function __construct(
        private AdmissionsRepository $people,
        private PersonAccountRepository $links,
        private UserAggregateRepository $users,
        private ProfileLedger $profiles,
        private AccountSetupRepository $setups,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private MessageCipher $cipher,
        private string $baseUrl,
        private int $minimumPasswordLength = 26
    ) {
    }
    public function staff(
        EntityId $operation,
        string $given,
        string $family,
        string $email,
        ?string $assignedId = null
    ): Person {
        $actor = $this->access->requirePermission('sis:person:manage');
        if ($assignedId !== null && trim($assignedId) !== '') {
            $this->access->requirePermission('sis:person:assign-id');
        }
        $assigned = $assignedId === null || trim($assignedId) === '' ? null : new InstitutionalId($assignedId);
        $userId = DerivedIdentity::from($operation, 'person.account');
        $username = 'p_' . strtr(substr(hash('sha256', $userId->value), 0, 18), '0123456789', 'abcdefghij');
        return $this->create(
            $operation,
            $actor,
            $given,
            $family,
            $email,
            $username,
            bin2hex(random_bytes(32)),
            true,
            $assigned,
            false
        );
    }
    public function register(
        EntityId $operation,
        string $given,
        string $family,
        string $email,
        string $username,
        #[\SensitiveParameter] string $password,
        ?EntityId $creator = null,
        string $middleName = ''
    ): Person {
        // Anonymous self-registration may create only its newly allocated identity and basic role.
        $actor = $creator ?? DerivedIdentity::from($operation, 'person.account');
        return $this->create(
            $operation,
            $actor,
            $given,
            $family,
            $email,
            $username,
            $password,
            false,
            null,
            true,
            $middleName
        );
    }
    private function create(
        EntityId $operation,
        EntityId $actor,
        string $given,
        string $family,
        string $email,
        string $username,
        #[\SensitiveParameter] string $password,
        bool $pending,
        ?InstitutionalId $assigned,
        bool $hashed,
        string $middleName = ''
    ): Person {
        $given = trim($given);
        $family = trim($family);
        $middleName = trim($middleName);
        $email = strtolower(trim($email));
        $invalidPassword = $hashed
        ? password_get_info($password)['algoName'] === 'unknown'
        : mb_strlen($password) < $this->minimumPasswordLength;
        if (
            filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 191 ||
            $invalidPassword || strlen($password) > 4096 || str_contains($password, "\0")
        ) {
            throw new ValidationException([[
                'field' => 'email',
                'message' => 'Use a valid email address and the required password length.',
            ]]);
        }
        $personId = DerivedIdentity::from($operation, 'person.identity');
        $userId = new UserId(DerivedIdentity::from($operation, 'person.account')->value);
        $intent = ProfileLedger::intent([$given,
            $family,
            strtolower($email),
            $username,
            $middleName,
            $pending,
            $assigned?->value,
            $pending ? null : hash('sha256', $operation->value . $password)]);
        return $this->transactions->run(function () use (
            $operation,
            $actor,
            $given,
            $family,
            $email,
            $username,
            $password,
            $pending,
            $assigned,
            $hashed,
            $personId,
            $userId,
            $intent,
            $middleName
        ): Person {
            if ($pending && $this->access->requirePermission('sis:person:manage')->value !== $actor->value) {
                throw new \Domain\Shared\Exception\AccessDeniedException('The person creator changed.');
            }
            if (!$pending && $actor->value !== (string) $userId) {
                if ($this->access->requirePermission('admin:create:user')->value !== $actor->value) {
                    throw new \Domain\Shared\Exception\AccessDeniedException('The account creator changed.');
                }
            }
            $receipt = $this->profiles->receipt($personId, $operation, $actor, $intent);
            if ($receipt !== null) {
                return $receipt;
            }
            if ($this->links->emailInUse($email)) {
                throw new ValidationException([['field' => 'email',
                    'message' => 'An account already uses this email. Find its existing person record.']]);
            }
            $user = User::createUser(
                $userId,
                new Username($username),
                new UserToken(),
                new Name(new StringLiteral($given), new StringLiteral($middleName), new StringLiteral($family)),
                new EmailAddress($email),
                new UserRole('user'),
                new StringLiteral($hashed ? $password : Password::hash($password)),
                $this->clock->now(),
                $personId
            );
            $this->users->saveProfile($user, $this->profiles->accountContext(
                $actor,
                DerivedIdentity::from($operation, 'person.account.created')
            ));
            $person = $this->people->person($personId);
            if ($assigned !== null) {
                // Initial assignment, before any person creation fact is accepted; no previous ID is fabricated.
                $this->links->assignInitialIdentifier($personId, $assigned);
                $person = $this->people->person($personId);
            }
            $this->profiles->create($person, $operation, $actor, $intent);
            if ($pending) {
                $origin = rtrim($this->baseUrl, '/');
                if (filter_var($origin, FILTER_VALIDATE_URL) === false || !str_starts_with($origin, 'https://')) {
                    throw new \DomainException(
                        'Configure the HTTPS application URL before creating setup invitations.'
                    );
                }
                $token = bin2hex(random_bytes(32));
                $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
                $notice = [
                    'recipient' => $email,
                    'body' => "A campus account has been created for you. Set your password within 48 hours:\n"
                        . $origin . '/portal/password-setup/#' . $token
                        . "\nIf you did not expect this account, contact the institution.",
                ];
                $setup = [
                    'id' => $operation->value,
                    'person_id' => $personId->value,
                    'user_id' => (string) $userId,
                    'token_hash' => hash('sha256', $token),
                    'credential_hash' => AccountSetupService::credential($user),
                    'requested_at' => $now->format('Y-m-d\TH:i:s.u\Z'),
                    'expires_at' => $now->modify('+48 hours')->format('Y-m-d\TH:i:s.u\Z'),
                    'consumed_at' => null,
                    'state' => 'queued',
                    'encrypted_notice' => $this->cipher->seal(json_encode($notice, JSON_THROW_ON_ERROR)),
                ];
                $setup['sealed_grant'] = $this->cipher->seal(json_encode(
                    AccountSetupService::grant($setup),
                    JSON_THROW_ON_ERROR
                ));
                $this->setups->stage($setup);
            }
            $this->audit->record(
                $actor,
                $pending ? 'person.created_with_account_setup'
                    : ($actor->value === (string) $userId ? 'person.self_registered' : 'person.created_with_account'),
                $personId
            );
            if ($pending) {
                if ($this->access->requirePermission('sis:person:manage')->value !== $actor->value) {
                    throw new \Domain\Shared\Exception\AccessDeniedException('The person creator changed.');
                }
                if ($assigned !== null) {
                    $this->access->requirePermission('sis:person:assign-id');
                }
            }
            if (!$pending && $actor->value !== (string) $userId) {
                if ($this->access->requirePermission('admin:create:user')->value !== $actor->value) {
                    throw new \Domain\Shared\Exception\AccessDeniedException('The account creator changed.');
                }
            }
            return $person;
        });
    }
}
