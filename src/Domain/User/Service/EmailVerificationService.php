<?php

declare(strict_types=1);

namespace Domain\User\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MailTransport;
use Domain\Communications\Service\MessageCipher;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\DerivedIdentity;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Repository\EmailVerificationRepository;
use Domain\User\Repository\UserAggregateRepository;
use Domain\User\User;
use Domain\User\ValueObject\UserId;
use Throwable;

use function Codefy\Framework\Helpers\trans;

/** Current proof of email control, never identity verification, an admission decision or an access grant. */
final readonly class EmailVerificationService implements OwnerEmailVerification
{
    public function __construct(
        private EmailVerificationRepository $records,
        private UserAggregateRepository $users,
        private PersonAccountRepository $people,
        private AdmissionsRepository $admissions,
        private AccessRepository $permissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
        private MailTransport $mail,
        private EmailVerificationPolicy $policy,
    ) {
    }

    private function source(EntityId $user): array
    {
        $this->permissions->requireUser($user);
        $person = $this->people->findByUser($user);
        /** @var User $account */
        $account = $this->users->loadAggregateRoot(new UserId($user->value));
        $email = (string) $account->emailAddress();
        $routing = $this->records->account($user);
        if (
            !$account->signInEnabled() || $person?->ownerUserId?->value !== $user->value
            || ($routing['email'] ?? null) !== $email || (int) ($routing['sign_in_enabled'] ?? 0) !== 1
            || strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new AccessDeniedException();
        }
        return ['user_id' => $user->value, 'person_id' => $person->id->value,
            'email' => $email, 'email_revision' => $account->emailRevision()];
    }

    private function owner(): array
    {
        $actor = $this->access->authenticatedUserId();
        $source = $this->source($actor);
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException();
        }
        return $source;
    }

    private function binding(#[\SensitiveParameter] array $source): array
    {
        return ['user_id' => $source['user_id'], 'person_id' => $source['person_id'],
            'email_hash' => hash('sha256', $source['email']), 'email_revision' => $source['email_revision']];
    }

    private function open(#[\SensitiveParameter] ?string $sealed): ?array
    {
        if ($sealed === null || strlen($sealed) > 8192) {
            return null;
        }
        try {
            $data = json_decode($this->cipher->open($sealed), true, 8, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function seal(#[\SensitiveParameter] array $data): string
    {
        $sealed = $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR));
        if (strlen($sealed) > 8192) {
            throw new \LengthException('Verification data exceeds its bound.');
        }
        return $sealed;
    }

    private function challenge(#[\SensitiveParameter] array $source, array $row): array
    {
        return ['purpose' => 'account.email.challenge', 'source' => $this->binding($source),
            'version' => $row['version'], 'token_hash' => $row['token_hash'],
            'requested_at' => $row['requested_at'], 'expires_at' => $row['expires_at']];
    }

    private function matches(#[\SensitiveParameter] array $source, ?array $row): bool
    {
        return $row !== null && $row['person_id'] === $source['person_id']
        && $this->open($row['sealed_challenge']) === $this->challenge($source, $row);
    }

    private function verified(#[\SensitiveParameter] array $source, ?array $row): bool
    {
        if ($row === null || $row['person_id'] !== $source['person_id']) {
            return false;
        }
        $proof = $this->open($row['sealed_verification']);
        $at = $proof['verified_at'] ?? null;
        return is_string($at) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $at)
        && $at <= $this->utc() && $proof === ['purpose' => 'account.email.verified',
                'source' => $this->binding($source), 'version' => $row['version'], 'verified_at' => $at];
    }

    private function describe(#[\SensitiveParameter] array $source, ?array $row): array
    {
        $verified = $this->verified($source, $row);
        $matching = $this->matches($source, $row);
        $state = $verified ? 'verified' : (!$matching ? 'not_requested'
            : ($row['expires_at'] <= $this->utc() ? 'expired' : $row['state']));
        if (
            (!$verified && $state === 'verified')
            || !in_array($state, [
                'verified', 'not_requested', 'queued', 'sending', 'sent', 'uncertain', 'expired',
            ], true)
        ) {
            $state = 'not_requested';
        }
        $cooldown = $row === null || $row['requested_at'] <= $this->utc('-1 minute');
        [$local, $domain] = explode('@', $source['email'], 2);
        return ['verified' => $verified, 'required' => $this->policy->requiredForApplications(),
            'destination_hint' => substr($local, 0, 1) . '…@' . $domain,
            'delivery_enabled' => $this->policy->deliveryEnabled(), 'state' => $state,
            'version' => $row['version'] ?? 0,
            'can_request' => !$verified && $cooldown && $this->policy->deliveryEnabled()];
    }

    public function status(): array
    {
        $source = $this->owner();
        $id = new EntityId($source['user_id']);
        $row = $this->records->get($id);
        $result = $this->describe($source, $row);
        if ($this->owner() !== $source || $this->records->get($id) !== $row) {
            throw new ConcurrentModificationException();
        }
        return $result;
    }

    public function requireForApplication(EntityId $owner, EntityId $person): void
    {
        if (!$this->policy->requiredForApplications()) {
            return;
        }
        $source = $this->owner();
        if ($source['user_id'] !== $owner->value || $source['person_id'] !== $person->value) {
            throw new AccessDeniedException();
        }
        if (!$this->verified($source, $this->records->get($owner))) {
            throw new ValidationException([['field' => 'email_verification',
                'message' => trans('Verify your current sign-in email before submitting your application.')]]);
        }
    }

    public function request(int $version): array
    {
        $source = $this->owner();
        $id = new EntityId($source['user_id']);
        if ($version < 0 || $version > 2147483646) {
            throw new \InvalidArgumentException();
        }
        return $this->transactions->run(function () use ($source, $id, $version): array {
            $this->permissions->lock();
            $this->admissions->lockPerson(new EntityId($source['person_id']));
            $this->records->lock($id);
            if ($this->owner() !== $source) {
                throw new AccessDeniedException();
            }
            $row = $this->records->get($id);
            // The request has no editor-supplied recipient. A matching immediate retry never sends another notice.
            if ($row !== null && $row['version'] === $version + 1 && $this->matches($source, $row)) {
                return $this->describe($source, $row);
            }
            if (($row['version'] ?? 0) !== $version) {
                throw new ConcurrentModificationException();
            }
            if ($this->verified($source, $row)) {
                return $this->describe($source, $row);
            }
            if (
                !$this->policy->deliveryEnabled()
                || ($row !== null && $row['requested_at'] > $this->utc('-1 minute'))
            ) {
                throw new ValidationException([['field' => 'email_verification',
                    'message' => trans(
                        'Email verification is unavailable or a link was requested less than a minute ago.'
                    )]]);
            }
            $origin = $this->policy->origin();
            $this->mail->assertReady();
            $token = bin2hex(random_bytes(32));
            $next = ['person_id' => $source['person_id'], 'version' => $version + 1,
                'token_hash' => hash('sha256', $token), 'requested_at' => $this->utc(),
                'expires_at' => $this->utc('+30 minutes'), 'state' => 'queued', 'sealed_verification' => null];
            $next['sealed_challenge'] = $this->seal($this->challenge($source, $next));
            $next['sealed_notice'] = $this->seal(['recipient' => $source['email'], 'origin' => $origin,
                'body' => trans('Confirm your current campus sign-in email within 30 minutes:') . "\n"
                    . trans('If you did not request this email, ignore it. Do not share this link.') . "\n"
                    . $origin . '/portal/verify-email/#' . $token]);
            $this->records->request($id, $version, $next);
            $this->audit->record($id, 'account.email_verification_requested', new EntityId($source['person_id']));
            if (
                $this->owner() !== $source || !$this->policy->deliveryEnabled() || $this->policy->origin() !== $origin
                || $next['requested_at'] > $this->utc() || $next['expires_at'] <= $this->utc()
                || $this->records->get($id) != ['id' => $id->value] + $next
            ) {
                throw new ConcurrentModificationException();
            }
            return $this->describe($source, $this->records->get($id));
        });
    }

    public function confirm(#[\SensitiveParameter] string $token): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            self::invalid();
        }
        $source = $this->owner();
        $id = new EntityId($source['user_id']);
        return $this->transactions->run(function () use ($source, $id, $token): array {
            $this->permissions->lock();
            $this->admissions->lockPerson(new EntityId($source['person_id']));
            $this->records->lock($id);
            $row = $this->records->get($id);
            if (
                $this->owner() !== $source || !$this->matches($source, $row)
                || !hash_equals($row['token_hash'] ?? '', hash('sha256', $token))
            ) {
                self::invalid();
            }
            if ($this->verified($source, $row)) {
                return $this->describe($source, $row);
            }
            if ($row['expires_at'] <= $this->utc()) {
                self::invalid();
            }
            $proof = $this->seal(['purpose' => 'account.email.verified', 'source' => $this->binding($source),
                'version' => $row['version'], 'verified_at' => $this->utc()]);
            $this->records->confirm($id, $row['version'], $proof);
            $this->audit->record($id, 'account.email_verified', new EntityId($source['person_id']));
            $expected = array_replace($row, [
                'state' => 'verified', 'sealed_notice' => null, 'sealed_verification' => $proof,
            ]);
            if (
                $this->owner() !== $source || $row['expires_at'] <= $this->utc()
                || !$this->verified($source, $expected) || $this->records->get($id) !== $expected
            ) {
                throw new ConcurrentModificationException();
            }
            return $this->describe($source, $expected);
        });
    }

    public function dispatch(int $limit = 10): array
    {
        self::limit($limit);
        $result = ['examined' => 0, 'sent' => 0, 'uncertain' => 0, 'expired' => 0];
        if (!$this->policy->deliveryEnabled()) {
            return $result;
        }
        $this->mail->assertReady();
        foreach ($this->records->pending($this->utc(), $limit) as $id) {
            $row = $this->transactions->run(function () use ($id): ?array {
                $this->records->lock($id);
                $row = $this->records->get($id);
                return $row !== null && $row['expires_at'] > $this->utc()
                    && $this->records->claim($id, $row['version'], $row['token_hash']) ? $row : null;
            });
            if ($row === null) {
                continue;
            }
            $result['examined']++;
            $state = 'expired';
            try {
                $source = $this->source($id);
                $notice = $this->open($row['sealed_notice']);
                if (
                    $row['expires_at'] > $this->utc() && $this->matches($source, $row)
                    && ($notice['recipient'] ?? null) === $source['email'] && is_string($notice['body'] ?? null)
                    && ($notice['origin'] ?? null) === $this->policy->origin()
                    && preg_match('~/portal/verify-email/#([a-f0-9]{64})\z~', $notice['body'], $match) === 1
                    && hash_equals($row['token_hash'], hash('sha256', $match[1]))
                ) {
                    $latest = $this->records->get($id);
                    if (
                        $latest !== null && $latest['version'] === $row['version'] && $latest['state'] === 'sending'
                        && $latest['token_hash'] === $row['token_hash'] && $this->source($id) === $source
                        && $this->policy->deliveryEnabled()
                    ) {
                        $this->mail->send(
                            DerivedIdentity::from($id, $row['token_hash']),
                            $source['email'],
                            trans('Verify your campus email'),
                            $notice['body']
                        );
                        $state = 'sent';
                    }
                }
            } catch (Throwable) {
                $state = 'uncertain';
            }
            $this->transactions->run(
                fn () => $this->records->delivered($id, $row['version'], $row['token_hash'], $state)
            );
            $result[$state]++;
        }
        return $result;
    }

    private static function limit(int $limit): void
    {
        if ($limit < 1 || $limit > 20) {
            throw new \InvalidArgumentException();
        }
    }

    private static function invalid(): never
    {
        throw new ValidationException([['field' => 'email_verification',
            'message' => trans(
                'This verification link is unavailable. Sign in to the matching account or request a new link.'
            )]]);
    }

    private function utc(string $modify = 'now'): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->modify($modify)->format('Y-m-d\TH:i:s\Z');
    }
}
