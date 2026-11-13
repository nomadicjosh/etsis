<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Lms\Repository\{MoodleBindingRepository, MoodleSectionRepository, MoodleCourseRequestRepository};
use Domain\Registration\Repository\{SectionVersionRepository, SectionCourseBindingRepository};
use Domain\Shared\Exception\{AccessDeniedException, ConcurrentModificationException};
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

final readonly class MoodleCourseProvisioningService
{
    public function __construct(
        private MoodleAutomationPolicy $policy,
        private MoodleCourseRequestRepository $requests,
        private MoodleBindingRepository $bindings,
        private MoodleSectionRepository $sections,
        private SectionVersionRepository $versions,
        private SectionCourseBindingRepository $courses,
        private MoodleSectionGateway $remote,
        private AccessRepository $roles,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private MessageCipher $cipher,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }
    /** Local setup only; academic caller already checks catalog authority.
     * @return array<string,mixed> */
    public function options(EntityId $section): array
    {
        $actor = $this->access->authenticatedUserId();
        $binding = $this->bindings->current();
        $allowed = $this->roles->allows($actor->value, MoodleAdministrationService::MANAGE);
        $link = $binding === null ? null : $this->sections->link($binding->id, $section);
        $request = $binding === null ? null : $this->requests->forSection($binding->id, $section);
        return [
            "available" =>
                $allowed &&
                $this->policy->courseCreationEnabled() &&
                $binding !== null &&
                $link === null &&
                $request === null,
            "linked" => $link !== null,
            "request_state" => $request["state"] ?? null,
            "issue" => $request["issue_code"] ?? null,
        ];
    }
    /** @param array<string,mixed> $review
     * @return array<string,mixed>|null */
    public function review(EntityId $section, array $review, bool $create): ?array
    {
        if (!$create) {
            return null;
        }
        $actor = $this->access->requirePermission(MoodleAdministrationService::MANAGE);
        if (!$this->options($section)["available"]) {
            throw new AccessDeniedException("Moodle course creation is unavailable for this section.");
        }
        $binding = $this->bindings->current() ?? throw new AccessDeniedException();
        $bound =
        $this->courses->bound($section) ??
        throw new ConcurrentModificationException("Retain the course publication first.");
        $d = $review["definition"];
        $zone = $this->clock->now()->getTimezone();
        return [
            "binding_id" => $binding->id->value,
            "binding_hash" => hash("sha256", json_encode($binding->describe(), JSON_THROW_ON_ERROR)),
            "actor_id" => $actor->value,
            "course" => [
                "shortname" => "etsis-" . $section->value,
                "fullname" => mb_substr(
                    strip_tags($d["term_code"] . " · " . $d["code"] . " · " . $bound->definition->title),
                    0,
                    254,
                ),
                "startdate" => new \DateTimeImmutable($d["instruction_starts_on"], $zone)->getTimestamp(),
                "enddate" => new \DateTimeImmutable($d["instruction_ends_on"], $zone)
                    ->modify("+1 day")
                    ->getTimestamp(),
            ],
        ];
    }
    /** Acquire access authority before the academic section lock, using the shared lock order.
     * @param array<string,mixed> $proof */
    public function prepare(EntityId $section, EntityId $publication, array $proof): void
    {
        $this->roles->lock();
        $actor = $this->access->requirePermission(MoodleAdministrationService::MANAGE);
        $binding = $this->bindings->current() ?? throw new AccessDeniedException();
        $row = $this->requests->forSection($binding->id, $section);
        if (
            $row !== null &&
            $row["publication_id"] === $publication->value &&
            $row["requested_by"] === $actor->value
        ) {
            return;
        }
        if (
            !$this->policy->courseCreationEnabled() ||
            $row !== null ||
            $proof["actor_id"] !== $actor->value ||
            $proof["binding_id"] !== $binding->id->value ||
            !hash_equals(
                $proof["binding_hash"],
                hash("sha256", json_encode($binding->describe(), JSON_THROW_ON_ERROR)),
            ) ||
            $this->sections->link($binding->id, $section) !== null
        ) {
            throw new ConcurrentModificationException("Moodle setup changed. Review the publication again.");
        }
    }
    /** Queue in the publication transaction. No HTTP.
     * @param array<string,mixed> $proof */
    public function enqueue(EntityId $section, EntityId $publication, array $proof): void
    {
        $this->prepare($section, $publication, $proof);
        $actor = $this->access->requirePermission(MoodleAdministrationService::MANAGE);
        $binding = $this->bindings->current() ?? throw new AccessDeniedException();
        $row = $this->requests->forSection($binding->id, $section);
        if ($row !== null) {
            if ($row["publication_id"] !== $publication->value || $row["requested_by"] !== $actor->value) {
                throw new ConcurrentModificationException("A course request already exists.");
            }
            return;
        }
        if (
            !$this->policy->courseCreationEnabled() ||
            $proof["actor_id"] !== $actor->value ||
            $proof["binding_id"] !== $binding->id->value ||
            !hash_equals(
                $proof["binding_hash"],
                hash("sha256", json_encode($binding->describe(), JSON_THROW_ON_ERROR)),
            ) ||
            $this->sections->link($binding->id, $section) !== null
        ) {
            throw new ConcurrentModificationException("Moodle setup changed. Review the publication again.");
        }
        $this->requests->add([
            "id" => EntityId::generate()->value,
            "binding_id" => $binding->id->value,
            "section_id" => $section->value,
            "publication_id" => $publication->value,
            "requested_by" => $actor->value,
            "requested_at" => $this->clock
                ->now()
                ->setTimezone(new \DateTimeZone("UTC"))
                ->format("Y-m-d\TH:i:s\Z"),
            "encrypted_request" => $this->cipher->seal(json_encode($proof, JSON_THROW_ON_ERROR)),
        ]);
        $this->audit->record($actor, "moodle.course_creation_requested", $section);
    }
    public function runOne(): string
    {
        if (!$this->policy->courseCreationEnabled()) {
            return "idle";
        }
        $now = $this->clock->now()->getTimestamp();
        $row = $this->transactions->run(fn() => $this->requests->claim($now));
        if ($row === null) {
            return "idle";
        }
        $id = new EntityId($row["id"]);
        $version = (int) $row["version"];
        $section = new EntityId($row["section_id"]);
        try {
            $proof = json_decode(
                $this->cipher->open($row["encrypted_request"]),
                true,
                8,
                JSON_THROW_ON_ERROR,
            );
            $binding = $this->bindings->current() ?? throw new AccessDeniedException();
            $this->validate($row, $proof);
            if ($this->sections->link($binding->id, $section) !== null) {
                throw new ConcurrentModificationException('A course link already exists; review the original request.');
            }
            $selected = $this->versions->selected($section);
            if ($selected === null) {
                $this->requests->finish($id, $version, "queued", "selection_required", $now + 60);
                return "course_waiting";
            }
            if ($selected->publication->value !== $row["publication_id"]) {
                throw new ConcurrentModificationException(
                    "Select the publication used by the Moodle request.",
                );
            }
            $source = $this->sections->section($section);
        } catch (\Throwable) {
            $this->requests->finish($id, $version, "review_required", "source_changed", 0);
            return "review_required";
        }
        try {
            $course = $this->remote->provisionCourse($binding, $id, $proof["course"]);
        } catch (\Throwable) {
            $this->requests->finish($id, $version, "processing", "connection_uncertain", $now + 120);
            return (int) $row["attempts"] >= 4 ? "review_required" : "course_retry_pending";
        }
        try {
            return $this->transactions->run(function () use (
                $row,
                $proof,
                $source,
                $section,
                $binding,
                $course,
                $id,
                $version,
            ): string {
                $this->roles->lock();
                $this->validate($row, $proof);
                if (
                    $this->versions->selected($section)?->publication->value !== $row["publication_id"] ||
                    $this->sections->section($section)["hash"] !== $source["hash"]
                ) {
                    throw new ConcurrentModificationException();
                }
                $old = $this->sections->link($binding->id, $section);
                if ($old !== null && $old["moodle_course_id"] !== $course->value) {
                    throw new ConcurrentModificationException();
                }
                if ($old === null) {
                    $this->sections->add(
                        EntityId::generate(),
                        $binding->id,
                        $section,
                        $course->value,
                        new EntityId($row["requested_by"]),
                        $this->clock->now()->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d\TH:i:s\Z"),
                        $this->cipher->seal(
                            json_encode(
                                ["request_id" => $id->value, "publication_id" => $row["publication_id"]],
                                JSON_THROW_ON_ERROR,
                            ),
                        ),
                    );
                }
                $this->requests->finish($id, $version, "completed", "none", 0);
                $this->audit->record(
                    new EntityId($row["requested_by"]),
                    "moodle.course_created_and_linked",
                    $section,
                );
                return "course_completed";
            });
        } catch (\Throwable) {
            $this->requests->finish($id, $version, "review_required", "source_changed", 0);
            return "review_required";
        }
    }
    /** @param array<string,mixed> $row
     * @param array<string,mixed> $proof */
    private function validate(array $row, array $proof): void
    {
        $binding = $this->bindings->current();
        $actor = $row["requested_by"];
        $this->roles->requireUser(new EntityId($actor));
        if (
            !$this->policy->courseCreationEnabled() ||
            !$this->roles->allows($actor, "sis:moodle:manage") ||
            !$this->roles->allows($actor, "sis:catalog:manage") ||
            $binding === null ||
            $binding->id->value !== $row["binding_id"] ||
            $proof["binding_id"] !== $row["binding_id"] ||
            $proof["actor_id"] !== $actor ||
            !hash_equals(
                $proof["binding_hash"],
                hash("sha256", json_encode($binding->describe(), JSON_THROW_ON_ERROR)),
            )
        ) {
            throw new AccessDeniedException("Course creation authority changed.");
        }
    }
}
