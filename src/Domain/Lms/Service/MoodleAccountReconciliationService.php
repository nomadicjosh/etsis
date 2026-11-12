<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Lms\Repository\MoodleAccountRequestRepository;
use Domain\Lms\Repository\MoodleBindingRepository;
use Domain\Lms\Repository\MoodlePersonLinkRepository;
use Domain\Lms\Repository\MoodleProvisioningQueue;
use Domain\Lms\ValueObject\MoodleAccountReceipt;
use Domain\Lms\ValueObject\MoodleBinding;
use Domain\Person\Person;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Service\UserAccountAccess;
use Throwable;

/** Inspect the original remote receipt without retrying work or changing a local decision. */
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
                "recorded"
                    => "Moodle retained an account for the original request. " .
                        "This check does not link it or retry creation.",
                "restricted"
                    => "The original receipt cannot supply an eligible account. " .
                        "Ask a Moodle administrator to review it; do not create a replacement.",
                default
                    => "No receipt was observed. The outcome remains unknown; " .
                        "an operation could still be in progress. Do not create a replacement account.",
            },
        ];
    }
}
