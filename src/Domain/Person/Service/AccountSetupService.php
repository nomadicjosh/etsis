<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use Codefy\Framework\Support\Password;
use DateTimeZone;
use Domain\Communications\Service\MailTransport;
use Domain\Communications\Service\MessageCipher;
use Domain\Person\Repository\AccountSetupRepository;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Repository\UserAggregateRepository;
use Domain\User\User;
use Domain\User\ValueObject\UserId;
use Domain\User\ValueObject\UserToken;
use Qubus\ValueObjects\StringLiteral\StringLiteral;

final readonly class AccountSetupService
{
    public function __construct(
        private AccountSetupRepository $setups,
        private UserAggregateRepository $users,
        private UnitOfWork $transactions,
        private Clock $clock,
        private MessageCipher $cipher,
        private MailTransport $mail,
        private ProfileLedger $profiles,
        private \Domain\Admissions\Repository\AdmissionsRepository $people,
        private \Domain\Shared\Security\StudentAccess $access,
        private \Domain\Audit\Repository\AuditTrail $audit,
        private \Domain\User\Service\UserAccountAccess $accounts,
        private string $baseUrl,
        private int $minimumPasswordLength = 26
    ) {
    }
    public function reissue(EntityId $person): void
    {
        $actor = $this->access->requirePermission('sis:person:manage');
        $owner = $this->people->person($person)->ownerUserId
        ?? throw new \DomainException('This person needs a sign-in account.');
        $this->accounts->update($owner, function () use ($person, $actor): void {
            $p = $this->people->person($person);
            $row = $this->setups->forPerson($person) ?? throw new \DomainException('No pending setup exists.');
            $id = new EntityId($row['id']);
            $r = $this->setups->locked($id);
            $this->verifyGrant($r);
            if (
                $r['consumed_at'] !== null || $p->ownerUserId?->value !== $r['user_id'] ||
                new \DateTimeImmutable($r['requested_at'])->modify('+1 minute') > $this->clock->now()
            ) {
                throw new \DomainException('A new setup notice cannot be requested yet.');
            }
            /** @var User $user */ $user = $this->users->loadAggregateRoot(new UserId($r['user_id']));
            if (!$user->signInEnabled()) {
                throw new \DomainException('Enable sign-in before issuing a setup link.');
            }
            $credential = self::credential($user);
            $origin = rtrim($this->baseUrl, '/');
            if (!str_starts_with($origin, 'https://') || filter_var($origin, FILTER_VALIDATE_URL) === false) {
                throw new \DomainException('Configure the HTTPS application URL.');
            }
            $token = bin2hex(random_bytes(32));
            $notice = [
                'recipient' => (string) $user->emailAddress(),
                'body' => "Set your campus password within 48 hours:\n"
                    . $origin . '/portal/password-setup/#' . $token,
            ];
            $changes = [
                'credential_hash' => $credential,
                'token_hash' => hash('sha256', $token),
                'requested_at' => $this->utc(),
                'expires_at' => $this->clock->now()->modify('+48 hours')
                    ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                'state' => 'queued',
                'encrypted_notice' => $this->cipher->seal(json_encode($notice, JSON_THROW_ON_ERROR)),
            ];
            $changes['sealed_grant'] = $this->cipher->seal(json_encode(
                self::grant(array_replace(
                    $r,
                    $changes
                )),
                JSON_THROW_ON_ERROR
            ));
            $this->setups->renew($id, $changes);
            if ($this->access->requirePermission('sis:person:manage')->value !== $actor->value) {
                throw new \Domain\Shared\Exception\AccessDeniedException('The actor changed.');
            }
            $this->audit->record($actor, 'person.account_setup_reissued', $person);
        });
    }
    public static function credential(User $user): string
    {
        return hash('sha256', (string) $user->token() . "\0" . (string) $user->emailAddress());
    }
    /**
     * @param array<string,mixed> $row
     * @return array<string,string>
     */
    public static function grant(array $row): array
    {
        $grant = ['purpose' => 'person.account.setup.v1'];
        foreach (
            ['id',
            'person_id',
            'user_id',
            'token_hash',
            'credential_hash',
            'expires_at',
            'requested_at'] as $key
        ) {
            if (!is_string($row[$key] ?? null)) {
                throw new \DomainException('This setup link is unavailable.');
            }
            $grant[$key] = $row[$key];
        }
        return $grant;
    }
    /** @param array<string,mixed> $row */
    private function verifyGrant(array $row): void
    {
        try {
            if (
                !is_string($row['sealed_grant'] ?? null) || strlen($row['sealed_grant']) > 8192 ||
                json_decode(
                    $this->cipher->open($row['sealed_grant']),
                    true,
                    4,
                    JSON_THROW_ON_ERROR
                ) !== self::grant($row)
            ) {
                throw new \DomainException();
            }
        } catch (\Throwable) {
            throw new \DomainException('This setup link is unavailable.');
        }
    }
    /** @return array{state:string,pending:bool} */
    public function status(EntityId $person): array
    {
        $this->access->requirePermission('sis:person:manage');
        $this->people->person($person);
        $r = $this->setups->forPerson($person);
        if ($r === null || $r['consumed_at'] !== null) {
            return ['state' => 'ready', 'pending' => false];
        }
        return ['state' => $r['expires_at'] <= $this->utc() ? 'expired' : $r['state'], 'pending' => true];
    }
    public function complete(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password): void
    {
        if (
            strlen($password) > 4096
            || mb_strlen($password) < $this->minimumPasswordLength
            || str_contains(
                $password,
                "\0"
            )
        ) {
            throw new ValidationException([['field' => 'password',
                'message' => 'Use at least ' . $this->minimumPasswordLength . ' characters.']]);
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new \DomainException('This setup link is unavailable.');
        }
        $tokenHash = hash('sha256', $token);
        $row = $this->setups->byToken($tokenHash) ?? throw new \DomainException('This setup link is unavailable.');
        $this->transactions->run(function () use ($row, $password, $tokenHash): void {
            $id = new EntityId($row['id']);
            $r = $this->setups->locked($id);
            $this->verifyGrant($r);
            if (
                $r['consumed_at'] !== null
                || !hash_equals(
                    $r['token_hash'],
                    $tokenHash
                )
                || $r['expires_at'] <= $this->utc()
            ) {
                throw new \DomainException('This setup link is unavailable.');
            }
            /** @var User $user */
            $user = $this->users->loadAggregateRoot(new UserId($r['user_id']));
            if (!$user->signInEnabled() || !hash_equals($r['credential_hash'], self::credential($user))) {
                throw new \DomainException('This setup link is unavailable.');
            }
            $user->changePassword(new StringLiteral(Password::hash($password)), new UserToken());
            $this->users->saveProfile($user, $this->profiles->accountContext(
                new EntityId($r['user_id']),
                \Domain\Shared\ValueObject\DerivedIdentity::from($id, 'account.setup.complete')
            ));
            if ($r['expires_at'] <= $this->utc()) {
                throw new \DomainException('This setup link has expired.');
            }
            $this->setups->consume($id, $this->utc());
        });
    }
    /** Explicit bounded worker. Uncertain sends are never blindly retried.
     * @return array{examined:int,sent:int,uncertain:int,expired:int} */
    public function dispatch(int $limit = 10): array
    {
        if ($limit < 1 || $limit > 20) {
            throw new \InvalidArgumentException('Use 1–20 notices.');
        }
        $this->mail->assertReady();
        $result = ['examined' => 0, 'sent' => 0, 'uncertain' => 0, 'expired' => 0];
        foreach ($this->setups->pending($limit) as $id) {
            $row = $this->transactions->run(function () use ($id): ?array {
                $r = $this->setups->locked($id);
                return $r['consumed_at'] === null && $this->setups->claim($id) ? $r : null;
            });
            if ($row === null) {
                continue;
            }
            ++$result['examined'];
            $state = 'expired';
            if ($row['expires_at'] > $this->utc()) {
                try {
                    $this->verifyGrant($row);
                    /** @var User $user */
                    $user = $this->users->loadAggregateRoot(new UserId($row['user_id']));
                    if ($user->signInEnabled() && hash_equals($row['credential_hash'], self::credential($user))) {
                        $notice = json_decode(
                            $this->cipher->open($row['encrypted_notice']),
                            true,
                            4,
                            JSON_THROW_ON_ERROR
                        );
                        if (
                            !is_array($notice) || ($notice['recipient'] ?? null) !== (string) $user->emailAddress()
                            || !is_string($notice['body'] ?? null)

                                || preg_match(
                                    '~\/portal/password-setup/#([a-f0-9]{64})(?:\s|$)~',
                                    $notice['body'],
                                    $match
                                ) !== 1
                            || !hash_equals($row['token_hash'], hash('sha256', $match[1]))
                        ) {
                            throw new \DomainException('This setup notice is unavailable.');
                        }
                        $delivery = \Domain\Shared\ValueObject\DerivedIdentity::from($id, $row['token_hash']);
                        $this->mail->send(
                            $delivery,
                            $notice['recipient'],
                            'Set up your campus password',
                            $notice['body']
                        );
                        $state = 'sent';
                    }
                } catch (\Throwable) {
                    $state = 'uncertain';
                }
            }
            $this->transactions->run(fn() => $this->setups->delivery($id, $row['token_hash'], $state));
            ++$result[$state];
        }
        return $result;
    }
    private function utc(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
