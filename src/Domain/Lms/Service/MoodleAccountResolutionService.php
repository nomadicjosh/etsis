<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Lms\Repository\MoodleAccountRequestRepository;
use Domain\Lms\Repository\MoodleBindingRepository;
use Domain\Lms\Repository\MoodlePersonLinkRepository;
use Domain\Person\Person;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Service\UserAccountAccess;
use Throwable;

/** Record a staff-verified access resolution. Never retry creation, resend mail or change a link. */
final readonly class MoodleAccountResolutionService
{
    public function __construct(
        private MoodleAccountRequestRepository $requests,
        private MoodleBindingRepository $bindings,
        private MoodlePersonLinkRepository $links,
        private AdmissionsRepository $people,
        private MoodleAccountProfile $profiles,
        private UserAccountAccess $accounts,
        private AccessRepository $roles,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** @return array<string,mixed> */
    public function review(EntityId $personId, #[\SensitiveParameter] string $reason): array
    {
        [$actor, $person] = $this->authorize($personId);
        $reason = $this->reason($reason);
        [$context, $state] = $this->context($personId);
        $this->pending($state);
        $data = $context + [
            "kind" => "moodle-email-access-resolution",
            "actor_id" => $actor->value,
            "person_id" => $personId->value,
            "person_revision" => $this->profiles->revision($person),
            "version" => $state["version"],
            "reason" => $reason,
            "expires_at" => $this->clock->now()->getTimestamp() + 1200,
        ];
        $this->sameActor($actor, $personId);
        return [
            "review_token" => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR)),
            "person_name" => $person->givenName . " " . $person->familyName,
            "moodle_user_id" => $context["moodle_user_id"],
            "reason" => $reason,
            "expires_at" => $data["expires_at"],
        ];
    }

    /** @return array{state:string} */
    public function confirm(EntityId $personId, #[\SensitiveParameter] string $token): array
    {
        [$actor] = $this->authorize($personId);
        $data = $this->proof($token);
        if ($data["actor_id"] !== $actor->value || $data["person_id"] !== $personId->value) {
            throw new AccessDeniedException("This access review belongs to another person or administrator.");
        }
        return $this->transactions->run(function () use ($actor, $personId, $data): array {
            $this->roles->lock();
            $this->people->lockPerson($personId);
            $person = $this->sameActor($actor, $personId);
            [$context, $state] = $this->context($personId);
            foreach ($context as $field => $value) {
                if (!is_string($data[$field]) || !hash_equals($value, $data[$field])) {
                    throw new ConcurrentModificationException(
                        "The saved account link changed. Review it again.",
                    );
                }
            }
            $hash = hash("sha256", json_encode($data, JSON_THROW_ON_ERROR));
            if (
                $state["state"] === "completed" &&
                $state["issue_code"] === "email_resolved" &&
                $state["resolution_hash"] !== null &&
                hash_equals($state["resolution_hash"], $hash)
            ) {
                return ["state" => "completed"];
            }
            $this->pending($state);
            if ($data["expires_at"] <= $this->clock->now()->getTimestamp()) {
                self::invalid("review_token", "This access review expired. Review the resolution again.");
            }
            if (
                $state["version"] !== $data["version"] ||
                !hash_equals($data["person_revision"], $this->profiles->revision($person))
            ) {
                throw new ConcurrentModificationException("The person or request changed. Review it again.");
            }
            $at = $this->clock->now()->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d\TH:i:s\Z");
            $this->requests->resolveEmail(
                new EntityId($context["request_id"]),
                $data["version"],
                $hash,
                $this->cipher->seal(
                    json_encode(["review" => $data, "resolved_at" => $at], JSON_THROW_ON_ERROR),
                ),
            );
            $this->audit->record($actor, "moodle.account_access_resolved", $personId);
            $this->sameActor($actor, $personId);
            return ["state" => "completed"];
        });
    }

    /** @return array{EntityId,Person} */
    private function authorize(EntityId $id): array
    {
        $actor = $this->access->requirePermission(MoodleAccountRequestService::CREATE);
        if ($this->access->requirePermission("sis:person:manage")->value !== $actor->value) {
            throw new AccessDeniedException("The administrator changed. Reload before continuing.");
        }
        $person = $this->people->person($id);
        if ($person->ownerUserId !== null) {
            $this->accounts->authorizeUpdate($person->ownerUserId);
        }
        return [$actor, $person];
    }

    private function sameActor(EntityId $actor, EntityId $id): Person
    {
        [$current, $person] = $this->authorize($id);
        if ($current->value !== $actor->value) {
            throw new AccessDeniedException("The administrator changed. Reload before continuing.");
        }
        return $person;
    }

    /** @return array{array<string,string>,array{version:int,state:string,issue_code:string,resolution_hash:?string}} */
    private function context(EntityId $person): array
    {
        $binding = $this->bindings->current();
        $request = $binding === null ? null : $this->requests->forPerson($binding->id, $person);
        $link = $binding === null ? null : $this->links->forPerson($binding->id, $person);
        if ($binding === null || $request === null || $link === null) {
            throw new ConcurrentModificationException(
                "A saved creation request and linked account are required.",
            );
        }
        return [
            [
                "binding_id" => $binding->id->value,
                "binding_hash" => hash("sha256", json_encode($binding->describe(), JSON_THROW_ON_ERROR)),
                "request_id" => $request->id->value,
                "link_id" => $link->id->value,
                "moodle_user_id" => $link->external->value,
            ],
            $this->requests->processing($request->id),
        ];
    }

    /** @param array{state:string,issue_code:string,resolution_hash:?string} $state */
    private function pending(array $state): void
    {
        if (
            $state["state"] !== "review_required" ||
            $state["issue_code"] !== "email_delivery" ||
            $state["resolution_hash"] !== null
        ) {
            throw new ConcurrentModificationException(
                "Only a linked account awaiting setup-email review can be resolved here. Refresh its status.",
            );
        }
    }

    private function reason(#[\SensitiveParameter] string $reason): string
    {
        $reason = trim($reason);
        if ($reason === "" || mb_strlen($reason) > 500 || preg_match('/[\x00-\x1F\x7F]/', $reason)) {
            self::invalid(
                "reason",
                "Describe the access resolution in at most 500 characters, without credentials or academic details.",
            );
        }
        return $reason;
    }

    /** @return array{kind:string,actor_id:string,person_id:string,person_revision:string,version:int,reason:string,expires_at:int,binding_id:string,binding_hash:string,request_id:string,link_id:string,moodle_user_id:string} */
    private function proof(#[\SensitiveParameter] string $token): array
    {
        try {
            if ($token === "" || strlen($token) > 16384) {
                throw new \InvalidArgumentException();
            }
            $data = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($data) ||
                count($data) !== 12 ||
                ($data["kind"] ?? null) !== "moodle-email-access-resolution" ||
                !is_int($data["version"] ?? null) ||
                $data["version"] < 0 ||
                !is_int($data["expires_at"] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
            $fields = [
                "actor_id",
                "person_id",
                "person_revision",
                "reason",
                "binding_id",
                "binding_hash",
                "request_id",
                "link_id",
                "moodle_user_id",
            ];
            foreach ($fields as $field) {
                if (!is_string($data[$field] ?? null)) {
                    throw new \InvalidArgumentException();
                }
            }
            foreach (["actor_id", "person_id", "binding_id", "request_id", "link_id"] as $field) {
                new EntityId($data[$field]);
            }
            new \Domain\Lms\ValueObject\MoodleExternalId($data["moodle_user_id"]);
            foreach (["person_revision", "binding_hash"] as $field) {
                if (preg_match('/^[a-f0-9]{64}$/D', $data[$field]) !== 1) {
                    throw new \InvalidArgumentException();
                }
            }
            $this->reason($data["reason"]);
            return $data;
        } catch (Throwable) {
            self::invalid(
                "review_token",
                "Review the existing account's access resolution again before saving.",
            );
        }
    }

    private static function invalid(string $field, string $message): never
    {
        throw new ValidationException([["field" => $field, "message" => $message]]);
    }
}
