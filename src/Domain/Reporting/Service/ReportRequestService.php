<?php

declare(strict_types=1);

namespace Domain\Reporting\Service;

use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Reporting\ReportRequest;
use Domain\Reporting\Repository\ReportRepository;
use Domain\Reporting\Repository\RelationalReportRepository;
use Domain\Reporting\Repository\ReportRequestRepository;
use Domain\Reporting\ValueObject\ReportRequestBrief;
use Domain\Reporting\ValueObject\ReportRequestChange;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

final readonly class ReportRequestService
{
    public function __construct(
        private ReportRequestRepository $requests,
        private ReportRepository $reports,
        private RelationalReportRepository $queries,
        private ReportService $sharing,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    private function manager(): bool
    {
        try {
            $this->access->requirePermission("sis:reporting:manage");
            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }
    private function member(ReportRequest $request): EntityId
    {
        $actor = $this->access->authenticatedUserId();
        if (!$this->manager()) {
            $this->access->requirePermission("sis:reporting:run");
            if ($request->requestedBy->value !== $actor->value) {
                throw new AccessDeniedException("Access denied.");
            }
        }
        return $actor;
    }
    private function request(EntityId $id): ReportRequest
    {
        $request = $this->requests->find($id) ?? throw new RecordNotFoundException("Report request not found.");
        $this->member($request);
        return $request;
    }
    private function now(): string
    {
        return $this->clock
            ->now()
            ->setTimezone(new DateTimeZone("UTC"))
            ->format(DATE_ATOM);
    }
    /** @param array<string, mixed> $values */
    private function hash(array $values): string
    {
        return hash("sha256", json_encode($values, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function create(EntityId $id, ReportRequestBrief $brief): array
    {
        $actor = $this->access->authenticatedUserId();
        if (!$this->manager()) {
            $this->access->requirePermission("sis:reporting:run");
        }
        $hash = $this->hash(["id" => $id->value, "brief" => $brief->values()]);
        if (($old = $this->requests->operation($id)) !== null) {
            $this->request($id);
            return $this->replay($old, $id, $actor, $hash);
        }
        return $this->transactions->run(function () use ($id, $brief, $actor, $hash): array {
            $this->requests->lockRequester($actor);
            if (($old = $this->requests->operation($id)) !== null) {
                $this->request($id);
                return $this->replay($old, $id, $actor, $hash);
            }
            if ($brief->dueOn !== null && $brief->dueOn < $this->clock->now()->format("Y-m-d")) {
                throw new ValidationException([
                    ["field" => "due_on", "message" => "Choose today or a later requested date."],
                ]);
            }
            $request = new ReportRequest($id, $actor);
            $this->requests->add(
                $request,
                $this->cipher->seal(json_encode($brief->values(), JSON_THROW_ON_ERROR)),
                $brief->dueOn,
                $this->now(),
            );
            return $this->record($id, $request, $actor, $hash, "created", "", null);
        });
    }

    /** @return array<string, mixed> */
    public function page(bool $queue, ?string $status = "open", ?EntityId $after = null): array
    {
        $actor = $this->access->authenticatedUserId();
        $manager = $this->manager();
        if ($queue) {
            $this->access->requirePermission("sis:reporting:manage");
        } elseif (!$manager) {
            $this->access->requirePermission("sis:reporting:run");
        }
        if ($status !== null && !in_array($status, ReportRequest::STATUSES, true)) {
            throw new ValidationException([["field" => "status", "message" => "Choose a request status."]]);
        }
        if ($after !== null) {
            $cursor = $this->request($after);
            if (!$queue && $cursor->requestedBy->value !== $actor->value) {
                throw new ValidationException([
                    ["field" => "after", "message" => "Use a cursor from this request page."],
                ]);
            }
        }
        $rows = $this->requests->page($queue ? null : $actor, $status, $after, 51);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        $items = array_map(function (array $row) use ($actor): array {
            $brief = json_decode($this->cipher->open($row["encrypted_content"]), true, 16, JSON_THROW_ON_ERROR);
            return $this->metadata($row, $actor) + ["title" => $brief["title"]];
        }, $rows);
        return ["items" => $items, "next_after" => $more ? $rows[49]["id"] : null, "can_manage" => $manager];
    }
    /** @return array<string, mixed> */
    public function view(EntityId $id): array
    {
        $this->request($id);
        return $this->transactions->run(function () use ($id): array {
            $this->requests->lock($id);
            return $this->detail($id);
        });
    }
    /** @return array<string, mixed> */
    private function detail(EntityId $id): array
    {
        $request = $this->request($id);
        $actor = $this->member($request);
        $row = $this->requests->content($id);
        $brief = json_decode($this->cipher->open($row["encrypted_content"]), true, 16, JSON_THROW_ON_ERROR);
        $assigned = $request->assignedTo?->value === $actor->value && $this->manager();
        $owner = $request->requestedBy->value === $actor->value;
        $active = !in_array($request->status, ["delivered", "cancelled"], true);
        return $this->metadata($row, $actor) +
        $brief + [
                "actions" => [
                    "claim" => $this->manager() && $active && !$assigned,
                    "clarify" => $assigned && $request->status === "in_progress",
                    "reply" => $owner && $request->status === "waiting",
                    "deliver" => $assigned && $request->status === "in_progress",
                    "cancel" => $active && ($owner || $assigned),
                    "reopen" => !$active && ($owner || $assigned),
                    "note" => true,
                ],
            ];
    }
    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function metadata(array $row, EntityId $actor): array
    {
        return [
            "id" => $row["id"],
            "status" => $row["status"],
            "version" => (int) $row["version"],
            "due_on" => $row["due_on"],
            "report_id" => $row["report_id"],
            "created_at" => $row["created_at"],
            "updated_at" => $row["updated_at"],
            "requester_name" =>
                trim(($row["requester_given"] ?? "") . " " . ($row["requester_family"] ?? "")) ?: "Campus colleague",
            "assignee_name" =>
                $row["assigned_to"] === null
                    ? null
                    : (trim(($row["assignee_given"] ?? "") . " " . ($row["assignee_family"] ?? "")) ?:
                    "Report specialist"),
            "owned" => $row["requested_by"] === $actor->value,
            "assigned_to_me" => $row["assigned_to"] === $actor->value,
            "assigned" => $row["assigned_to"] !== null,
        ];
    }
    /** @return array<string, mixed> */
    public function deliveryReview(EntityId $id, EntityId $report): array
    {
        $this->access->requirePermission("sis:reporting:manage");
        return $this->transactions->run(function () use ($id, $report): array {
            $this->requests->lock($id);
            // Both parent locks precede consistent reads, including on repeatable-read engines.
            $this->reports->lock($report);
            $request = $this->request($id);
            $actor = $this->member($request);
            $request->change("deliver", $actor, true, $report);
            $context = $this->deliveryContext($request, $report);
            return [
                "request_id" => $id->value,
                "version" => $request->version,
                "report" => $context["report"],
                "requester_name" => $this->metadata($this->requests->content($id), $actor)["requester_name"],
                "operation_id" => EntityId::generate()->value,
                "delivery_revision" => $this->hash($context),
            ];
        });
    }
    /** @return array<string, mixed> */
    private function deliveryContext(ReportRequest $request, EntityId $id): array
    {
        // Callers acquire the request and report locks before any consistent reads.
        $report = $this->reports->get($id);
        $this->access->requireOwnerOrPermission($report->ownerUserId, "sis:reporting:admin");
        $query = $this->queries->get($id);
        return [
            "request_id" => $request->id->value,
            "version" => $request->version,
            "requester" => $request->requestedBy->value,
            "report" => [
                "id" => $id->value,
                "name" => $report->name,
                "definition_version" => $query === null ? null : (int) $query["version"],
            ],
            "report_owner" => $report->ownerUserId->value,
            "definition_hash" => $query === null ? null : hash("sha256", $query["encrypted_content"]),
        ];
    }
    /** @return array<string, mixed> */
    public function change(EntityId $id, EntityId $operation, int $version, ReportRequestChange $change): array
    {
        $request = $this->request($id);
        $actor = $this->member($request);
        $this->actionPermission($change->action);
        $hash = $this->hash(["request_id" => $id->value, "version" => $version, "change" => $change->values()]);
        if (($old = $this->requests->operation($operation)) !== null) {
            return $this->replay($old, $id, $actor, $hash);
        }
        return $this->transactions->run(function () use ($id, $operation, $version, $change, $actor, $hash): array {
            $this->requests->lock($id);
            if ($change->report !== null) {
                $this->reports->lock($change->report);
            }
            $request = $this->request($id);
            if (($old = $this->requests->operation($operation)) !== null) {
                return $this->replay($old, $id, $actor, $hash);
            }
            if ($request->version !== $version) {
                throw new ConcurrentModificationException("Review the current request before changing it.");
            }
            if ($change->action === "claim" && $request->assignedTo !== null && $change->note === "") {
                throw new ValidationException([
                    ["field" => "note", "message" => "Explain taking over another specialist’s request."],
                ]);
            }
            $next = $request->change($change->action, $actor, $this->manager(), $change->report);
            $delivery = null;
            if ($change->action === "deliver" && $change->report !== null) {
                $context = $this->deliveryContext($request, $change->report);
                if (!hash_equals($this->hash($context), $change->deliveryRevision ?? "")) {
                    throw new ConcurrentModificationException("The request or report changed. Review delivery again.");
                }
                $this->sharing->share($change->report, $request->requestedBy);
                $delivery = $context["report"];
            }
            $this->requests->save($next, $version, $this->now());
            return $this->record($operation, $next, $actor, $hash, $change->action, $change->note, $delivery);
        });
    }
    private function actionPermission(string $action): void
    {
        if (in_array($action, ["claim", "clarify", "deliver"], true)) {
            $this->access->requirePermission("sis:reporting:manage");
        }
    }
    /** @param array<string, mixed>|null $delivery
     * @return array<string, mixed>
     */
    private function record(
        EntityId $operation,
        ReportRequest $request,
        EntityId $actor,
        string $hash,
        string $action,
        string $note,
        ?array $delivery,
    ): array {
        $result = [
            "request_id" => $request->id->value,
            "operation_id" => $operation->value,
            "status" => $request->status,
            "version" => $request->version,
            "report_id" => $request->reportId?->value,
            "delivered_report" => $delivery,
        ];
        $payload = ["action" => $action, "note" => $note, "result" => $result];
        $this->requests->record(
            $operation,
            $request->id,
            $actor,
            $request->version,
            $hash,
            $this->cipher->seal(json_encode($payload, JSON_THROW_ON_ERROR)),
            $this->now(),
        );
        $this->audit->record($actor, "report_request." . $action, $request->id);
        return $result;
    }
    /** @return array<string, mixed> */
    public function receipt(EntityId $id, EntityId $operation): array
    {
        $request = $this->request($id);
        $actor = $this->member($request);
        $row =
        $this->requests->operation($operation) ?? throw new RecordNotFoundException("Request receipt not found.");
        return $this->replay($row, $id, $actor, null);
    }
    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function replay(array $row, EntityId $id, EntityId $actor, ?string $hash): array
    {
        if ($row["request_id"] !== $id->value || $row["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("Access denied.");
        }
        if ($hash !== null && !hash_equals($row["request_hash"], $hash)) {
            throw new ConcurrentModificationException("This reference belongs to another action.");
        }
        $payload = json_decode($this->cipher->open($row["encrypted_content"]), true, 16, JSON_THROW_ON_ERROR);
        $this->actionPermission($payload["action"]);
        return $payload["result"];
    }
    /** @return array<string, mixed> */
    public function history(EntityId $id, int $afterVersion = -1): array
    {
        $request = $this->request($id);
        $actor = $this->member($request);
        if ($afterVersion >= 0) {
            $cursorExists = $this->requests->history($id, $afterVersion - 1, 1);
            if ($cursorExists === [] || (int) $cursorExists[0]["version"] !== $afterVersion) {
                throw new ValidationException([
                    ["field" => "after_version", "message" => "Use a version from this case history."],
                ]);
            }
        }
        $rows = $this->requests->history($id, $afterVersion, 51);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        $items = array_map(function (array $row) use ($actor, $request): array {
            $payload = json_decode($this->cipher->open($row["encrypted_content"]), true, 16, JSON_THROW_ON_ERROR);
            return [
                "id" => $row["id"],
                "version" => (int) $row["version"],
                "created_at" => $row["created_at"],
                "actor" =>
                    $row["actor_id"] === $actor->value
                        ? "You"
                        : ($row["actor_id"] === $request->requestedBy->value
                            ? "Requester"
                            : "Report team"),
                "action" => $payload["action"],
                "note" => $payload["note"],
                "result" => $payload["result"],
            ];
        }, $rows);
        return ["items" => $items, "next_after_version" => $more ? (int) $rows[49]["version"] : null];
    }
}
