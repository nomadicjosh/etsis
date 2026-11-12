<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Lms\Repository\{MoodleBindingRepository, MoodlePersonLinkRepository, MoodleProvisioningQueue};
use Domain\Lms\ValueObject\{MoodleAccountDraft, MoodleBinding, MoodlePersonLink};
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Throwable;

/** One bounded operation per invocation. No network call holds a database lock. */
final readonly class MoodleProvisioningWorker
{
    public function __construct(
        private MoodleAccountRequestPolicy $policy,
        private MoodleProvisioningQueue $queue,
        private MoodleBindingRepository $bindings,
        private MoodlePersonLinkRepository $links,
        private AdmissionsRepository $people,
        private AccessRepository $access,
        private MoodleAccountProfile $profiles,
        private MoodleAccountProvisioner $remote,
        private MessageCipher $cipher,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }
    public function runOne(): string
    {
        if (!$this->policy->processingEnabled()) {
            return "disabled";
        }
        $claim = $this->transactions->run(fn() => $this->queue->claim($this->clock->now()->getTimestamp()));
        if ($claim === null) {
            return "idle";
        }
        $job = new EntityId($claim["id"]);
        $version = $claim["version"];
        if ($claim["attempts"] > 5) {
            $this->queue->finish($job, $version, "review_required", "attempt_limit");
            return "review_required";
        }
        try {
            $row = $this->queue->approval(new EntityId($claim["request_id"]));
            $data = $this->approval($row);
            $binding = $this->bindings->current();
            if ($binding === null) {
                throw new AccessDeniedException("Installation approval required.");
            }
            $this->transactions->run(function () use ($row, $data, $binding): void {
                $this->validate($row, $data, $binding);
            });
        } catch (Throwable) {
            $this->queue->finish($job, $version, "review_required", "request_changed");
            return "review_required";
        }
        try {
            // Retry the same operation after a timeout. Moodle owns the creation receipt.
            $result = $this->remote->provision(
                $binding,
                new EntityId($row["id"]),
                $data["policy_hash"],
                new MoodleAccountDraft($data["username"], $data["email"], $data["sign_in"]),
                $data["firstname"],
                $data["lastname"],
            );
        } catch (MoodleConnectionUnavailableException) {
            $this->queue->finish($job, $version, "processing", "connection_uncertain");
            return "retry_pending";
        } catch (Throwable) {
            $this->queue->finish($job, $version, "processing", "connection_uncertain");
            return "retry_pending";
        }
        // Changes during the external effect require review; never forget the remote operation ID.
        try {
            $this->transactions->run(function () use ($row, $data, $binding): void {
                $this->validate($row, $data, $binding);
            });
        } catch (Throwable) {
            $this->queue->finish($job, $version, "review_required", "request_changed");
            return "review_required";
        }
        return $this->transactions->run(function () use (
            $row,
            $data,
            $binding,
            $result,
            $job,
            $version,
        ): string {
            $this->validate($row, $data, $binding);
            $person = new EntityId($row["person_id"]);
            $actor = new EntityId($row["requested_by"]);
            if ($this->links->forExternal($binding->id, $result->user) !== null) {
                $this->queue->finish($job, $version, "review_required", "request_changed");
                return "review_required";
            }
            $at = $this->clock->now()->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d\TH:i:s\Z");
            $this->links->add(
                new MoodlePersonLink(EntityId::generate(), $binding->id, $person, $result->user, $actor, $at),
                $this->cipher->seal(
                    json_encode(
                        [
                            "kind" => "moodle-provisioned-link",
                            "request_id" => $row["id"],
                            "policy_hash" => $data["policy_hash"],
                            "sign_in_status" => $result->signInStatus,
                        ],
                        JSON_THROW_ON_ERROR,
                    ),
                ),
            );
            $needsReview = $result->signInStatus === "email_review_required";
            $state = $needsReview ? "review_required" : "completed";
            $this->queue->finish($job, $version, $state, $needsReview ? "email_delivery" : "none");
            $this->audit->record($actor, "moodle.account_provisioned", $person);
            return $state;
        });
    }
    /** @param array<string,mixed> $row
     * @return array<string,string> */
    private function approval(#[\SensitiveParameter] array $row): array
    {
        $data = json_decode($this->cipher->open($row["encrypted_approval"]), true, 8, JSON_THROW_ON_ERROR);
        if (
            !is_array($data) ||
            ($data["kind"] ?? null) !== "moodle-account-request" ||
            !hash_equals($row["review_hash"], hash("sha256", json_encode($data, JSON_THROW_ON_ERROR)))
        ) {
            throw new AccessDeniedException("Invalid account approval.");
        }
        $fields = ["id", "actor_id", "person_id", "binding_id", "binding_hash", "person_revision",
            "firstname", "lastname", "username", "email", "sign_in", "policy_hash"];
        foreach ($fields as $field) {
            if (!is_string($data[$field] ?? null)) {
                throw new AccessDeniedException("Review the account policy again.");
            }
        }
        $references = ["id" => "id", "requested_by" => "actor_id", "person_id" => "person_id",
            "binding_id" => "binding_id"];
        foreach ($references as $column => $field) {
            if ($row[$column] !== $data[$field]) {
                throw new AccessDeniedException("Invalid approval reference.");
            }
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $data["policy_hash"]) !== 1) {
            throw new AccessDeniedException("Invalid account policy.");
        }
        return array_filter($data, is_string(...));
    }
    /** @param array<string,mixed> $row
     * @param array<string,string> $data */
    private function validate(array $row, #[\SensitiveParameter] array $data, MoodleBinding $binding): void
    {
        $this->access->lock();
        $personId = new EntityId($row["person_id"]);
        $this->people->lockPerson($personId);
        $person = $this->people->person($personId);
        $actor = $row["requested_by"];
        $owner = $person->ownerUserId?->value;
        $current = $this->bindings->current();
        if (
            !$this->policy->processingEnabled() ||
            !$this->policy->enabled() ||
            !$this->access->allows($actor, MoodleAccountRequestService::CREATE) ||
            !$this->access->allows($actor, "sis:person:manage") ||
            ($owner !== null &&
                (!$this->access->allows($actor, $owner === $actor ? "admin:profile" : "admin:edit:user") ||
                    ($this->access->isSuper($owner) && !$this->access->isSuper($actor)))) ||
            $current === null ||
            $current->id->value !== $row["binding_id"] ||
            $binding->id->value !== $row["binding_id"] ||
            !hash_equals(
                $data["binding_hash"],
                hash("sha256", json_encode($current->describe(), JSON_THROW_ON_ERROR)),
            ) ||
            !hash_equals($data["person_revision"], $this->profiles->revision($person)) ||
            $this->links->forPerson($binding->id, $personId) !== null
        ) {
            throw new AccessDeniedException("The account request needs review.");
        }
    }
}
