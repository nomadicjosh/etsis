<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\Enum\ApplicationStatus;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Admissions\Repository\AdmissionPolicyRepository;
use Domain\Admissions\Repository\AdmissionUpdatesRepository;
use Domain\Admissions\Repository\AdmissionWithdrawalRepository;
use Domain\Admissions\Repository\ApplicationFormRepository;
use Domain\Admissions\Repository\ApplicationRevisionRepository;
use Domain\Admissions\ValueObject\AdmissionDecisionSchema;
use Domain\Admissions\ValueObject\AdmissionOfferSchema;
use Domain\Admissions\ValueObject\ApplicationRevisionSchema;
use Domain\Admissions\ValueObject\SubmissionIntegrity;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

/** One applicant/staff workflow; working requests are CRUD, submitted answers are retained facts. */
final readonly class ApplicationRevisionService
{
    public function __construct(
        private AdmissionsRepository $applications,
        private ApplicationFormRepository $forms,
        private ApplicationRevisionRepository $revisions,
        private AdmissionPolicyRepository $policy,
        private AdmissionWithdrawalRepository $withdrawals,
        private AdmissionUpdatesRepository $updates,
        private AdmissionDecisionLedger $ledger,
        private ApplicationContext $context,
        private StudentAccess $access,
        private AccessRepository $permissions,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
        private \Domain\User\Service\OwnerEmailVerification $verification,
    ) {
    }

    public function current(
        EntityId $id,
        bool $mine,
        ?EntityId $revision = null,
        bool $original = false,
    ): array {
        $route = $this->applications->application($id);
        $actor = $this->actor($route, $mine, false);
        return $this->transactions->run(function () use (
            $route,
            $actor,
            $mine,
            $revision,
            $original,
        ): array {
            $a = $this->lock($route, $actor, $mine, false);
            $state = $this->ledger->revisionState($a);
            $material = $this->material(
                $a,
                $state,
                $mine,
                $revision,
                $original,
            );
            $pending = $this->revisions->pending($a->id);
            $owner = $this->applications->person($a->personId)->ownerUserId
                ?->value;
            // Do not transfer private instructions to a replacement account.
            $visible =
                $pending !== null &&
                (!$mine || $pending["owner_user_id"] === $owner);
            $result = $material + [
                "application_status" => $a->status->value,
                "request" => $visible
                    ? [
                        "id" => $pending["id"],
                        "instructions" => $pending["instructions"],
                    ]
                    : null,
                "request_blocked" => $pending !== null && !$visible,
                "can_revise" =>
                    !$original &&
                    $revision === null &&
                    $this->editable($a, $state, $pending, $owner),
                "request_pending" => $pending !== null,
                "can_request" => $pending === null && $this->available($a, $state, $owner),
                "withdrawal_pending" => $this->withdrawals->pending($a->id) !== null,
                "revision_limit_reached" => count($state['revisions']) >= 20,
                "policy" => $this->policy->answers(),
            ];
            $this->sameActor($a, $actor, $mine, false);
            return $result;
        });
    }

    public function review(
        EntityId $id,
        string $action,
        #[\SensitiveParameter] array $input,
        bool $mine,
    ): array {
        $route = $this->applications->application($id);
        $actor = $this->actor($route, $mine, true);
        return $this->transactions->run(function () use (
            $route,
            $actor,
            $action,
            $input,
            $mine,
        ): array {
            $a = $this->lock($route, $actor, $mine, true);
            $this->policy->lock();
            $state = $this->ledger->revisionState($a);
            $pending = $this->revisions->pending($a->id);
            $intent = $this->intent(
                $a,
                $state,
                $pending,
                $action,
                $input,
                $mine,
            );
            $id = $pending["id"] ?? EntityId::generate()->value;
            $p = [
                "purpose" => "application-answers",
                "application" => $a->id->value,
                "actor" => $actor->value,
                "mine" => $mine,
                "operation" => EntityId::generate()->value,
                "revision_id" => $id,
                "action" => $action,
                "intent" => $intent,
                "context" => $this->fingerprint($a, $state),
                "expires" => $this->clock->now()->getTimestamp() + 1200,
            ];
            $this->sameActor($a, $actor, $mine, true);
            return [
                "action" => $action,
                "instructions" =>
                    $pending["instructions"] ?? ($intent["instructions"] ?? ""),
                "answers" => $intent["answers"] ?? null,
                "reason" => $intent["reason"] ?? "",
                "review_token" => $this->cipher->seal(
                    json_encode(
                        $p,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                    ),
                ),
            ];
        });
    }

    public function commit(
        EntityId $id,
        #[\SensitiveParameter] string $token,
        bool $mine,
    ): array {
        $route = $this->applications->application($id);
        $actor = $this->actor($route, $mine, true);
        try {
            if (strlen($token) > 1000000) {
                throw new \InvalidArgumentException();
            }
            $p = json_decode(
                $this->cipher->open($token),
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
            if (
                !is_array($p) ||
                count($p) !== 10 ||
                ($p["purpose"] ?? null) !== "application-answers" ||
                ($p["application"] ?? null) !== $id->value ||
                ($p["actor"] ?? null) !== $actor->value ||
                ($p["mine"] ?? null) !== $mine ||
                !is_array($p["intent"] ?? null) ||
                !is_string($p["context"] ?? null) ||
                !is_int($p["expires"] ?? null) ||
                !in_array(
                    $p["action"] ?? null,
                    ["request", "cancel", "submit"],
                    true,
                )
            ) {
                throw new \InvalidArgumentException();
            }
            $operation = new EntityId($p["operation"]);
            $revision = new EntityId($p["revision_id"]);
        } catch (\Throwable) {
            throw new ValidationException([
                [
                    "field" => "review_token",
                    "message" => "Review these answers again.",
                ],
            ]);
        }
        return $this->transactions->run(function () use (
            $route,
            $actor,
            $mine,
            $p,
            $operation,
            $revision,
        ): array {
            $a = $this->lock($route, $actor, $mine, true);
            $receipt = $this->receipt($a, $p, $operation, $actor, $revision);
            if ($receipt !== null) {
                $this->sameActor($a, $actor, $mine, true);
                return $receipt;
            }
            if ($mine && $p['action'] === 'submit') {
                $this->verification->requireForApplication($actor, $a->personId);
            }
            $this->policy->lock();
            $state = $this->ledger->revisionState($a);
            $pending = $this->revisions->pending($a->id);
            $intent = $this->intent(
                $a,
                $state,
                $pending,
                $p["action"],
                $p["intent"],
                $mine,
            );
            if (
                $p["expires"] <= $this->clock->now()->getTimestamp() ||
                !hash_equals($p["context"], $this->fingerprint($a, $state)) ||
                ($pending !== null && $pending["id"] !== $revision->value)
            ) {
                throw new ConcurrentModificationException(
                    "This answer review changed or expired. Review it again.",
                );
            }
            $at = $this->clock->now();
            $owner = $this->applications->person($a->personId)->ownerUserId;
            $row = $pending ?? [
                "id" => $revision->value,
                "application_id" => $a->id->value,
                "owner_user_id" => $owner?->value,
                "status" => "requested",
                "version" => -1,
                "instructions" => "",
                "requested_by" => null,
                "requested_at" => null,
                "request_receipt" => [],
                "cancellation" => null,
                "artifact" => null,
            ];
            $row["version"]++;
            $result = [
                "application_id" => $a->id->value,
                "revision_id" => $revision->value,
                "action" => $p["action"],
                "status" =>
                    $p["action"] === "request" ? "requested" : "cancelled",
            ];
            if ($p["action"] === "request") {
                $row["instructions"] = $intent["instructions"];
                $row["requested_by"] = $actor->value;
                $row["requested_at"] = $at->format(DATE_ATOM);
                $row["request_receipt"] = [
                    "operation" => $operation->value,
                    "actor" => $actor->value,
                    "result" => $result,
                ];
            } elseif ($p["action"] === "cancel") {
                $row["status"] = "cancelled";
                $row["cancellation"] = [
                    "operation" => $operation->value,
                    "actor" => $actor->value,
                    "reason" => $intent["reason"],
                    "at" => $at->format(DATE_ATOM),
                    "result" => $result,
                ];
            } else {
                $birth = $state["birth"];
                $artifact = [
                    "id" => $revision->value,
                    "application_id" => $a->id->value,
                    "original_submission_id" =>
                        $birth["source"]["submission"]["id"],
                    "form_id" => $birth["source"]["submission"]["form_id"],
                    "form_hash" => $birth["form_definition_hash"],
                    "owner_user_id" => $owner?->value,
                    "submitted_by" => $actor->value,
                    "submitted_at" => $at->format(DATE_ATOM),
                    "channel" => $mine ? "owner" : "admissions_staff",
                    "policy" => $this->policy->answers(),
                    "answers" => $intent["answers"],
                    "reason" => $intent["reason"],
                    "request" =>
                        $pending === null
                            ? null
                            : [
                                "id" => $pending["id"],
                                "instructions" => $pending["instructions"],
                                "by" => $pending["requested_by"],
                                "at" => $pending["requested_at"],
                            ],
                ];
                $row["status"] = "submitted";
                $row["artifact"] = $artifact;
                $reference = array_intersect_key(
                    $artifact,
                    array_flip(ApplicationRevisionSchema::REFERENCE),
                );
                $reference[
                    "fingerprint"
                ] = ApplicationRevisionSchema::fingerprint($artifact);
            }
            $this->revisions->save(
                $row,
                $this->revisions->revision($a->id),
                $pending === null,
            );
            if ($p["action"] === "submit") {
                $after = $a->resubmitAnswers();
                $this->applications->saveResubmission($a, $after);
                $result = $this->ledger->resubmitted(
                    $a,
                    $after,
                    $reference,
                    $operation,
                    $actor,
                    $intent["reason"],
                    $at,
                );
            }
            if ($owner !== null) {
                $this->updates->record(
                    $a->id,
                    $owner,
                    $at
                        ->setTimezone(new \DateTimeZone("UTC"))
                        ->format("Y-m-d\TH:i:s.u\Z"),
                );
            }
            $this->audit->record(
                $actor,
                "admissions.answers_" . $p["action"],
                $a->id,
            );
            $this->sameActor($a, $actor, $mine, true);
            if ($mine && $p['action'] === 'submit') {
                $this->verification->requireForApplication($actor, $a->personId);
            }
            return $result;
        });
    }

    private function receipt(
        AdmissionApplication $a,
        #[\SensitiveParameter] array $p,
        EntityId $operation,
        EntityId $actor,
        EntityId $revision,
    ): ?array {
        if ($p["action"] === "submit") {
            return $this->ledger->receipt($a, $operation, $actor);
        }
        $r = $this->revisions->find($revision);
        if ($r === null || $r["application_id"] !== $a->id->value) {
            return null;
        }
        $receipt =
        $p["action"] === "request"
        ? $r["request_receipt"]
        : $r["cancellation"];
        return ($receipt["operation"] ?? null) === $operation->value &&
        ($receipt["actor"] ?? null) === $actor->value
        ? $receipt["result"]
        : null;
    }

    private function intent(
        AdmissionApplication $a,
        array $state,
        ?array $pending,
        string $action,
        #[\SensitiveParameter] array $input,
        bool $mine,
    ): array {
        if (
            !$mine &&
            in_array($action, ["request", "cancel", "submit"], true)
        ) {
            $this->access->requirePermission("sis:admissions:amend");
        } elseif ($action !== "submit") {
            throw new AccessDeniedException(
                "Admissions controls correction requests.",
            );
        }
        if ($action === "cancel") {
            if ($pending === null) {
                $this->invalid("There is no open correction request.");
            }
            return ["reason" => $this->text($input["reason"] ?? null)];
        }
        $owner = $this->applications->person($a->personId)->ownerUserId?->value;
        if ($action === "request") {
            if ($pending !== null || !$this->available($a, $state, $owner)) {
                $this->invalid(
                    "Choose an application awaiting a decision with no open correction or withdrawal request.",
                );
            }
            return [
                "instructions" => $this->text($input["instructions"] ?? null),
            ];
        }
        if (
            $action !== "submit" ||
            !$this->editable($a, $state, $pending, $owner)
        ) {
            $this->invalid(
                "This application is not open for revised answers. Contact admissions.",
            );
        }
        if (!is_array($input["answers"] ?? null)) {
            $this->invalid("Enter the revised application answers.");
        }
        $material = $this->material($a, $state, $mine);
        $canonical = $material["form"]->validate($input["answers"]);
        if ($canonical === $material["answers"]) {
            $this->invalid("Change the requested answers before resubmitting.");
        }
        return [
            "answers" => $canonical,
            "reason" => $this->text($input["reason"] ?? null),
        ];
    }
    private function available(
        AdmissionApplication $a,
        array $state,
        ?string $owner,
    ): bool {
        return $a->status === ApplicationStatus::Submitted &&
        $owner !== null &&
        ($state["birth"]["source"]["submission"] ?? null) !== null &&
        count($state["revisions"]) < 20 &&
        $this->withdrawals->pending($a->id) === null;
    }
    private function editable(
        AdmissionApplication $a,
        array $state,
        ?array $pending,
        ?string $owner,
    ): bool {
        if (!$this->available($a, $state, $owner)) {
            return false;
        }
        if ($pending !== null) {
            return $pending["owner_user_id"] === $owner;
        }
        return $this->policy->answers()["mode"] === "before_decision";
    }

    private function material(
        AdmissionApplication $a,
        array $state,
        bool $mine,
        ?EntityId $revision = null,
        bool $original = false,
    ): array {
        $birth = $state["birth"];
        if ($birth === null) {
            $row = $this->forms->submissionForApplication($a->id);
            if ($row === null || $revision !== null) {
                $this->invalid(
                    "No retained form is available for this application.",
                );
            }
            $this->context->verifyRetained(
                $a,
                $this->context->source($a),
                new EntityId(
                    $state["initial_entry_term"] ?? $a->entryTermId->value,
                ),
            );
            try {
                $answers = json_decode(
                    $this->cipher->open($row["encrypted_answers"]),
                    true,
                    32,
                    JSON_THROW_ON_ERROR,
                );
            } catch (\Throwable) {
                throw new CorruptHistoryException();
            }
            return [
                "form" => $this->forms->get(new EntityId($row["form_id"])),
                "answers" => $answers,
                "revision_id" => null,
                "submitted_term_code" => null,
                "versions" => [],
            ];
        }
        if (($birth["source"]["submission"] ?? null) === null) {
            $this->invalid(
                "This application has no verifiable original form. Contact admissions.",
            );
        }
        $form = $this->forms->get(
            new EntityId($birth["source"]["submission"]["form_id"]),
        );
        $refs = $state["revisions"];
        $selected = $original
        ? null
        : ($revision === null
                ? $refs[array_key_last($refs)] ?? null
                : null);
        foreach ($refs as $ref) {
            if ($revision?->value === $ref["id"]) {
                $selected = $ref;
            }
        }
        if ($revision !== null && $selected === null) {
            throw new AccessDeniedException("Answer revision unavailable.");
        }
        if ($selected === null) {
            $row =
            $this->forms->submission(
                new EntityId($birth["source"]["submission"]["id"]),
            ) ?? throw new CorruptHistoryException();
            try {
                $answers = json_decode(
                    $this->cipher->open($row["encrypted_answers"]),
                    true,
                    32,
                    JSON_THROW_ON_ERROR,
                );
            } catch (\Throwable) {
                throw new CorruptHistoryException();
            }
        } else {
            if (
                $mine &&
                $selected["owner_user_id"] !==
                    $this->applications->person($a->personId)->ownerUserId
                        ?->value
            ) {
                throw new AccessDeniedException(
                    "This answer revision belongs to an earlier account. Contact admissions.",
                );
            }
            $answers = $this->context->verifyRevision($a, $selected)["answers"];
        }
        return [
            "form" => $form,
            "answers" => $answers,
            "revision_id" => $selected["id"] ?? null,
            "submitted_term_code" => $birth["source"]["entry_term_code"],
            "versions" => array_values(
                array_map(
                    static fn(array $ref): array => [
                        "id" => $ref["id"],
                        "submitted_at" => $ref["submitted_at"],
                    ],
                    array_filter(
                        $refs,
                        fn(array $ref): bool => !$mine ||
                            $ref["owner_user_id"] ===
                                $this->applications->person($a->personId)
                                    ->ownerUserId?->value,
                    ),
                ),
            ),
        ];
    }
    private function fingerprint(AdmissionApplication $a, array $state): string
    {
        $p = $this->applications->person($a->personId);
        return ApplicationRevisionSchema::fingerprint([
            AdmissionDecisionSchema::facts($a),
            $state["head"],
            $this->revisions->revision($a->id),
            $this->withdrawals->revision($a->id),
            $this->policy->answers(),
            $p->identityVersion,
            $p->ownerUserId?->value,
        ]);
    }
    private function actor(
        AdmissionApplication $a,
        bool $mine,
        bool $write,
    ): EntityId {
        if ($mine) {
            $actor = $this->access->authenticatedUserId();
            if (
                $this->applications->person($a->personId)->ownerUserId
                    ?->value !== $actor->value
            ) {
                throw new AccessDeniedException(
                    "This application belongs to another account.",
                );
            }
            return $actor;
        }
        $actor = $this->access->requirePermission("sis:admissions:manage");
        if (
            $write &&
            $this->access->requirePermission("sis:admissions:amend")->value !==
                $actor->value
        ) {
            throw new AccessDeniedException(
                "The application revision actor changed.",
            );
        }
        return $actor;
    }
    private function sameActor(
        AdmissionApplication $a,
        EntityId $actor,
        bool $mine,
        bool $write,
    ): void {
        if ($this->actor($a, $mine, $write)->value !== $actor->value) {
            throw new AccessDeniedException(
                "The application revision actor changed.",
            );
        }
    }
    private function lock(
        AdmissionApplication $route,
        EntityId $actor,
        bool $mine,
        bool $write,
    ): AdmissionApplication {
        $this->permissions->lock();
        $this->applications->lockPerson($route->personId);
        $this->applications->lockApplication($route->id);
        $a = $this->applications->application($route->id);
        $this->sameActor($a, $actor, $mine, $write);
        if (
            $a->personId->value !== $route->personId->value ||
            $a->programId->value !== $route->programId->value
        ) {
            throw new ConcurrentModificationException(
                "The application changed. Reload it.",
            );
        }
        $this->ledger->verify($a);
        return $a;
    }
    private function text(mixed $value): string
    {
        if (!AdmissionOfferSchema::text($value, 2000)) {
            $this->invalid(
                "Enter instructions or a reason up to 2,000 characters.",
            );
        }
        return trim($value);
    }
    private function invalid(string $message): never
    {
        throw new ValidationException([
            ["field" => "answers", "message" => $message],
        ]);
    }
}
