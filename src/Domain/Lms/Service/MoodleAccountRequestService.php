<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use DateTimeZone;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Lms\Repository\MoodleAccountDefaults;
use Domain\Lms\Repository\MoodleAccountRequestRepository;
use Domain\Lms\Repository\MoodleBindingRepository;
use Domain\Lms\Repository\MoodlePersonLinkRepository;
use Domain\Lms\ValueObject\MoodleAccountDraft;
use Domain\Lms\ValueObject\MoodleAccountRequest;
use Domain\Lms\ValueObject\MoodleBinding;
use Domain\Person\Person;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Service\UserAccountAccess;
use InvalidArgumentException;
use Throwable;

/** Review and durable intake only. No account creation, email or external write occurs here. */
final readonly class MoodleAccountRequestService
{
    public const string CREATE = "sis:moodle:create-person";

    public function __construct(
        private MoodleAccountRequestPolicy $policy,
        private MoodleInstanceReader $reader,
        private MoodleAccountProvisioner $provisioner,
        private MoodleAccountRequestRepository $requests,
        private MoodleAccountDefaults $defaults,
        private MoodleBindingRepository $bindings,
        private MoodlePersonLinkRepository $links,
        private AdmissionsRepository $people,
        private UserAccountAccess $accounts,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** @return array<string,mixed> */
    public function status(EntityId $id): array
    {
        [$actor, $person] = $this->authorize($id);
        $binding = $this->bindings->current();
        $request = $binding === null ? null : $this->requests->forPerson($binding->id, $id);
        $link = $binding === null ? null : $this->links->forPerson($binding->id, $id);
        $enabled = $this->policy->enabled() && $this->reader->enabled();
        $defaults = null;
        if ($enabled && $request === null && $link === null) {
            $defaults = $this->defaults->forOwner($person->ownerUserId);
        }
        $this->actor($actor, $id);
        return [
            "enabled" => $enabled,
            "installation_approved" => $binding !== null,
            "processing_available" => $this->policy->processingEnabled(),
            "receipt_inspection_available" => $this->reader->enabled(),
            "defaults" => $defaults,
            "person" => [
                "id" => $id->value,
                "name" => $person->givenName . " " . $person->familyName,
                "institutional_id" => $person->institutionalId->value,
            ],
            "link" => $link?->describe(),
            "request" => $request === null ? null : $this->describe($request),
        ];
    }

    /** @return array<string,mixed> */
    public function review(
        EntityId $id,
        #[\SensitiveParameter] MoodleAccountDraft $draft,
        #[\SensitiveParameter] string $reason,
    ): array {
        [$actor, $person] = $this->authorize($id);
        $this->enabled();
        $reason = $this->reason($reason);
        $binding = $this->binding();
        $this->available($binding, $id);
        $revision = $this->revision($person);
        $this->verify($actor, $id, $binding);
        $policy = $this->provisioner->policy($binding);
        if (!$policy->permits($draft->signIn)) {
            self::invalid(
                "sign_in",
                "This sign-in workflow is not enabled in the approved Moodle installation.",
            );
        }
        $this->enabled();
        $this->current($actor, $id, $binding, $revision);
        $this->available($binding, $id);
        $data = [
            "kind" => "moodle-account-request",
            "id" => EntityId::generate()->value,
            "actor_id" => $actor->value,
            "person_id" => $id->value,
            "binding_id" => $binding->id->value,
            "binding_hash" => $this->bindingHash($binding),
            "person_revision" => $revision,
            "firstname" => $person->givenName,
            "lastname" => $person->familyName,
            "username" => $draft->username,
            "email" => $draft->email,
            "sign_in" => $draft->signIn,
            "reason" => $reason,
            "policy_hash" => $policy->hash,
            "expires_at" => $this->clock->now()->getTimestamp() + 1200,
        ];
        return [
            "review_token" => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR)),
            "person_name" => $person->givenName . " " . $person->familyName,
            "username" => $draft->username,
            "email" => $draft->email,
            "sign_in" => $draft->signIn,
            "expires_at" => $data["expires_at"],
            "processing_available" => $this->policy->processingEnabled(),
        ];
    }

    /** @return array{request:array<string,string>,processing_available:bool} */
    public function confirm(EntityId $id, #[\SensitiveParameter] string $token): array
    {
        [$actor, $person] = $this->authorize($id);
        $data = $this->proof($token);
        if ($data["actor_id"] !== $actor->value || $data["person_id"] !== $id->value) {
            throw new AccessDeniedException(
                "This account review belongs to another person or administrator.",
            );
        }
        $binding = $this->binding();
        if (
            $binding->id->value !== $data["binding_id"] ||
            !hash_equals($this->bindingHash($binding), $data["binding_hash"])
        ) {
            throw new ConcurrentModificationException(
                "The approved installation changed. Review the account request again.",
            );
        }
        $hash = hash("sha256", json_encode($data, JSON_THROW_ON_ERROR));
        $existing = $this->completed($binding, $id, $data["id"], $actor, $hash);
        if ($existing !== null) {
            $this->actor($actor, $id);
            return $this->result($existing);
        }
        $this->unexpired($data["expires_at"]);
        $this->current($actor, $id, $binding, $data["person_revision"]);
        $this->verify($actor, $id, $binding);
        $policy = $this->provisioner->policy($binding);
        if (!hash_equals($data["policy_hash"], $policy->hash) || !$policy->permits($data["sign_in"])) {
            self::invalid("sign_in", "Moodle authentication setup changed. Review the request again.");
        }
        $save = function () use ($actor, $id, $binding, $data, $hash): MoodleAccountRequest {
            $this->people->lockPerson($id);
            $this->actor($actor, $id);
            $existing = $this->completed($binding, $id, $data["id"], $actor, $hash);
            if ($existing !== null) {
                return $existing;
            }
            $this->unexpired($data["expires_at"]);
            $this->current($actor, $id, $binding, $data["person_revision"]);
            $this->enabled();
            $at = $this->clock->now()->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d\TH:i:s\Z");
            $request = new MoodleAccountRequest(
                new EntityId($data["id"]),
                $binding->id,
                $id,
                $actor,
                $at,
                $hash,
            );
            $this->requests->add($request, $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR)));
            $this->requests->enqueue($request->id, $at);
            $this->audit->record($actor, "moodle.account_creation_requested", $id);
            $this->actor($actor, $id);
            $this->enabled();
            return $request;
        };
        if ($person->ownerUserId === null) {
            $request = $this->transactions->run($save);
        } else {
            $request = $this->accounts->update($person->ownerUserId, $save);
        }
        return $this->result($request);
    }

    /** @return array{EntityId,Person} */
    private function authorize(EntityId $id): array
    {
        $actor = $this->access->requirePermission(self::CREATE);
        if ($this->access->requirePermission("sis:person:manage")->value !== $actor->value) {
            throw new AccessDeniedException("The administrator changed. Reload before continuing.");
        }
        $person = $this->people->person($id);
        if ($person->ownerUserId !== null) {
            $this->accounts->authorizeUpdate($person->ownerUserId);
        }
        return [$actor, $person];
    }

    private function actor(EntityId $actor, EntityId $id): void
    {
        if ($this->authorize($id)[0]->value !== $actor->value) {
            throw new AccessDeniedException("The administrator changed. Reload before continuing.");
        }
    }

    private function enabled(): void
    {
        if (!$this->policy->enabled() || !$this->reader->enabled()) {
            self::invalid(
                "configuration",
                "Moodle account request intake is disabled. Ask an administrator to review its setup.",
            );
        }
    }

    private function binding(): MoodleBinding
    {
        $binding = $this->bindings->current();
        if ($binding === null) {
            self::invalid("installation", "Approve the Moodle installation before requesting accounts.");
        }
        return $binding;
    }

    private function available(MoodleBinding $binding, EntityId $id): void
    {
        if (
            $this->requests->forPerson($binding->id, $id) !== null ||
            $this->links->forPerson($binding->id, $id) !== null
        ) {
            throw new ConcurrentModificationException(
                "A Moodle link or creation request already exists. Refresh the saved status.",
            );
        }
    }

    private function completed(
        MoodleBinding $binding,
        EntityId $id,
        string $operation,
        EntityId $actor,
        string $hash,
    ): ?MoodleAccountRequest {
        $request = $this->requests->forPerson($binding->id, $id);
        if (
            $request !== null &&
            $request->id->value === $operation &&
            $request->requestedBy->value === $actor->value &&
            hash_equals($request->reviewHash, $hash)
        ) {
            return $request;
        }
        $this->available($binding, $id);
        return null;
    }

    private function verify(EntityId $actor, EntityId $id, MoodleBinding $binding): void
    {
        $this->enabled();
        $this->audit->record($actor, "moodle.account_request_check_requested", $id);
        $this->actor($actor, $id);
        $this->enabled();
        try {
            $installation = $this->reader->identify();
        } catch (MoodleConnectionUnavailableException $error) {
            $this->actor($actor, $id);
            throw new MoodleConnectionUnavailableException($error->setupIssue);
        } catch (Throwable) {
            $this->actor($actor, $id);
            throw new MoodleConnectionUnavailableException();
        }
        $this->actor($actor, $id);
        $this->enabled();
        if (!$binding->matches($installation)) {
            throw new ConcurrentModificationException(
                "Moodle differs from the reviewed installation. Review its setup before requesting an account.",
            );
        }
    }

    private function current(EntityId $actor, EntityId $id, MoodleBinding $binding, string $revision): void
    {
        [$current, $person] = $this->authorize($id);
        if ($current->value !== $actor->value) {
            throw new AccessDeniedException("The administrator changed.");
        }
        if (
            !hash_equals($revision, $this->revision($person)) ||
            !hash_equals($this->bindingHash($binding), $this->bindingHash($this->binding()))
        ) {
            throw new ConcurrentModificationException(
                "The person or approved installation changed. Review the account request again.",
            );
        }
    }

    private function revision(Person $person): string
    {
        return new MoodleAccountProfile($this->defaults)->revision($person);
    }

    private function bindingHash(MoodleBinding $binding): string
    {
        return hash("sha256", json_encode($binding->describe(), JSON_THROW_ON_ERROR));
    }

    private function reason(#[\SensitiveParameter] string $reason): string
    {
        $reason = trim($reason);
        if ($reason === "" || mb_strlen($reason) > 500 || preg_match('/[\x00-\x1F\x7F]/', $reason)) {
            self::invalid(
                "reason",
                "Enter an administrative reason of at most 500 characters without credentials or academic details.",
            );
        }
        return $reason;
    }

    /** @return array<string,mixed> */
    private function proof(#[\SensitiveParameter] string $token): array
    {
        try {
            if ($token === "" || strlen($token) > 16384) {
                throw new InvalidArgumentException();
            }
            $data = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($data) ||
                count($data) !== 15 ||
                ($data["kind"] ?? null) !== "moodle-account-request" ||
                !is_int($data["expires_at"] ?? null)
            ) {
                throw new InvalidArgumentException();
            }
            $fields = [
                "id",
                "actor_id",
                "person_id",
                "binding_id",
                "binding_hash",
                "person_revision",
                "firstname",
                "lastname",
                "username",
                "email",
                "sign_in",
                "reason",
                "policy_hash",
            ];
            foreach ($fields as $field) {
                if (!is_string($data[$field] ?? null)) {
                    throw new InvalidArgumentException();
                }
            }
            foreach (["id", "actor_id", "person_id", "binding_id"] as $field) {
                new EntityId($data[$field]);
            }
            foreach (["binding_hash", "person_revision", "policy_hash"] as $field) {
                if (preg_match('/^[a-f0-9]{64}$/D', $data[$field]) !== 1) {
                    throw new InvalidArgumentException();
                }
            }
            new MoodleAccountDraft($data["username"], $data["email"], $data["sign_in"]);
            $this->reason($data["reason"]);
            return $data;
        } catch (Throwable) {
            self::invalid("review_token", "Review this account request again before saving.");
        }
    }

    /** @return array<string,string> */
    private function describe(MoodleAccountRequest $request): array
    {
        $processing = $this->requests->processing($request->id);
        return $request->describe() + [
            "state" => $processing["state"],
            "issue_code" => $processing["issue_code"],
            "issue" => $processing["issue"],
        ];
    }

    /** @return array{request:array<string,string>,processing_available:bool} */
    private function result(MoodleAccountRequest $request): array
    {
        return [
            "request" => $this->describe($request),
            "processing_available" => $this->policy->processingEnabled(),
        ];
    }

    private function unexpired(int $expires): void
    {
        if ($expires <= $this->clock->now()->getTimestamp()) {
            self::invalid("review_token", "The review expired. Review the account request again.");
        }
    }

    private static function invalid(string $field, string $message): never
    {
        throw new ValidationException([["field" => $field, "message" => $message]]);
    }
}
