<?php

declare(strict_types=1);

namespace Domain\Finance\Service;

use DateTimeZone;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Finance\FinancialReview;
use Domain\Finance\Repository\FinancialReviewRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\HiatusRepository;
use Domain\Student\StudentHiatus;

final readonly class FinancialReviewService
{
    public function __construct(
        private FinancialReviewRepository $reviews,
        private HiatusRepository $hiatuses,
        private AdmissionsRepository $admissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function request(EntityId $student, EntityId $hiatus): array
    {
        $actor = $this->access->requirePermission("sis:registration:manage");
        return $this->transactions->run(function () use ($student, $hiatus, $actor): array {
            $this->hiatuses->lockStudent($student);
            $this->hiatuses->lock($hiatus);
            $source = $this->source($student, $hiatus);
            $existing = $this->reviews->forHiatus($hiatus);
            if ($existing !== null) {
                return $this->metadata($existing);
            }
            if ($source->voided) {
                throw new ValidationException([
                    ["field" => "hiatus", "message" => "A voided academic case cannot start a new hand-off."],
                ]);
            }
            $review = new FinancialReview(EntityId::generate(), $student, $hiatus, $actor, $this->now());
            $this->reviews->add($review);
            $this->audit->record($actor, "finance.review_requested", $review->id);
            return $this->metadata($review);
        });
    }

    /** Registrar visibility deliberately excludes finance notes, account data and assignee identity.
     * @return array<string, mixed>|null
     */
    public function progress(EntityId $student, EntityId $hiatus): ?array
    {
        $this->access->requirePermission("sis:registration:manage");
        $this->source($student, $hiatus);
        $review = $this->reviews->forHiatus($hiatus);
        return $review === null ? null : $this->metadata($review);
    }

    /** @return array<string, mixed> */
    public function page(?string $status, ?EntityId $after = null): array
    {
        $this->access->requirePermission("sis:finance:post");
        if ($status !== null && !in_array($status, ["open", "in_review", "completed", "cancelled"], true)) {
            throw new ValidationException([["field" => "status", "message" => "Choose a valid review status."]]);
        }
        $rows = $this->reviews->page($status, $after);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        return [
            "items" => array_map(
                static fn(array $r): array => [
                    "id" => $r["id"],
                    "status" => $r["status"],
                    "given_name" => $r["given_name"],
                    "family_name" => $r["family_name"],
                    "institutional_id" => $r["institutional_id"],
                    "created_at" => $r["created_at"],
                ],
                $rows,
            ),
            "next_after" => $more ? $rows[49]["id"] : null,
        ];
    }

    /** @return array<string, mixed> */
    public function view(EntityId $id): array
    {
        $actor = $this->access->requirePermission("sis:finance:post");
        $review = $this->reviews->get($id);
        $student = $this->admissions->student($review->student);
        $person = $this->admissions->person($student->personId);
        $hiatus = $this->source($student->id, $review->hiatus);
        $this->audit->record($actor, "finance.review_read", $id);
        return $this->metadata($review) + [
            "student" => [
                "id" => $student->id->value,
                "given_name" => $person->givenName,
                "family_name" => $person->familyName,
                "institutional_id" => $person->institutionalId->value,
            ],
            "version" => $review->version,
            "source" => [
                "id" => $hiatus->id->value,
                "starts_on" => $hiatus->dates->start->format("Y-m-d"),
                "ends_on" => $hiatus->dates->end?->format("Y-m-d"),
                "voided" => $hiatus->voided,
            ],
            "assigned_to_me" => $review->assignedTo?->value === $actor->value,
        ];
    }

    /** @return array<string, mixed> */
    public function change(
        EntityId $id,
        EntityId $operation,
        int $version,
        string $action,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->access->requirePermission("sis:finance:post");
        $note = trim($reason);
        if (
            !mb_check_encoding($note, "UTF-8") ||
            $note === "" ||
            mb_strlen($note) > 10000 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note)
        ) {
            throw new ValidationException([
                ["field" => "note", "message" => "Enter a finance note of up to 10,000 characters."],
            ]);
        }
        $request = ["version" => $version, "action" => $action, "note" => $note];
        return $this->transactions->run(function () use ($id, $operation, $version, $action, $actor, $request): array {
            $this->reviews->lock($id);
            $current = $this->reviews->get($id);
            $previous = $this->reviews->action($operation);
            if ($previous !== null) {
                $data = $this->payload($previous);
                if (
                    $previous["review_id"] !== $id->value ||
                    $previous["actor_id"] !== $actor->value ||
                    $data["request"] !== $request
                ) {
                    throw new ConcurrentModificationException("This operation belongs to another finance change.");
                }
                return $data["result"];
            }
            if ($current->version !== $version) {
                throw new ConcurrentModificationException("The finance review changed. Reload it before saving.");
            }
            $next = $current->change($action, $actor);
            $this->reviews->save($next, $version);
            $result = $this->metadata($next);
            $this->reviews->record(
                $operation,
                $id,
                $actor,
                $this->cipher->seal(
                    json_encode(
                        [
                            "request" => $request,
                            "previous_status" => $current->status,
                            "previous_assignee" => $current->assignedTo?->value,
                            "result" => $result,
                        ],
                        JSON_THROW_ON_ERROR,
                    ),
                ),
                $this->now(),
            );
            $this->audit->record($actor, "finance.review_" . $action, $id);
            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function history(EntityId $id, ?EntityId $after = null): array
    {
        $actor = $this->access->requirePermission("sis:finance:post");
        $this->reviews->get($id);
        $rows = $this->reviews->history($id, $after);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        $items = [];
        foreach ($rows as $row) {
            $data = $this->payload($row);
            $items[] = [
                "id" => $row["id"],
                "created_at" => $row["created_at"],
                "action" => $data["request"]["action"],
                "note" => $data["request"]["note"],
                "actor_name" => trim($row["given_name"] . " " . $row["family_name"]),
                "previous_status" => $data["previous_status"],
                "status" => $data["result"]["status"],
            ];
        }
        $this->audit->record($actor, "finance.review_history_read", $id);
        return ["items" => $items, "next_after" => $more ? $rows[49]["id"] : null];
    }

    private function source(EntityId $student, EntityId $hiatus): StudentHiatus
    {
        $source = $this->hiatuses->find($hiatus);
        if ($source === null || $source->studentId->value !== $student->value) {
            throw new RecordNotFoundException("Academic case not found.");
        }
        return $source;
    }

    /** @return array<string, mixed> */
    private function metadata(FinancialReview $review): array
    {
        return [
            "id" => $review->id->value,
            "status" => $review->status,
            "created_at" => $review->createdAt,
            "office" => "Finance",
            "assigned" => $review->assignedTo !== null,
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function payload(#[\SensitiveParameter] array $row): array
    {
        return json_decode($this->cipher->open($row["encrypted_content"]), true, 16, JSON_THROW_ON_ERROR);
    }

    private function now(): string
    {
        return $this->clock
            ->now()
            ->setTimezone(new DateTimeZone("UTC"))
            ->format(DATE_ATOM);
    }
}
