<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use DateTimeZone;
use Domain\AcademicRecord\Service\InstitutionalCreditLedger;
use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Gradebook\ValueObject\ExternalCourseGrade;
use Domain\Gradebook\ValueObject\ExternalGradeEvidence;
use Domain\Lms\Repository\MoodleGradeImportRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Throwable;

use function Codefy\Framework\Helpers\trans;

final readonly class MoodleGradeImportService
{
    public const int REVIEW_TOKEN_LIMIT = 65536;

    public function __construct(
        private MoodleGradeImportRepository $records,
        private MoodleGradeSource $source,
        private MoodleGradeImportPolicy $policy,
        private InstitutionalCreditLedger $credits,
        private AdmissionsRepository $admissions,
        private AccessRepository $roles,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private MessageCipher $cipher,
        private AuditTrail $audit,
        private Clock $clock,
        private \Domain\Shared\EventSourcing\HistoricalEventStore $history,
    ) {
    }

    public function page(EntityId $section, ?EntityId $after = null): array
    {
        $actor = $this->authorize();
        $rows = $this->records->page($section, $after);
        $items = [];
        foreach (array_slice($rows, 0, 10) as $row) {
            $items[] = $this->display($row);
        }
        $job = $this->records->job($section);
        $this->authorize($actor);
        return [
            "items" => $items,
            "next_after" => count($rows) > 10 ? $rows[9]["id"] : null,
            "enabled" => $this->policy->enabled(),
            "request_id" => EntityId::generate()->value,
            "revision" => (int) ($job["revision"] ?? 0),
            "job" =>
                $job === null
                    ? null
                    : array_intersect_key(
                        $job,
                        array_flip([
                            "request_id",
                            "state",
                            "processed",
                            "ready",
                            "issue",
                            "requested_at",
                            "completed_at",
                        ]),
                    ),
            "section" => $this->source->scope($section)["section"],
        ];
    }

    public function request(
        EntityId $section,
        EntityId $request,
        int $revision,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize();
        $reason = $this->reason($reason);
        return $this->transactions->run(function () use (
            $section,
            $request,
            $revision,
            $reason,
            $actor,
        ): array {
            $this->roles->lock();
            $this->authorize($actor);
            $job = $this->records->job($section);
            $intent = MoodleGradeSource::hash([
                $section->value,
                $request->value,
                $actor->value,
                $reason,
            ]);
            if (($job["request_id"] ?? null) === $request->value) {
                $old = json_decode(
                    $this->cipher->open($job["encrypted_context"]),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
                if (($old["intent"] ?? null) !== $intent) {
                    throw new ConcurrentModificationException();
                }
                return [
                    "request_id" => $request->value,
                    "state" => $job["state"],
                ];
            }
            $this->enabled();
            if (
                (int) ($job["revision"] ?? 0) !== $revision ||
                in_array($job["state"] ?? null, ["queued", "running"], true)
            ) {
                self::invalid(
                    trans(
                        "An intake is already running or its status changed. Refresh before starting another.",
                    ),
                );
            }
            $scope = $this->source->scope($section);
            $this->audit->record(
                $actor,
                "moodle.grade_intake_requested",
                $request,
            );
            $this->authorize($actor);
            $this->enabled();
            if ($this->source->scope($section)["hash"] !== $scope["hash"]) {
                throw new ConcurrentModificationException();
            }
            $this->records->saveJob($section, $revision, [
                "request_id" => $request->value,
                "binding_id" => $scope["binding"]->id->value,
                "scope_hash" => $scope["hash"],
                "requested_by" => $actor->value,
                "requested_at" => $this->at(),
                "state" => "queued",
                "after_enrollment" => null,
                "through_enrollment" => $this->records->boundary($section),
                "processed" => 0,
                "ready" => 0,
                "lease_until" => 0,
                "issue" => "none",
                "completed_at" => null,
                "encrypted_context" => $this->cipher->seal(
                    json_encode(
                        [
                            "intent" => $intent,
                            "reason" => $reason,
                            "mode" => "reviewed_final",
                        ],
                        JSON_THROW_ON_ERROR,
                    ),
                ),
            ]);
            return ["request_id" => $request->value, "state" => "queued"];
        });
    }

    public function cancel(EntityId $section, int $revision): array
    {
        $actor = $this->authorize();
        return $this->transactions->run(function () use (
            $section,
            $revision,
            $actor,
        ): array {
            $this->roles->lock();
            $this->authorize($actor);
            $job =
                $this->records->job($section) ??
                throw new RecordNotFoundException();
            if (
                (int) $job["revision"] !== $revision ||
                !in_array($job["state"], ["queued", "running"], true)
            ) {
                throw new ConcurrentModificationException();
            }
            $this->audit->record(
                $actor,
                "moodle.grade_intake_cancelled",
                new EntityId($job["request_id"]),
            );
            $this->authorize($actor);
            $this->records->saveJob($section, $revision, [
                "state" => "cancelled",
                "completed_at" => $this->at(),
                "lease_until" => 0,
            ]);
            $this->records->notify([
                "id" => $job["request_id"],
                "owner_id" => $job["requested_by"],
                "section_id" => $section->value,
                "state" => "cancelled",
                "processed" => (int) $job["processed"],
                "ready" => (int) $job["ready"],
                "recorded_at" => $this->at(),
                "read_at" => null,
            ]);
            return ["state" => "cancelled"];
        });
    }

    public function view(
        EntityId $section,
        EntityId $candidate,
        ?EntityId $assignment = null,
    ): array {
        $actor = $this->authorize();
        $row = $this->case($section, $candidate);
        $result = $this->display($row);
        if ($row["state"] === "pending") {
            try {
                $current = $this->capture($row);
                $this->same($row["source_hash"], $current["source_hash"]);
                $result["choices"] = $this->credits->choices(
                    new EntityId($row["student_id"]),
                    $section,
                    new EntityId($row["enrollment_id"]),
                    $assignment,
                );
            } catch (ConcurrentModificationException | AccessDeniedException | RecordNotFoundException) {
                $result["stale"] = true;
            }
        }
        $this->authorize($actor);
        return $result;
    }

    public function review(
        EntityId $section,
        EntityId $candidate,
        int $revision,
        string $action,
        ?EntityId $grade,
        #[\SensitiveParameter] string $reason,
        ?string $effective = null,
        ?EntityId $assignment = null,
    ): array {
        $actor = $this->authorize();
        $this->enabled();
        $reason = $this->reason($reason);
        if (
            !in_array($action, ["accept", "dismiss"], true) ||
            ($action === "accept" && $grade === null)
        ) {
            self::invalid(
                trans(
                    "Choose a published SIS grade or dismiss the imported result.",
                ),
            );
        }
        return $this->transactions->run(function () use (
            $section,
            $candidate,
            $revision,
            $action,
            $grade,
            $reason,
            $effective,
            $assignment,
            $actor,
        ): array {
            $this->roles->lock();
            $row = $this->case($section, $candidate);
            if (
                (int) $row["revision"] !== $revision ||
                !in_array($row["state"], ["pending", "unavailable"], true) ||
                ($action === "accept" && $row["state"] !== "pending")
            ) {
                throw new ConcurrentModificationException();
            }
            $review = null;
            $route = null;
            if ($action === "accept") {
                $current = $this->capture($row);
                $this->same($row["source_hash"], $current["source_hash"]);
                $evidence = new ExternalGradeEvidence(
                    $this->stored($row)["evidence"],
                );
                $review = $this->credits->review(
                    new EntityId($row["student_id"]),
                    $section,
                    new EntityId($row["enrollment_id"]),
                    $grade,
                    $current["credit"]["version"],
                    assignment: $assignment,
                    reason: $reason,
                    effective: $effective,
                    externalGrade: $evidence,
                );
                $route = $current["route_hash"];
            }
            $data = [
                "kind" => "moodle-grade-review",
                "section_id" => $section->value,
                "candidate_id" => $candidate->value,
                "student_id" => $row["student_id"],
                "actor_id" => $actor->value,
                "revision" => $revision,
                "source_hash" => $row["source_hash"],
                "route_hash" => $route,
                "action" => $action,
                "reason" => $reason,
                "operation_id" =>
                    $review["operation_id"] ?? EntityId::generate()->value,
                "requires_approval" => $review["requires_approval"] ?? false,
                "grade_token" => $review["review_token"] ?? null,
                "expires_at" => $this->clock->now()->getTimestamp() + 1200,
            ];
            $this->authorize($actor);
            return [
                "record" => $this->display($row),
                "action" => $action,
                "reason" => $reason,
                "grade_review" =>
                    $review === null
                        ? null
                        : array_diff_key(
                            $review,
                            array_flip([
                                "review_token",
                                "actor_id",
                                "baseline",
                                "observed_grade",
                                "grade_change_policy",
                            ]),
                        ),
                "review_token" => $this->cipher->seal(
                    json_encode($data, JSON_THROW_ON_ERROR),
                ),
            ];
        });
    }

    public function confirm(
        EntityId $section,
        EntityId $candidate,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->authorize();
        try {
            if (strlen($token) > self::REVIEW_TOKEN_LIMIT) {
                throw new \InvalidArgumentException();
            }
            $d = json_decode(
                $this->cipher->open($token),
                true,
                16,
                JSON_THROW_ON_ERROR,
            );
            if (
                !is_array($d) ||
                ($d["kind"] ?? null) !== "moodle-grade-review" ||
                ($d["section_id"] ?? null) !== $section->value ||
                ($d["candidate_id"] ?? null) !== $candidate->value ||
                ($d["actor_id"] ?? null) !== $actor->value
            ) {
                throw new \InvalidArgumentException();
            }
        } catch (Throwable) {
            self::invalid(
                trans("Review this Moodle result again before confirming."),
            );
        }
        return $this->transactions->run(function () use (
            $section,
            $candidate,
            $token,
            $actor,
            $d,
        ): array {
            $this->roles->lock();
            $this->authorize($actor);
            $row = $this->case($section, $candidate);
            $intent = hash("sha256", $token);
            if ($row["accepted_hash"] !== null) {
                $this->same($row["accepted_hash"], $intent);
                return $this->disposition($row)["result"];
            }
            $this->enabled();
            if (
                $d["expires_at"] <= $this->clock->now()->getTimestamp() ||
                (int) $row["revision"] !== $d["revision"] ||
                $row["student_id"] !== $d["student_id"] ||
                !in_array($row["state"], ["pending", "unavailable"], true)
            ) {
                throw new ConcurrentModificationException(
                    trans(
                        "This imported result changed or its review expired. Review it again.",
                    ),
                );
            }
            $this->same($row["source_hash"], $d["source_hash"]);
            $this->audit->record(
                $actor,
                "moodle.grade_" . $d["action"],
                $candidate,
            );
            $this->authorize($actor);
            $this->enabled();
            $receipt = null;
            if ($d["action"] === "accept") {
                $current = $this->capture($row);
                $this->same($current["source_hash"], $d["source_hash"]);
                $this->same($current["route_hash"], $d["route_hash"]);
                $receipt = $this->credits->commit(
                    new EntityId($row["student_id"]),
                    $section,
                    $d["grade_token"],
                );
                $this->same(
                    $this->capture($row)["route_hash"],
                    $d["route_hash"],
                );
            }
            $this->authorize($actor);
            $this->enabled();
            $state =
                $d["action"] === "dismiss"
                    ? "dismissed"
                    : ($d["requires_approval"]
                        ? "requested"
                        : "accepted");
            $result = [
                "candidate_id" => $candidate->value,
                "operation_id" => $d["operation_id"],
                "state" => $state,
                "receipt" => $receipt,
            ];
            $this->records->change($candidate, (int) $row["revision"], [
                "state" => $state,
                "operation_id" => $d["operation_id"],
                "accepted_hash" => $intent,
                "encrypted_result" => $this->cipher->seal(
                    json_encode(
                        [
                            "result" => $result,
                            "actor_id" => $actor->value,
                            "reason" => $d["reason"],
                            "decided_at" => $this->at(),
                        ],
                        JSON_THROW_ON_ERROR,
                    ),
                ),
            ]);
            return $result;
        });
    }

    public function notices(bool $unread, ?EntityId $after = null): array
    {
        $owner = $this->access->authenticatedUserId();
        $rows = $this->records->notices($owner, $unread, $after);
        $this->owner($owner);
        $items = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            $items[] = array_diff_key(
                $row,
                array_flip(["owner_id", "read_at"]),
            ) + [
                "unread" => $row["read_at"] === null,
                "revision" => (int) $row["revision"],
            ];
        }
        return [
            "items" => $items,
            "next_after" => count($rows) > 20 ? $rows[19]["id"] : null,
        ];
    }

    public function notice(EntityId $id, int $revision, bool $delete): array
    {
        $owner = $this->access->authenticatedUserId();
        return $this->transactions->run(function () use (
            $owner,
            $id,
            $revision,
            $delete,
        ): array {
            $this->owner($owner);
            $row =
                $this->records->notice($owner, $id) ??
                throw new RecordNotFoundException();
            if ((int) $row["revision"] !== $revision) {
                throw new ConcurrentModificationException();
            }
            $this->records->clearNotice(
                $owner,
                $id,
                $revision,
                $delete ? null : $this->at(),
            );
            $this->owner($owner);
            return ["state" => $delete ? "deleted" : "read"];
        });
    }

    private function display(array $row): array
    {
        $person = $this->admissions->person(
            $this->admissions->student(new EntityId($row["student_id"]))
                ->personId,
        );
        if ($person->id->value !== $row["person_id"]) {
            throw new AccessDeniedException();
        }
        $stored = $this->stored($row);
        $disposition = $this->disposition($row);
        return [
            "id" => $row["id"],
            "student_id" => $row["student_id"],
            "revision" => (int) $row["revision"],
            "state" => $row["state"],
            "disposition" =>
                $disposition === null
                    ? null
                    : array_intersect_key(
                        $disposition,
                        array_flip(["reason", "decided_at"]),
                    ),
            "observed_at" => $row["observed_at"],
            "grade_change_request_id" =>
                $row["state"] === "requested" ? $row["operation_id"] : null,
            "issue" => $stored["issue"] ?? null,
            "grade" =>
                $stored["evidence"]["grade"] === null
                    ? null
                    : array_diff_key(
                        new ExternalCourseGrade($stored["evidence"]["grade"])
                            ->facts,
                        array_flip(["item_id", "revision"]),
                    ),
            "identity" => [
                "name" => trim($person->givenName . " " . $person->familyName),
                "institutional_id" => $person->institutionalId->value,
            ],
        ];
    }

    private function disposition(array $row): ?array
    {
        $final = in_array(
            $row["state"],
            ["accepted", "requested", "dismissed"],
            true,
        );
        if (!$final) {
            if ($row["accepted_hash"] !== null) {
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            }
            return null;
        }
        if (
            $row["accepted_hash"] === null ||
            !is_string($row["encrypted_result"])
        ) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        $saved = json_decode(
            $this->cipher->open($row["encrypted_result"]),
            true,
            12,
            JSON_THROW_ON_ERROR,
        );
        $result = $saved["result"] ?? null;
        if (
            !is_array($result) ||
            ($result["candidate_id"] ?? null) !== $row["id"] ||
            ($result["operation_id"] ?? null) !== $row["operation_id"] ||
            ($result["state"] ?? null) !== $row["state"]
        ) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        if ($row["state"] !== "dismissed") {
            if (
                ($result["receipt"]["operation_id"] ?? null) !==
                    $row["operation_id"] ||
                $this->history->verifyOperation(
                    new EntityId($row["operation_id"]),
                ) < 1
            ) {
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            }
        }
        return $saved;
    }

    private function stored(array $row): array
    {
        $data = json_decode(
            $this->cipher->open($row["encrypted_source"]),
            true,
            12,
            JSON_THROW_ON_ERROR,
        );
        $e = $data["evidence"];
        foreach (
            [
                "student_id",
                "person_id",
                "section_id",
                "enrollment_id",
                "source_hash",
            ] as $key
        ) {
            if ($e[$key] !== $row[$key]) {
                throw new AccessDeniedException();
            }
        }
        if ($e["candidate_id"] !== $row["id"]) {
            throw new AccessDeniedException();
        }
        return $data;
    }

    private function case(EntityId $section, EntityId $candidate): array
    {
        $row =
        $this->records->candidate($candidate) ??
        throw new RecordNotFoundException();
        if ($row["section_id"] !== $section->value) {
            throw new AccessDeniedException();
        }
        return $row;
    }

    private function capture(array $row): array
    {
        return $this->source->capture(
            new EntityId($row["section_id"]),
            new EntityId($row["student_id"]),
            new EntityId($row["enrollment_id"]),
        );
    }

    private function authorize(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->authenticatedUserId();
        if ($expected !== null && $expected->value !== $actor->value) {
            throw new AccessDeniedException();
        }
        foreach (MoodleGradeImportWorker::PERMISSIONS as $permission) {
            if (
                $this->access->requirePermission($permission)->value !==
                $actor->value
            ) {
                throw new AccessDeniedException();
            }
        }
        return $actor;
    }

    private function owner(EntityId $expected): void
    {
        if ($this->access->authenticatedUserId()->value !== $expected->value) {
            throw new AccessDeniedException();
        }
    }

    private function enabled(): void
    {
        if (!$this->policy->enabled()) {
            self::invalid(
                trans(
                    "Enable reviewed Moodle final-grade intake before starting or confirming a new decision.",
                ),
            );
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (
            $reason === "" ||
            mb_strlen($reason) > 1000 ||
            !mb_check_encoding($reason, "UTF-8") ||
            preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $reason)
        ) {
            self::invalid(
                trans("Give an evaluation reason of at most 1,000 characters."),
            );
        }
        return $reason;
    }

    private function same(string $actual, string $expected): void
    {
        if (!hash_equals($actual, $expected)) {
            throw new ConcurrentModificationException(
                trans(
                    "The imported result or its SIS source changed. Refresh and review it again.",
                ),
            );
        }
    }

    private static function invalid(string $message): never
    {
        throw new ValidationException([
            ["field" => "review", "message" => $message],
        ]);
    }

    private function at(): string
    {
        return $this->clock
            ->now()
            ->setTimezone(new DateTimeZone("UTC"))
            ->format("Y-m-d\TH:i:s\Z");
    }
}
