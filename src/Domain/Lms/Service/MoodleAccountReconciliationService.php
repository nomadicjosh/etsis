<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Access\Repository\AccessRepository;
use Domain\Communications\Service\MessageCipher;
use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Lms\Repository\MoodleAccountRequestRepository;
use Domain\Lms\Repository\MoodleBindingRepository;
use Domain\Lms\Repository\MoodlePersonLinkRepository;
use Domain\Lms\Repository\MoodleProvisioningQueue;
use Domain\Lms\ValueObject\MoodleAccountReceipt;
use Domain\Lms\ValueObject\MoodleBinding;
use Domain\Lms\ValueObject\MoodleExternalId;
use Domain\Lms\ValueObject\MoodlePersonLink;
use Domain\Lms\ValueObject\MoodleProvisionedAccount;
use Domain\Person\Person;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Service\UserAccountAccess;
use Throwable;

/**
 * Inspect original receipts and explicitly recover missing links without retrying external effects.
 * @phpstan-type RecoveryProof array{
 *     kind:string,id:string,actor_id:string,person_id:string,binding_id:string,binding_hash:string,
 *     request_id:string,snapshot:string,version:int,issue_code:string,moodle_user_id:string,
 *     sign_in_status:string,reason:string,expires_at:int
 * }
 */
final readonly class MoodleAccountReconciliationService
{
    public function __construct(
        private MoodleAccountRequestRepository $requests,
        private MoodleProvisioningQueue $queue,
        private MoodleBindingRepository $bindings,
        private MoodlePersonLinkRepository $links,
        private MoodleAccountApprovalReader $approvals,
        private MoodleAccountReceiptReader $remote,
        private MoodleInstanceReader $integration,
        private AdmissionsRepository $people,
        private MoodleAccountProfile $profiles,
        private UserAccountAccess $accounts,
        private StudentAccess $access,
        private AuditTrail $audit,
        private AccessRepository $roles,
        private UnitOfWork $transactions,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** @return array{status:string,moodle_user_id:?string,sign_in_status:?string,profile_changed:bool,message:string} */
    public function inspect(EntityId $personId): array
    {
        [$actor, $person] = $this->authorize($personId);
        [$binding, $row, $snapshot] = $this->context($person);
        $data = $this->approvals->read($row);
        if (
            $data["binding_id"] !== $binding->id->value ||
            !hash_equals(
                $data["binding_hash"],
                hash("sha256", json_encode($binding->describe(), JSON_THROW_ON_ERROR)),
            )
        ) {
            throw new ConcurrentModificationException("The original installation approval changed.");
        }
        $this->audit->record($actor, "moodle.account_receipt_check_requested", $personId);
        $this->unchanged($actor, $personId, $snapshot);
        try {
            $receipt = $this->remote->receipt(
                $binding,
                new EntityId($data["id"]),
                $this->approvals->requestHash($data, $binding),
            );
        } catch (Throwable) {
            $this->unchanged($actor, $personId, $snapshot);
            throw new MoodleConnectionUnavailableException();
        }
        $this->unchanged($actor, $personId, $snapshot);
        if (
            $receipt->account !== null &&
            ($data["sign_in"] === "sso") !== ($receipt->account->signInStatus === "sso_ready")
        ) {
            throw new MoodleConnectionUnavailableException();
        }
        return $this->describe(
            $receipt,
            !hash_equals($data["person_revision"], $this->profiles->revision($person)),
        );
    }

    /** @return array<string,mixed> */
    public function reviewRecovery(EntityId $personId, #[\SensitiveParameter] string $reason): array
    {
        [$actor, $person] = $this->authorizeRecovery($personId);
        $reason = $this->reason($reason);
        [$binding, $row, $snapshot] = $this->context($person);
        $requestId = new EntityId($row["id"]);
        $state = $this->stopped($requestId);
        $observation = $this->inspect($personId);
        $this->unchanged($actor, $personId, $snapshot);
        $this->recoveryActor($actor, $personId);
        if (
            $observation["status"] !== "recorded" ||
            $observation["moodle_user_id"] === null ||
            $observation["sign_in_status"] === null
        ) {
            self::invalid(
                "receipt",
                "Recovery requires a verified eligible account in the original receipt.",
            );
        }
        $external = new MoodleExternalId($observation["moodle_user_id"]);
        $this->available($binding, $external);
        $original = $this->approvals->read($row);
        $data = [
            "kind" => "moodle-account-link-recovery",
            "id" => EntityId::generate()->value,
            "actor_id" => $actor->value,
            "person_id" => $personId->value,
            "binding_id" => $binding->id->value,
            "binding_hash" => hash("sha256", json_encode($binding->describe(), JSON_THROW_ON_ERROR)),
            "request_id" => $requestId->value,
            "snapshot" => $snapshot,
            "version" => $state["version"],
            "issue_code" => $state["issue_code"],
            "moodle_user_id" => $external->value,
            "sign_in_status" => $observation["sign_in_status"],
            "reason" => $reason,
            "expires_at" => $this->clock->now()->getTimestamp() + 1200,
        ];
        return [
            "review_token" => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR)),
            "person_name" => $person->givenName . " " . $person->familyName,
            "original_person_name" => $original["firstname"] . " " . $original["lastname"],
            "original_username" => $original["username"],
            "original_email" => $original["email"],
            "moodle_user_id" => $external->value,
            "sign_in_status" => $data["sign_in_status"],
            "profile_changed" => $observation["profile_changed"],
            "reason" => $reason,
            "expires_at" => $data["expires_at"],
        ];
    }

    /** @return array{state:string,link:array<string,string>} */
    public function confirmRecovery(EntityId $personId, #[\SensitiveParameter] string $token): array
    {
        [$actor, $person] = $this->authorizeRecovery($personId);
        $data = $this->proof($token);
        if ($data["actor_id"] !== $actor->value || $data["person_id"] !== $personId->value) {
            throw new AccessDeniedException(
                "This recovery review belongs to another person or administrator.",
            );
        }
        $hash = hash("sha256", json_encode($data, JSON_THROW_ON_ERROR));
        $completed = $this->completedRecovery($personId, $data, $hash);
        if ($completed !== null) {
            $this->recoveryActor($actor, $personId);
            return $completed;
        }
        $this->unexpired($data["expires_at"]);
        [, , $snapshot] = $this->context($person);
        if (!hash_equals($data["snapshot"], $snapshot)) {
            throw new ConcurrentModificationException(
                "The original request or person changed. Review recovery again.",
            );
        }
        $this->stopped(new EntityId($data["request_id"]));
        $observed = $this->inspect($personId);
        if (
            $observed["status"] !== "recorded" ||
            $observed["moodle_user_id"] !== $data["moodle_user_id"] ||
            $observed["sign_in_status"] !== $data["sign_in_status"]
        ) {
            throw new ConcurrentModificationException("The original receipt changed. Review recovery again.");
        }
        $this->unchanged($actor, $personId, $snapshot);
        $this->recoveryActor($actor, $personId);
        return $this->transactions->run(function () use ($actor, $personId, $data, $hash): array {
            $this->roles->lock();
            $this->people->lockPerson($personId);
            $this->recoveryActor($actor, $personId);
            $completed = $this->completedRecovery($personId, $data, $hash);
            if ($completed !== null) {
                return $completed;
            }
            $this->unexpired($data["expires_at"]);
            [$binding, , $snapshot] = $this->context($this->people->person($personId));
            if (!hash_equals($snapshot, $data["snapshot"])) {
                throw new ConcurrentModificationException("The recovery review is no longer current.");
            }
            $external = new MoodleExternalId($data["moodle_user_id"]);
            $this->available($binding, $external);
            $at = $this->clock->now()->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d\TH:i:s\Z");
            $link = new MoodlePersonLink(
                new EntityId($data["id"]),
                $binding->id,
                $personId,
                $external,
                $actor,
                $at,
            );
            $evidence = $this->cipher->seal(
                json_encode(["review" => $data, "recorded_at" => $at], JSON_THROW_ON_ERROR),
            );
            $this->links->add($link, $evidence);
            $needsEmailReview = $data["sign_in_status"] === "email_review_required";
            $this->requests->recover(
                new EntityId($data["request_id"]),
                $data["version"],
                $data["issue_code"],
                $link->id,
                $hash,
                $needsEmailReview,
                $evidence,
            );
            $this->audit->record($actor, "moodle.account_link_recovered", $personId);
            $this->recoveryActor($actor, $personId);
            return [
                "state" => $needsEmailReview ? "review_required" : "completed",
                "link" => $link->describe(),
            ];
        });
    }

    /** @return array{EntityId,Person} */
    private function authorizeRecovery(EntityId $id): array
    {
        [$actor, $person] = $this->authorize($id);
        if ($this->access->requirePermission(MoodlePersonLinkService::LINK)->value !== $actor->value) {
            throw new AccessDeniedException("The administrator changed. Reload before continuing.");
        }
        return [$actor, $person];
    }

    private function recoveryActor(EntityId $actor, EntityId $id): void
    {
        if ($this->authorizeRecovery($id)[0]->value !== $actor->value) {
            throw new AccessDeniedException("The administrator changed. Reload before continuing.");
        }
    }

    /** @return array{version:int,issue_code:string} */
    private function stopped(EntityId $request): array
    {
        $state = $this->requests->processing($request);
        if (
            $state["state"] !== "review_required" ||
            !in_array($state["issue_code"], ["attempt_limit", "request_changed"], true)
        ) {
            throw new ConcurrentModificationException(
                "Wait until processing has stopped for review before recovering a link.",
            );
        }
        return ["version" => $state["version"], "issue_code" => $state["issue_code"]];
    }

    private function available(MoodleBinding $binding, MoodleExternalId $external): void
    {
        if ($this->links->forExternal($binding->id, $external) !== null) {
            throw new ConcurrentModificationException(
                "The recorded Moodle account already has a person link.",
            );
        }
    }

    /** @param RecoveryProof $data
     * @return array{state:string,link:array<string,string>}|null */
    private function completedRecovery(EntityId $person, #[\SensitiveParameter] array $data, string $hash): ?array
    {
        $binding = $this->bindings->current();
        $request = $binding === null ? null : $this->requests->forPerson($binding->id, $person);
        if (
            $binding === null ||
            $request === null ||
            $request->id->value !== $data["request_id"] ||
            $binding->id->value !== $data["binding_id"] ||
            !hash_equals(
                hash("sha256", json_encode($binding->describe(), JSON_THROW_ON_ERROR)),
                $data["binding_hash"],
            )
        ) {
            throw new ConcurrentModificationException("The original request or installation changed.");
        }
        $recovery = $this->requests->recovery($request->id);
        if ($recovery === null) {
            return null;
        }
        $link = $this->links->forPerson($binding->id, $person);
        if (
            !hash_equals($recovery["review_hash"], $hash) ||
            $link === null ||
            $link->id->value !== $data["id"] ||
            $recovery["link_id"] !== $link->id->value ||
            $link->external->value !== $data["moodle_user_id"] ||
            $link->approvedBy->value !== $data["actor_id"]
        ) {
            throw new ConcurrentModificationException(
                "Another recovery decision already exists. Refresh its status.",
            );
        }
        return [
            "state" => $data["sign_in_status"] === "email_review_required" ? "review_required" : "completed",
            "link" => $link->describe(),
        ];
    }

    private function reason(#[\SensitiveParameter] string $reason): string
    {
        $reason = trim($reason);
        if ($reason === "" || mb_strlen($reason) > 500 || preg_match('/[\x00-\x1F\x7F]/', $reason)) {
            self::invalid(
                "reason",
                "Enter a recovery reason of at most 500 characters, without credentials or academic details.",
            );
        }
        return $reason;
    }

    private function unexpired(int $expires): void
    {
        if ($expires <= $this->clock->now()->getTimestamp()) {
            self::invalid("review_token", "This recovery review expired. Review the original receipt again.");
        }
    }

    /** @return RecoveryProof */
    private function proof(#[\SensitiveParameter] string $token): array
    {
        try {
            if ($token === "" || strlen($token) > 16384) {
                throw new \InvalidArgumentException();
            }
            $data = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($data) ||
                count($data) !== 14 ||
                ($data["kind"] ?? null) !== "moodle-account-link-recovery" ||
                !is_int($data["expires_at"] ?? null) ||
                !is_int($data["version"] ?? null) ||
                $data["version"] < 0
            ) {
                throw new \InvalidArgumentException();
            }
            $fields = [
                    "id",
                    "actor_id",
                    "person_id",
                    "binding_id",
                    "binding_hash",
                    "request_id",
                    "snapshot",
                    "issue_code",
                    "moodle_user_id",
                    "sign_in_status",
                    "reason",
                ];
            foreach ($fields as $field) {
                if (!is_string($data[$field] ?? null)) {
                    throw new \InvalidArgumentException();
                }
            }
            foreach (["id", "actor_id", "person_id", "binding_id", "request_id"] as $field) {
                new EntityId($data[$field]);
            }
            foreach (["binding_hash", "snapshot"] as $field) {
                if (preg_match('/^[a-f0-9]{64}$/D', $data[$field]) !== 1) {
                    throw new \InvalidArgumentException();
                }
            }
            new MoodleProvisionedAccount(
                new MoodleExternalId($data["moodle_user_id"]),
                $data["sign_in_status"],
            );
            if (!in_array($data["issue_code"], ["attempt_limit", "request_changed"], true)) {
                throw new \InvalidArgumentException();
            }
            $this->reason($data["reason"]);
            return $data;
        } catch (Throwable) {
            self::invalid("review_token", "Review the original Moodle account recovery again before saving.");
        }
    }

    private static function invalid(string $field, string $message): never
    {
        throw new ValidationException([["field" => $field, "message" => $message]]);
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

    /** @return array{MoodleBinding,array<string,mixed>,string} */
    private function context(Person $person): array
    {
        if (!$this->integration->enabled()) {
            throw new MoodleConnectionUnavailableException();
        }
        $binding = $this->bindings->current();
        $request = $binding === null ? null : $this->requests->forPerson($binding->id, $person->id);
        if (
            $binding === null ||
            $request === null ||
            $this->links->forPerson($binding->id, $person->id) !== null
        ) {
            throw new ConcurrentModificationException("An unlinked original creation request is required.");
        }
        $state = $this->requests->processing($request->id);
        $pending =
        ($state["state"] === "processing" && $state["issue_code"] === "connection_uncertain") ||
        ($state["state"] === "review_required" &&
        in_array($state["issue_code"], ["attempt_limit", "request_changed"], true));
        if (!$pending) {
            throw new ConcurrentModificationException(
                "This request does not have an uncertain creation outcome.",
            );
        }
        $row = $this->queue->approval($request->id);
        $snapshot = hash(
            "sha256",
            json_encode(
                [
                    $binding->describe(),
                    $request->describe(),
                    $row["review_hash"],
                    $row["encrypted_approval"],
                    $state,
                    $this->profiles->revision($person),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
        return [$binding, $row, $snapshot];
    }

    private function unchanged(EntityId $actor, EntityId $id, string $snapshot): void
    {
        [$current, $person] = $this->authorize($id);
        if ($current->value !== $actor->value) {
            throw new AccessDeniedException("The administrator changed. Reload before continuing.");
        }
        if (!hash_equals($snapshot, $this->context($person)[2])) {
            throw new ConcurrentModificationException(
                "The person or original operation changed. Refresh its status.",
            );
        }
    }

    /** @return array{status:string,moodle_user_id:?string,sign_in_status:?string,profile_changed:bool,message:string} */
    private function describe(MoodleAccountReceipt $receipt, bool $profileChanged): array
    {
        return [
            "status" => $receipt->status,
            "moodle_user_id" => $receipt->account?->user->value,
            "sign_in_status" => $receipt->account?->signInStatus,
            "profile_changed" => $profileChanged,
            "message" => match ($receipt->status) {
                "recorded" => "Moodle retained an account for the original request. " .
                    "This check does not link it or retry creation.",
                "restricted" => "The original receipt cannot supply an eligible account. " .
                    "Ask a Moodle administrator to review it; do not create a replacement.",
                default => "No receipt was observed. The outcome remains unknown; " .
                    "an operation could still be in progress. Do not create a replacement account.",
            },
        ];
    }
}
