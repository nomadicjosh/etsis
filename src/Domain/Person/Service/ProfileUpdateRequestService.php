<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Repository\PersonProfileRepository;
use Domain\Person\Repository\ProfileUpdateRequestRepository;
use Domain\Person\ProfileUpdateRequest;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use DateTimeZone;

/** Working follow-up only. Existing profile editors remain the authority for actual data changes. */
final readonly class ProfileUpdateRequestService
{
    public function __construct(
        private ProfileUpdateRequestRepository $requests,
        private PersonProfileAccess $authorization,
        private PersonProfileRepository $people,
        private ProfileLedger $history,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }

    public function listing(
        ?EntityId $id,
        bool $staff,
        bool $finished = false,
        ?EntityId $after = null,
    ): array {
        $actor = $this->authorization->actor();
        $person = $this->authorization->person($actor, $id, $staff);
        return $this->transactions->run(function () use (
            $actor,
            $person,
            $staff,
            $finished,
            $after,
        ): array {
            $this->history->verify($person);
            $rows = $this->requests->page($person->id, $staff ? null : $actor, $finished, $after);
            $items = array_slice($rows, 0, 10);
            $this->audit->record($actor, "person.profile_requests_read", $person->id);
            $this->authorization->person($actor, $person->id, $staff);
            return [
                "items" => array_map(
                    static fn(ProfileUpdateRequest $row): array => $row->view(
                        $row->facts()["owner_user_id"] !== $person->ownerUserId?->value,
                    ),
                    $items,
                ),
                "next_after" => count($rows) > 10 ? end($items)->facts()["id"] : null,
                "new_id" => EntityId::generate()->value,
                "areas" => ProfileUpdateRequest::AREAS,
            ];
        });
    }

    public function request(
        EntityId $personId,
        EntityId $id,
        string $area,
        #[\SensitiveParameter] string $message,
    ): array {
        $actor = $this->authorization->actor();
        $this->authorization->person($actor, $personId, true);
        return $this->transactions->run(function () use (
            $actor,
            $personId,
            $id,
            $area,
            $message,
        ): array {
            $this->authorization->lockStaffRoles();
            $this->people->lock($personId);
            $person = $this->authorization->person($actor, $personId, true);
            $this->history->verify($person);
            $candidate = ProfileUpdateRequest::create(
                $id,
                $personId,
                $person->ownerUserId ?? throw new AccessDeniedException(),
                $actor,
                $area,
                $message,
                $this->now(),
            );
            $old = $this->requests->find($personId, $id);
            if ($old !== null) {
                if (
                    $old->facts()["owner_user_id"] !== $person->ownerUserId->value ||
                    $old->facts()["area"] !== $area
                ) {
                    throw new ConcurrentModificationException(
                        "This request reference was already used.",
                    );
                }
                $result = $old->retry(
                    "request",
                    $actor,
                    $candidate->intent("request", 0, $message),
                );
            } else {
                if ($this->requests->pending($personId, $area)) {
                    throw new ConcurrentModificationException(
                        "An active request already exists for this area. Review it first.",
                    );
                }
                $this->requests->save($candidate, null);
                $this->audit->record($actor, "person.profile_update_requested", $id);
                $result = $candidate->retry(
                    "request",
                    $actor,
                    $candidate->intent("request", 0, $message),
                );
            }
            $this->authorization->person($actor, $personId, true);
            return $result;
        });
    }

    public function act(
        ?EntityId $personId,
        EntityId $id,
        bool $staff,
        string $action,
        int $version,
        #[\SensitiveParameter] string $message,
    ): array {
        $actor = $this->authorization->actor();
        $person = $this->authorization->person($actor, $personId, $staff);
        if (
            ($staff && !in_array($action, ["close", "cancel"], true)) ||
            (!$staff && $action !== "respond")
        ) {
            throw new AccessDeniedException("Choose an action available in this profile workflow.");
        }
        return $this->transactions->run(function () use (
            $actor,
            $person,
            $id,
            $staff,
            $action,
            $version,
            $message,
        ): array {
            if ($staff) {
                $this->authorization->lockStaffRoles();
            }
            $this->people->lock($person->id);
            $current = $this->authorization->person($actor, $person->id, $staff);
            $this->history->verify($current);
            $request =
                $this->requests->find($person->id, $id) ??
                throw new RecordNotFoundException("Request not found.");
            if (
                $request->facts()["owner_user_id"] !== $current->ownerUserId?->value &&
                (!$staff || $action !== "cancel")
            ) {
                throw new AccessDeniedException(
                    "This request belongs to an earlier account link. Staff must cancel it.",
                );
            }
            $intent = $request->intent($action, $version, $message);
            if (($result = $request->retry($action, $actor, $intent)) === null) {
                $result = $request->change($action, $actor, $version, $message, $this->now());
                $this->requests->save($request, $version);
                $this->audit->record($actor, "person.profile_request_" . $action, $id);
            }
            $this->authorization->person($actor, $person->id, $staff);
            return $result;
        });
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d\TH:i:s\Z");
    }
}
