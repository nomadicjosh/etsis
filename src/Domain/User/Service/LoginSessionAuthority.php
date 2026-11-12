<?php

declare(strict_types=1);

namespace Domain\User\Service;

use Domain\User\LoginSession;
use Domain\User\ValueObject\VerifiedLoginCookie;
use Domain\User\Repository\{LoginSessionRepository, LoginAccountStateReader};
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Service\Clock;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Exception\{AccessDeniedException, ConcurrentModificationException};

final readonly class LoginSessionAuthority
{
    public function __construct(
        private LoginSessionRepository $sessions,
        private LoginAccountStateReader $accounts,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private Clock $clock,
    ) {
    }
    /** Register the current verified browser sign-in for later integration use. */
    public function current(VerifiedLoginCookie $cookie): LoginSession
    {
        $actor = $this->access->authenticatedUserId();
        $state = $this->account($actor, $cookie);
        $candidate = new LoginSession(
            EntityId::generate(),
            $actor,
            $cookie->cookieHash,
            $state,
            $cookie->expiresAt,
            $this->clock->now()->getTimestamp(),
        );
        $this->saveOrFind($candidate);
        $session = $this->sessions->forCookie($cookie->cookieHash);
        if ($session === null || $session->user->value !== $actor->value || !$this->permits($cookie)) {
            throw new AccessDeniedException("This sign-in is no longer valid. Sign in again.");
        }
        return $session;
    }
    /** Missing references preserve normal framework sign-ins until explicitly registered. */
    public function permits(VerifiedLoginCookie $cookie): bool
    {
        $session = $this->sessions->forCookie($cookie->cookieHash);
        if ($cookie->expiresAt <= $this->clock->now()->getTimestamp()) {
            return false;
        }
        if ($session === null) {
            return true;
        }
        return $session->matches($cookie) && $this->active($session, $cookie->tokenHash);
    }
    /** Internal server-side check only; no browser endpoint exposes arbitrary sign-in references. */
    public function activeReference(EntityId $reference, EntityId $owner): bool
    {
        $session = $this->sessions->byId($reference);
        return $session !== null && $session->user->value === $owner->value && $this->active($session);
    }
    /** A null proof is an intentional anonymous/expired-cookie logout. */
    public function revokeCurrent(?VerifiedLoginCookie $cookie): void
    {
        if ($cookie === null || $cookie->expiresAt <= $this->clock->now()->getTimestamp()) {
            return;
        }
        try {
            $actor = $this->access->authenticatedUserId();
        } catch (AccessDeniedException) {
            return;
        }
        $revision = $this->account($actor, $cookie);
        $candidate = new LoginSession(
            EntityId::generate(),
            $actor,
            $cookie->cookieHash,
            $revision,
            $cookie->expiresAt,
            $this->clock->now()->getTimestamp(),
            $this->clock->now()->getTimestamp(),
        );
        $session = $this->saveOrFind($candidate);
        if ($session->user->value !== $actor->value || !$session->matches($cookie)) {
            throw new AccessDeniedException("The sign-in owner changed.");
        }
        $this->sessions->revoke($session->id, $this->clock->now()->getTimestamp());
    }
    private function active(LoginSession $session, ?string $tokenHash = null): bool
    {
        if (!$session->activeAt($this->clock->now()->getTimestamp())) {
            return false;
        }
        $account = $this->accounts->current($session->user);
        return $account !== null &&
        hash_equals($session->accountRevision, $account->revision) &&
        ($tokenHash === null || hash_equals($account->tokenHash, $tokenHash));
    }
    private function account(EntityId $actor, VerifiedLoginCookie $cookie): string
    {
        $account = $this->accounts->current($actor);
        if (
            $cookie->expiresAt <= $this->clock->now()->getTimestamp() ||
            $account === null ||
            !hash_equals($account->tokenHash, $cookie->tokenHash)
        ) {
            throw new AccessDeniedException("The sign-in could not be verified.");
        }
        return $account->revision;
    }
    private function saveOrFind(LoginSession $candidate): LoginSession
    {
        // Competing registration/logout inserts must never overwrite a revocation.
        $save = function () use ($candidate): LoginSession {
            $existing = $this->sessions->forCookie($candidate->cookieHash);
            if ($existing !== null) {
                return $existing;
            }
            $this->sessions->add($candidate);
            return $candidate;
        };
        try {
            return $this->transactions->run($save);
        } catch (ConcurrentModificationException) {
            // The first scope has rolled back; read the competing committed row once.
            return $this->transactions->run($save);
        }
    }
}
