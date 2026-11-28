<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use DateTimeZone;
use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Lms\Repository\MoodleGradeImportRepository;
use Domain\Lms\ValueObject\MoodleExternalId;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Throwable;

/** One exact enrollment per step. Network reads run outside database transactions. */
final readonly class MoodleGradeImportWorker
{
    public const array PERMISSIONS = [
        "sis:moodle:manage",
        "sis:gradebook:amend",
        "sis:student:read",
    ];

    public function __construct(
        private MoodleGradeImportRepository $records,
        private MoodleGradeSource $source,
        private MoodleGradeGateway $remote,
        private MoodleGradeImportPolicy $policy,
        private AccessRepository $roles,
        private UnitOfWork $transactions,
        private MessageCipher $cipher,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }

    public function authority(?EntityId $expected = null): EntityId
    {
        if (!$this->policy->enabled()) {
            throw new AccessDeniedException("Moodle grade intake is disabled.");
        }
        $actor = $this->policy->actor();
        if ($expected !== null && $actor->value !== $expected->value) {
            throw new AccessDeniedException();
        }
        $this->requireActor($actor);
        return $actor;
    }

    public function requireActor(EntityId $actor): void
    {
        $this->roles->requireUser($actor);
        foreach (self::PERMISSIONS as $permission) {
            if (!$this->roles->allows($actor->value, $permission)) {
                throw new AccessDeniedException(
                    "Current Moodle grade intake authority required.",
                );
            }
        }
    }

    public function runOne(): string
    {
        if (!$this->policy->enabled()) {
            return "disabled";
        }
        try {
            $actor = $this->authority();
            $pending = $this->records->pending(
                $this->clock->now()->getTimestamp(),
            );
            if ($pending === null) {
                return "idle";
            }
            $section = new EntityId($pending["id"]);
            $job = $this->transactions->run(function () use (
                $pending,
                $section,
                $actor,
            ): array {
                $this->roles->lock();
                $this->authority($actor);
                $current = $this->records->job($section);
                if (
                    $current === null ||
                    (int) $current["revision"] !== (int) $pending["revision"]
                ) {
                    throw new ConcurrentModificationException();
                }
                $this->requireActor(new EntityId($current["requested_by"]));
                $this->records->saveJob($section, (int) $current["revision"], [
                    "state" => "running",
                    "lease_until" => $this->clock->now()->getTimestamp() + 30,
                ]);
                return $this->records->job($section);
            });
            $scope = $this->source->scope($section);
            if ($scope["hash"] !== $job["scope_hash"]) {
                return $this->finish($job, $actor, "failed", "source_changed");
            }
            $next =
            $job["through_enrollment"] === null
            ? null
            : $this->records->nextEnrollment(
                $section,
                $job["after_enrollment"],
                $job["through_enrollment"],
            );
            if ($next === null) {
                return $this->finish($job, $actor, "complete", $job["issue"]);
            }
            $student = new EntityId($next["student_id"]);
            $enrollment = new EntityId($next["id"]);
            try {
                $before = $this->source->capture(
                    $section,
                    $student,
                    $enrollment,
                );
            } catch (AccessDeniedException | RecordNotFoundException | ConcurrentModificationException) {
                return $this->advance(
                    $job,
                    $actor,
                    $student,
                    $enrollment,
                    "source_unavailable",
                );
            }
            $grade = $this->remote->courseGrade(
                $before["binding"],
                new MoodleExternalId($before["moodle_course_id"]),
                new MoodleExternalId($before["moodle_user_id"]),
            );
            $this->transactions->run(function () use (
                $job,
                $actor,
                $section,
                $student,
                $enrollment,
                $before,
                $grade,
            ): void {
                $this->current($job, $actor);
                $after = $this->source->capture(
                    $section,
                    $student,
                    $enrollment,
                );
                if ($after["source_hash"] !== $before["source_hash"]) {
                    throw new ConcurrentModificationException();
                }
                $key = MoodleGradeSource::hash([
                    $before["binding_id"],
                    $enrollment->value,
                    $before["moodle_course_id"],
                    $before["moodle_user_id"],
                    $grade->facts,
                ]);
                $old = $this->records->source($key);
                $candidate =
                    $old === null
                        ? EntityId::generate()
                        : new EntityId($old["id"]);
                $evidence = array_intersect_key(
                    $before,
                    array_flip([
                        "binding_id",
                        "section_id",
                        "section_link_id",
                        "person_id",
                        "person_link_id",
                        "student_id",
                        "enrollment_id",
                        "moodle_course_id",
                        "moodle_user_id",
                        "source_hash",
                    ]),
                ) + [
                    "kind" => "moodle_course_total",
                    "candidate_id" => $candidate->value,
                    "observed_at" => $this->at(),
                    "grade" => $grade->facts,
                ];
                $state =
                    $grade->facts["state"] === "available"
                        ? "pending"
                        : "unavailable";
                $values = [
                    "request_id" => $job["request_id"],
                    "source_hash" => $before["source_hash"],
                    "state" => $state,
                    "observed_at" => $evidence["observed_at"],
                    "encrypted_source" => $this->cipher->seal(
                        json_encode(
                            [
                                "evidence" => $evidence,
                                "route_hash" => $before["route_hash"],
                            ],
                            JSON_THROW_ON_ERROR,
                        ),
                    ),
                ];
                $this->records->supersede($enrollment, $key);
                if ($old === null) {
                    $this->records->add(
                        [
                            "id" => $candidate->value,
                            "section_id" => $section->value,
                            "student_id" => $student->value,
                            "enrollment_id" => $enrollment->value,
                            "person_id" => $before["person_id"],
                            "source_key" => $key,
                        ] + $values,
                    );
                } elseif (
                    in_array(
                        $old["state"],
                        ["pending", "unavailable", "superseded"],
                        true,
                    ) &&
                    ($old["source_hash"] !== $before["source_hash"] ||
                        $old["state"] === "superseded")
                ) {
                    $this->records->change(
                        $candidate,
                        (int) $old["revision"],
                        $values,
                    );
                }
                $this->audit->record(
                    $actor,
                    "moodle.grade_observed",
                    $candidate,
                );
                if (
                    $this->source->capture($section, $student, $enrollment)[
                        "source_hash"
                    ] !== $before["source_hash"]
                ) {
                    throw new ConcurrentModificationException();
                }
                $this->current($job, $actor);
                $this->records->saveJob($section, (int) $job["revision"], [
                    "after_enrollment" => $enrollment->value,
                    "processed" => (int) $job["processed"] + 1,
                    "ready" =>
                        (int) $job["ready"] +
                        (int) ($state === "pending" &&
                            ($old === null ||
                                in_array(
                                    $old["state"],
                                    ["pending", "unavailable", "superseded"],
                                    true,
                                ))),
                    "lease_until" => $this->clock->now()->getTimestamp(),
                ]);
            });
            return "grade_observed";
        } catch (ConcurrentModificationException) {
            return isset($job, $actor)
            ? $this->safelyFinish($job, $actor, "source_changed")
            : "busy";
        } catch (Throwable) {
            return isset($job, $actor)
            ? $this->safelyFinish($job, $actor, "review_required")
            : "review_required";
        }
    }

    private function advance(
        array $job,
        EntityId $actor,
        EntityId $student,
        EntityId $enrollment,
        string $issue,
    ): string {
        return $this->transactions->run(function () use (
            $job,
            $actor,
            $student,
            $enrollment,
            $issue,
        ): string {
            $this->current($job, $actor);
            $person = $this->source->person($student);
            $key = MoodleGradeSource::hash([
                "unavailable_sis_source",
                $job["binding_id"],
                $enrollment->value,
            ]);
            $old = $this->records->source($key);
            $candidate =
                $old === null ? EntityId::generate() : new EntityId($old["id"]);
            $hash = MoodleGradeSource::hash([
                $job["scope_hash"],
                $student->value,
                $person->id->value,
                $issue,
            ]);
            $values = [
                "request_id" => $job["request_id"],
                "state" => "unavailable",
                "source_hash" => $hash,
                "observed_at" => $this->at(),
                "encrypted_source" => $this->cipher->seal(
                    json_encode(
                        [
                            "issue" => $issue,
                            "evidence" => [
                                "candidate_id" => $candidate->value,
                                "student_id" => $student->value,
                                "person_id" => $person->id->value,
                                "section_id" => $job["id"],
                                "enrollment_id" => $enrollment->value,
                                "source_hash" => $hash,
                                "grade" => null,
                            ],
                        ],
                        JSON_THROW_ON_ERROR,
                    ),
                ),
            ];
            $this->records->supersede($enrollment, $key);
            if ($old === null) {
                $this->records->add(
                    [
                        "id" => $candidate->value,
                        "source_key" => $key,
                        "section_id" => $job["id"],
                        "student_id" => $student->value,
                        "person_id" => $person->id->value,
                        "enrollment_id" => $enrollment->value,
                    ] + $values,
                );
            } elseif (
                in_array($old["state"], ["unavailable", "superseded"], true)
            ) {
                $this->records->change(
                    $candidate,
                    (int) $old["revision"],
                    $values,
                );
            }
            $this->audit->record(
                $actor,
                "moodle.grade_source_unavailable",
                $candidate,
            );
            $this->current($job, $actor);
            $this->records->saveJob(
                new EntityId($job["id"]),
                (int) $job["revision"],
                [
                    "after_enrollment" => $enrollment->value,
                    "processed" => (int) $job["processed"] + 1,
                    "ready" => (int) $job["ready"],
                    "issue" => $issue,
                    "lease_until" => $this->clock->now()->getTimestamp(),
                ],
            );
            return "source_unavailable";
        });
    }

    private function current(array $job, EntityId $actor): void
    {
        $this->roles->lock();
        $this->authority($actor);
        $this->requireActor(new EntityId($job["requested_by"]));
        $current = $this->records->job(new EntityId($job["id"]));
        if (
            $current === null ||
            (int) $current["revision"] !== (int) $job["revision"] ||
            $current["request_id"] !== $job["request_id"] ||
            $current["state"] !== "running"
        ) {
            throw new ConcurrentModificationException();
        }
    }

    private function finish(
        array $job,
        EntityId $actor,
        string $state,
        string $issue,
    ): string {
        return $this->transactions->run(function () use (
            $job,
            $actor,
            $state,
            $issue,
        ): string {
            $this->current($job, $actor);
            $this->audit->record(
                $actor,
                "moodle.grade_intake_finished",
                new EntityId($job["request_id"]),
            );
            $this->current($job, $actor);
            $this->records->saveJob(
                new EntityId($job["id"]),
                (int) $job["revision"],
                [
                    "state" => $state,
                    "issue" => $issue,
                    "completed_at" => $this->at(),
                    "lease_until" => 0,
                ],
            );
            $this->records->notify([
                "id" => $job["request_id"],
                "owner_id" => $job["requested_by"],
                "section_id" => $job["id"],
                "state" => $state,
                "processed" => (int) $job["processed"],
                "ready" => (int) $job["ready"],
                "recorded_at" => $this->at(),
                "read_at" => null,
            ]);
            return $state === "complete"
                ? "intake_complete"
                : "review_required";
        });
    }

    private function safelyFinish(
        array $job,
        EntityId $actor,
        string $issue,
    ): string {
        try {
            return $this->finish($job, $actor, "failed", $issue);
        } catch (Throwable) {
            return "review_required";
        }
    }

    private function at(): string
    {
        return $this->clock
            ->now()
            ->setTimezone(new DateTimeZone("UTC"))
            ->format("Y-m-d\TH:i:s\Z");
    }
}
