<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use DateTimeZone;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Lms\Repository\MoodleBindingRepository;
use Domain\Lms\Repository\MoodlePersonLinkRepository;
use Domain\Lms\ValueObject\MoodleBinding;
use Domain\Lms\ValueObject\MoodleExternalId;
use Domain\Lms\ValueObject\MoodlePersonLink;
use Domain\Lms\ValueObject\MoodleRecordCheck;
use Domain\Lms\ValueObject\MoodleResourceKind;
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

/** Explicit administrative reference only; never match by email or enable sign-in here. */
final readonly class MoodlePersonLinkService
{
    public const string LINK = "sis:moodle:link-person";

    public function __construct(
        private MoodleBoundRecordReader $reader,
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
        $link = $binding === null ? null : $this->links->forPerson($binding->id, $id);
        $this->actor($actor, $id);
        return [
            "enabled" => $this->reader->enabled(),
            "installation_approved" => $binding !== null,
            "person" => [
                "id" => $id->value,
                "name" => $person->givenName . " " . $person->familyName,
                "institutional_id" => $person->institutionalId->value,
            ],
            "link" => $link?->describe(),
        ];
    }

    /** @return array<string,mixed> */
    public function review(
        EntityId $id,
        MoodleExternalId $external,
        #[\SensitiveParameter] string $reason,
    ): array {
        [$actor, $person] = $this->authorize($id);
        $reason = $this->reason($reason);
        $this->enabled();
        $binding = $this->binding();
        $this->available($binding, $id, $external);
        $revision = $this->revision($person);
        $record = $this->read($actor, $id, $binding, $external);
        $this->available($binding, $id, $external);
        $this->assertLocal($actor, $id, $binding, $revision);
        $data = [
            "kind" => "moodle-person-link",
            "actor_id" => $actor->value,
            "id" => EntityId::generate()->value,
            "person_id" => $id->value,
            "binding_id" => $binding->id->value,
            "external_id" => $external->value,
            "person_revision" => $revision,
            "binding_hash" => $this->bindingHash($binding),
            "status" => $record->describe(),
            "reason" => $reason,
            "expires_at" => $this->clock->now()->getTimestamp() + 1200,
        ];
        return [
            "review_token" => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR)),
            "moodle_user_id" => $external->value,
            "status" => $record->describe(),
            "expires_at" => $data["expires_at"],
        ];
    }

    /** @return array{link:array<string,string>} */
    public function confirm(EntityId $id, #[\SensitiveParameter] string $token): array
    {
        [$actor, $person] = $this->authorize($id);
        $data = $this->proof($token);
        if ($data["actor_id"] !== $actor->value || $data["person_id"] !== $id->value) {
            throw new AccessDeniedException(
                "This Moodle link review belongs to another person or administrator.",
            );
        }
        $binding = $this->binding();
        $this->assertBinding($binding, $data);
        $external = new MoodleExternalId($data["external_id"]);
        $existing = $this->completed($binding, $id, $external, $data["id"], $actor);
        if ($existing !== null) {
            $this->actor($actor, $id);
            return ["link" => $existing->describe()];
        }
        $this->unexpired($data["expires_at"]);
        $this->assertLocal($actor, $id, $binding, $data["person_revision"]);
        $record = $this->read($actor, $id, $binding, $external);
        if ($record->describe() !== $data["status"]) {
            throw new ConcurrentModificationException(
                "The Moodle account status changed. Review the link again.",
            );
        }
        $save = function () use ($actor, $id, $binding, $external, $data): MoodlePersonLink {
            $this->people->lockPerson($id);
            $this->actor($actor, $id);
            $this->assertBinding($this->binding(), $data);
            $existing = $this->completed($binding, $id, $external, $data["id"], $actor);
            if ($existing !== null) {
                return $existing;
            }
            $this->unexpired($data["expires_at"]);
            $this->assertLocal($actor, $id, $binding, $data["person_revision"]);
            $this->enabled();
            $link = new MoodlePersonLink(
                new EntityId($data["id"]),
                $binding->id,
                $id,
                $external,
                $actor,
                $this->clock->now()->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d\TH:i:s\Z"),
            );
            $this->links->add(
                $link,
                $this->cipher->seal(
                    json_encode(
                        [
                            "reason" => $data["reason"],
                            "reviewed_status" => $data["status"],
                            "person_revision" => $data["person_revision"],
                        ],
                        JSON_THROW_ON_ERROR,
                    ),
                ),
            );
            $this->audit->record($actor, "moodle.person_link_approved", $id);
            $this->actor($actor, $id);
            $this->enabled();
            return $link;
        };
        if ($person->ownerUserId === null) {
            $link = $this->transactions->run($save);
        } else {
            $link = $this->accounts->update($person->ownerUserId, $save);
        }
        return ["link" => $link->describe()];
    }

    private function read(
        EntityId $actor,
        EntityId $person,
        MoodleBinding $binding,
        MoodleExternalId $external,
    ): MoodleRecordCheck {
        $this->enabled();
        $this->audit->record($actor, "moodle.person_link_check_requested", $person);
        $this->actor($actor, $person);
        $this->enabled();
        try {
            $record = $this->reader->inspectBound($binding, MoodleResourceKind::User, $external);
        } catch (MoodleConnectionUnavailableException $error) {
            $this->actor($actor, $person);
            throw new MoodleConnectionUnavailableException($error->setupIssue);
        } catch (Throwable) {
            $this->actor($actor, $person);
            throw new MoodleConnectionUnavailableException();
        }
        $this->actor($actor, $person);
        $this->enabled();
        if ($record->kind !== MoodleResourceKind::User) {
            throw new MoodleConnectionUnavailableException();
        }
        if (!$record->returned) {
            self::invalid(
                "moodle_user_id",
                "No matching Moodle account was returned. It may be missing or outside the service account scope.",
            );
        }
        if (in_array(true, $record->describe(), true)) {
            self::invalid(
                "moodle_user_id",
                "A suspended or deleted Moodle account cannot receive a new link.",
            );
        }
        return $record;
    }

    /** @return array{EntityId,Person} */
    private function authorize(EntityId $id): array
    {
        $actor = $this->access->requirePermission(self::LINK);
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
        if (!$this->reader->enabled()) {
            throw new MoodleConnectionUnavailableException();
        }
    }

    private function binding(): MoodleBinding
    {
        $binding = $this->bindings->current();
        if ($binding === null) {
            self::invalid("installation", "Ask a Moodle administrator to review and approve the installation first.");
        }
        return $binding;
    }

    private function available(MoodleBinding $binding, EntityId $person, MoodleExternalId $external): void
    {
        if (
            $this->links->forPerson($binding->id, $person) !== null ||
            $this->links->forExternal($binding->id, $external) !== null
        ) {
            throw new ConcurrentModificationException(
                "One of these records is already linked. Refresh the saved details.",
            );
        }
    }

    private function completed(
        MoodleBinding $binding,
        EntityId $person,
        MoodleExternalId $external,
        string $operation,
        EntityId $actor,
    ): ?MoodlePersonLink {
        $link = $this->links->forPerson($binding->id, $person);
        if (
            $link !== null &&
            $link->id->value === $operation &&
            $link->external->value === $external->value &&
            $link->approvedBy->value === $actor->value
        ) {
            return $link;
        }
        $this->available($binding, $person, $external);
        return null;
    }

    private function assertLocal(
        EntityId $actor,
        EntityId $id,
        MoodleBinding $binding,
        string $revision,
    ): void {
        [$currentActor, $person] = $this->authorize($id);
        if ($currentActor->value !== $actor->value) {
            throw new AccessDeniedException("The administrator changed.");
        }
        if (
            !hash_equals($revision, $this->revision($person)) ||
            $this->bindingHash($this->binding()) !== $this->bindingHash($binding)
        ) {
            throw new ConcurrentModificationException(
                "The person or approved installation changed. Review the link again.",
            );
        }
    }

    private function revision(Person $person): string
    {
        return hash(
            "sha256",
            json_encode(
                [
                    $person->id->value,
                    $person->ownerUserId?->value,
                    $person->givenName,
                    $person->familyName,
                    $person->institutionalId->value,
                    $person->identityVersion,
                    $person->directoryRestricted,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function bindingHash(MoodleBinding $binding): string
    {
        return hash("sha256", json_encode($binding->describe(), JSON_THROW_ON_ERROR));
    }

    /** @param array{binding_id:string,binding_hash:string} $data */
    private function assertBinding(MoodleBinding $binding, array $data): void
    {
        if (
            $binding->id->value !== $data["binding_id"] ||
            !hash_equals($this->bindingHash($binding), $data["binding_hash"])
        ) {
            throw new ConcurrentModificationException(
                "The approved installation changed. Review the link again.",
            );
        }
    }

    private function reason(#[\SensitiveParameter] string $reason): string
    {
        $reason = trim($reason);
        if ($reason === "" || mb_strlen($reason) > 500 || preg_match('/[\x00-\x1F\x7F]/', $reason)) {
            self::invalid(
                "reason",
                "Enter an administrative reason of at most 500 characters without student details or credentials.",
            );
        }
        return $reason;
    }

    /** @return array{kind:string,actor_id:string,id:string,person_id:string,binding_id:string,external_id:string,person_revision:string,binding_hash:string,status:array<string,?bool>,reason:string,expires_at:int} */
    private function proof(#[\SensitiveParameter] string $token): array
    {
        try {
            if ($token === "" || strlen($token) > 16384) {
                throw new InvalidArgumentException();
            }
            $data = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($data) ||
                count($data) !== 11 ||
                ($data["kind"] ?? null) !== "moodle-person-link" ||
                !is_int($data["expires_at"] ?? null) ||
                !is_array($data["status"] ?? null)
            ) {
                throw new InvalidArgumentException();
            }
            $reviewFields = ["actor_id", "id", "person_id", "binding_id", "external_id",
                "person_revision", "binding_hash", "reason"];
            foreach ($reviewFields as $key) {
                if (!is_string($data[$key] ?? null)) {
                    throw new InvalidArgumentException();
                }
            }
            foreach (["actor_id", "id", "person_id", "binding_id"] as $key) {
                new EntityId($data[$key]);
            }
            foreach (["person_revision", "binding_hash"] as $key) {
                if (preg_match('/^[a-f0-9]{64}$/D', $data[$key]) !== 1) {
                    throw new InvalidArgumentException();
                }
            }
            new MoodleExternalId($data["external_id"]);
            if (
                new MoodleRecordCheck(MoodleResourceKind::User, true, $data["status"])->describe() !==
                $data["status"]
            ) {
                throw new InvalidArgumentException();
            }
            $this->reason($data["reason"]);
            return $data;
        } catch (Throwable) {
            self::invalid("review_token", "Review this Moodle person link again before approving.");
        }
    }

    private function unexpired(int $expires): void
    {
        if ($expires <= $this->clock->now()->getTimestamp()) {
            self::invalid("review_token", "This review expired. Review the person link again.");
        }
    }

    private static function invalid(string $field, string $message): never
    {
        throw new ValidationException([["field" => $field, "message" => $message]]);
    }
}
