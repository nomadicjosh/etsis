<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Lms\Repository\{MoodleLaunchRepository, MoodleBindingRepository, MoodlePersonLinkRepository};
use Domain\Lms\ValueObject\{MoodleLaunch, MoodleHandoff};
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Service\LoginSessionAuthority;
use Domain\User\ValueObject\VerifiedLoginCookie;

final readonly class MoodleLaunchService
{
    public function __construct(
        private MoodleLaunchRepository $launches,
        private MoodleBindingRepository $bindings,
        private MoodlePersonLinkRepository $links,
        private MoodleLaunchPolicy $policy,
        private LoginSessionAuthority $sessions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private Clock $clock,
    ) {
    }
    public function available(): bool
    {
        $user = $this->access->authenticatedUserId();
        $binding = $this->bindings->current();
        $person = $this->launches->personForOwner($user);
        return $this->policy->enabled() &&
        $binding !== null &&
        $person !== null &&
        hash_equals($binding->siteFingerprint, hash("sha256", $this->policy->site()->value)) &&
        $this->links->forPerson($binding->id, $person) !== null;
    }
    /** Only the verified browser owner can mint a ticket; no target identity is accepted. */
    public function begin(VerifiedLoginCookie $cookie): MoodleHandoff
    {
        if (!$this->available()) {
            throw new AccessDeniedException(
                "Moodle launch is unavailable. Ask your institution to review the account link.",
            );
        }
        $session = $this->sessions->current($cookie);
        $binding =
        $this->bindings->current() ?? throw new AccessDeniedException("Moodle launch is unavailable.");
        $person =
        $this->launches->personForOwner($session->user) ??
        throw new AccessDeniedException("Moodle launch is unavailable.");
        $link =
        $this->links->forPerson($binding->id, $person) ??
        throw new AccessDeniedException("Moodle launch is unavailable.");
        $ticket = bin2hex(random_bytes(32));
        $launch = new MoodleLaunch(
            EntityId::generate(),
            $session->id,
            $session->user,
            $person,
            $binding->id,
            $link->id,
            $link->external,
            min($session->expiresAt, $this->clock->now()->getTimestamp() + 60),
            $session->expiresAt,
        );
        $this->launches->add($launch, hash("sha256", $ticket));
        return new MoodleHandoff($this->policy->site(), $ticket);
    }
    /** Authenticated machine channel only. A lost response requires a new browser launch. */
    public function exchange(#[\SensitiveParameter] string $ticket, string $instance): MoodleLaunch
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $ticket) !== 1) {
            throw new AccessDeniedException("Moodle launch is unavailable.");
        }
        return $this->transactions->run(function () use ($ticket, $instance): MoodleLaunch {
            $launch = $this->launches->forTicket(hash("sha256", $ticket));
            if (
                $launch === null ||
                $launch->consumed ||
                $launch->ticketExpiresAt <= $this->clock->now()->getTimestamp() ||
                !$this->active($launch, $instance) ||
                !$this->launches->consume($launch->id)
            ) {
                throw new AccessDeniedException("Moodle launch is unavailable.");
            }
            return $launch;
        });
    }
    /** Bounded indexed reads, no refresh or history replay. */
    public function check(EntityId $grant, string $external, string $instance): ?MoodleLaunch
    {
        $launch = $this->launches->byId($grant);
        return $launch !== null &&
        $launch->consumed &&
        hash_equals($launch->external->value, $external) &&
        $this->active($launch, $instance)
        ? $launch
        : null;
    }
    private function active(MoodleLaunch $launch, string $instance): bool
    {
        if (!$this->policy->enabled() || $launch->sessionExpiresAt <= $this->clock->now()->getTimestamp()) {
            return false;
        }
        $binding = $this->bindings->current();
        if (
            $binding === null ||
            $binding->id->value !== $launch->binding->value ||
            !hash_equals($binding->instanceFingerprint, $instance) ||
            !hash_equals($binding->siteFingerprint, hash("sha256", $this->policy->site()->value)) ||
            $this->launches->personForOwner($launch->user)?->value !== $launch->person->value
        ) {
            return false;
        }
        $link = $this->links->forPerson($binding->id, $launch->person);
        return $link !== null &&
        $link->id->value === $launch->link->value &&
        $link->external->value === $launch->external->value &&
        $this->sessions->activeReference($launch->session, $launch->user);
    }
}
