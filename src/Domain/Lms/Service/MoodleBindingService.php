<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Lms\Repository\MoodleBindingRepository;
use Domain\Lms\ValueObject\MoodleBinding;
use Domain\Lms\ValueObject\MoodleInstallation;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Throwable;

/** Audited administrative configuration; does not grant SSO or academic write authority. */
final readonly class MoodleBindingService
{
    public function __construct(
        private MoodleInstanceReader $reader,
        private MoodleBindingRepository $bindings,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** @return array{binding:?array<string,string>} */
    public function status(): array
    {
        $actor = $this->access->requirePermission(MoodleAdministrationService::MANAGE);
        $binding = $this->bindings->current();
        $this->actor($actor);
        return ["binding" => $binding?->describe()];
    }

    /** @return array{review_token:string,site_fingerprint:string,instance_fingerprint:string,expires_at:int} */
    public function review(#[\SensitiveParameter] string $reason): array
    {
        $actor = $this->access->requirePermission(MoodleAdministrationService::MANAGE);
        $reason = $this->reason($reason);
        if ($this->bindings->current() !== null) {
            throw new ConcurrentModificationException(
                "An installation is already approved. Refresh its saved details.",
            );
        }
        $installation = $this->readRemote($actor, "moodle.installation_review_requested");
        if ($this->bindings->current() !== null) {
            throw new ConcurrentModificationException(
                "Another administrator approved an installation. Refresh its saved details.",
            );
        }
        $this->actor($actor);
        $data = [
            "kind" => "moodle-initial-binding",
            "actor_id" => $actor->value,
            "id" => EntityId::generate()->value,
            "site_fingerprint" => $installation->siteFingerprint(),
            "instance_fingerprint" => $installation->instanceFingerprint(),
            "reason" => $reason,
            "expires_at" => $this->clock->now()->getTimestamp() + 1200,
        ];
        return [
            "review_token" => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR)),
            "site_fingerprint" => $data["site_fingerprint"],
            "instance_fingerprint" => $data["instance_fingerprint"],
            "expires_at" => $data["expires_at"],
        ];
    }

    /** @return array{binding:array<string,string>} */
    public function confirm(#[\SensitiveParameter] string $token): array
    {
        $actor = $this->access->requirePermission(MoodleAdministrationService::MANAGE);
        $data = $this->proof($token);
        if ($data["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("This installation review belongs to another administrator.");
        }
        $existing = $this->completed($data, $actor);
        if ($existing !== null) {
            return ["binding" => $existing->describe()];
        }
        $this->unexpired($data["expires_at"]);
        // Recheck the actual installation. No network request runs within a database transaction.
        $installation = $this->readRemote($actor, "moodle.installation_confirmation_requested");
        if (
            !hash_equals($data["site_fingerprint"], $installation->siteFingerprint()) ||
            !hash_equals($data["instance_fingerprint"], $installation->instanceFingerprint())
        ) {
            throw new ConcurrentModificationException(
                "The installation changed after review. Review it again before approving.",
            );
        }
        return $this->transactions->run(function () use ($data, $actor): array {
            $this->actor($actor);
            $existing = $this->completed($data, $actor);
            if ($existing !== null) {
                return ["binding" => $existing->describe()];
            }
            $this->unexpired($data["expires_at"]);
            $this->enabled();
            $binding = new MoodleBinding(
                new EntityId($data["id"]),
                $data["site_fingerprint"],
                $data["instance_fingerprint"],
                $actor,
                $this->clock->now()->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d\TH:i:s\Z"),
            );
            $this->bindings->add($binding, $this->cipher->seal($data["reason"]));
            $this->audit->record($actor, "moodle.installation_approved", $binding->id);
            $this->actor($actor);
            $this->enabled();
            return ["binding" => $binding->describe()];
        });
    }

    /** @return array{binding:array<string,string>,verified:bool} */
    public function verify(): array
    {
        $actor = $this->access->requirePermission(MoodleAdministrationService::MANAGE);
        $binding = $this->bindings->current();
        if ($binding === null) {
            self::invalid("binding", "Review and approve an installation first.");
        }
        $installation = $this->readRemote($actor, "moodle.bound_installation_check_requested");
        $current = $this->bindings->current();
        if ($current?->id->value !== $binding->id->value || !$binding->matches($installation)) {
            throw new ConcurrentModificationException(
                "Moodle differs from the approved installation. Review the server configuration before continuing.",
            );
        }
        $this->actor($actor);
        return ["binding" => $binding->describe(), "verified" => true];
    }

    private function readRemote(EntityId $actor, string $action): MoodleInstallation
    {
        $this->enabled();
        $this->audit->record($actor, $action, $actor);
        $this->actor($actor);
        $this->enabled();
        try {
            $installation = $this->reader->identify();
        } catch (MoodleConnectionUnavailableException $error) {
            $this->actor($actor);
            throw new MoodleConnectionUnavailableException($error->setupIssue);
        } catch (Throwable) {
            $this->actor($actor);
            throw new MoodleConnectionUnavailableException();
        }
        $this->actor($actor);
        $this->enabled();
        return $installation;
    }

    private function enabled(): void
    {
        if (!$this->reader->enabled()) {
            throw new MoodleConnectionUnavailableException();
        }
    }

    private function actor(EntityId $actor): void
    {
        if ($this->access->requirePermission(MoodleAdministrationService::MANAGE)->value !== $actor->value) {
            throw new AccessDeniedException("The administrator changed. Reload before continuing.");
        }
    }

    private function reason(#[\SensitiveParameter] string $reason): string
    {
        $reason = trim($reason);
        if ($reason === "" || mb_strlen($reason) > 500 || preg_match('/[\x00-\x1F\x7F]/', $reason)) {
            self::invalid(
                "reason",
                "Enter an approval reason of at most 500 characters, without student information or credentials.",
            );
        }
        return $reason;
    }

    /** @return array{kind:string,actor_id:string,id:string,site_fingerprint:string,instance_fingerprint:string,reason:string,expires_at:int} */
    private function proof(#[\SensitiveParameter] string $token): array
    {
        try {
            if ($token === "" || strlen($token) > 16384) {
                throw new InvalidArgumentException();
            }
            $data = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($data) ||
                count($data) !== 7 ||
                ($data["kind"] ?? null) !== "moodle-initial-binding" ||
                !is_int($data["expires_at"] ?? null)
            ) {
                throw new InvalidArgumentException();
            }
            foreach (["actor_id", "id", "site_fingerprint", "instance_fingerprint", "reason"] as $field) {
                if (!is_string($data[$field] ?? null)) {
                    throw new InvalidArgumentException();
                }
            }
            new MoodleBinding(
                new EntityId($data["id"]),
                $data["site_fingerprint"],
                $data["instance_fingerprint"],
                new EntityId($data["actor_id"]),
                "",
            );
            $this->reason($data["reason"]);
            return $data;
        } catch (Throwable) {
            self::invalid("review_token", "Review the installation again before approving.");
        }
    }

    /** @param array{id:string,site_fingerprint:string,instance_fingerprint:string} $data */
    private function completed(array $data, EntityId $actor): ?MoodleBinding
    {
        $binding = $this->bindings->current();
        $this->actor($actor);
        if ($binding === null) {
            return null;
        }
        if (
            $binding->id->value !== $data["id"] ||
            $binding->approvedBy->value !== $actor->value ||
            !hash_equals($binding->siteFingerprint, $data["site_fingerprint"]) ||
            !hash_equals($binding->instanceFingerprint, $data["instance_fingerprint"])
        ) {
            throw new ConcurrentModificationException(
                "An installation has already been approved. Refresh its saved details.",
            );
        }
        return $binding;
    }

    private function unexpired(int $expires): void
    {
        if ($expires <= $this->clock->now()->getTimestamp()) {
            self::invalid("review_token", "This review expired. Review the installation again.");
        }
    }

    private static function invalid(string $field, string $message): never
    {
        throw new ValidationException([["field" => $field, "message" => $message]]);
    }
}
