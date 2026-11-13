<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Lms\Repository\MoodleBindingRepository;
use Domain\Lms\Repository\MoodleCourseRequestRepository;
use Domain\Lms\Repository\MoodlePersonLinkRepository;
use Domain\Lms\Repository\MoodleSectionRepository;
use Domain\Lms\ValueObject\MoodleExternalId;
use Domain\Lms\ValueObject\MoodleResourceKind;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

final readonly class MoodleSectionSyncService
{
    public function __construct(
        private MoodleSectionRepository $sections,
        private MoodleSectionGateway $remote,
        private MoodleBoundRecordReader $lookup,
        private MoodleBindingRepository $bindings,
        private MoodlePersonLinkRepository $people,
        private MoodleSectionSyncPolicy $policy,
        private AccessRepository $roles,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private Clock $clock,
        private MessageCipher $cipher,
        private AuditTrail $audit,
        private MoodleAutomaticAccountService $automatic,
        private MoodleCourseRequestRepository $courseRequests,
    ) {
    }
    /** @return array<string,mixed> */
    public function directory(string $search): array
    {
        $this->authorize();
        $binding = $this->bindings->current();
        $items = [];
        if (mb_strlen(trim($search)) >= 2) {
            foreach ($this->sections->directory(mb_substr(trim($search), 0, 80)) as $row) {
                $link =
                $binding === null ? null : $this->sections->link($binding->id, new EntityId($row["id"]));
                $items[] = $row + [
                    "moodle_course_id" => $link["moodle_course_id"] ?? null,
                    "issue" => $link["issue_code"] ?? null,
                    "members_needing_setup" =>
                        $link === null ? 0 : $this->sections->issues(new EntityId($link["id"])),
                ];
            }
        }
        $this->authorize();
        return ["items" => $items, "sync_enabled" => $this->policy->enabled()];
    }
    /** One explicit confirmation; independently verified course ID, no automatic identity matching.
     * @return array{linked:bool} */
    public function link(
        EntityId $section,
        MoodleExternalId $course,
        #[\SensitiveParameter] string $reason,
        bool $acknowledged,
    ): array {
        $actor = $this->authorize();
        $reason = trim($reason);
        if (
            !$acknowledged ||
            $reason === "" ||
            mb_strlen($reason) > 500 ||
            preg_match('/[\x00-\x1F\x7F]/', $reason)
        ) {
            throw new ValidationException([
                [
                    "field" => "reason",
                    "message" =>
                        "Confirm the verified course and give a reason without credentials or student details.",
                ],
            ]);
        }
        $binding =
        $this->bindings->current() ??
        throw new \DomainException("Approve the Moodle installation first.");
        $source = $this->sections->section($section);
        $old = $this->sections->link($binding->id, $section);
        if ($old !== null) {
            if ($old["moodle_course_id"] !== $course->value) {
                throw new ConcurrentModificationException(
                    "This section already has a retained Moodle course.",
                );
            }
            return ["linked" => true];
        }
        $this->requireNoCreationRequest($binding->id, $section);
        $this->audit->record($actor, "moodle.section_link_check_requested", $section);
        // A returned record must be explicitly present, not an unknown/invisible lookup result.
        $record = $this->lookup->inspectBound($binding, MoodleResourceKind::Course, $course);
        if (!$record->returned) {
            throw new ValidationException([
                [
                    "field" => "course",
                    "message" => "Moodle did not return that course. Verify its ID and service scope.",
                ],
            ]);
        }
        return $this->transactions->run(function () use (
            $actor,
            $section,
            $course,
            $binding,
            $source,
            $reason,
        ): array {
            $this->roles->lock();
            if (
                $this->authorize()->value !== $actor->value ||
                $this->bindings->current()?->describe() !== $binding->describe() ||
                $this->sections->section($section)["hash"] !== $source["hash"]
            ) {
                throw new ConcurrentModificationException(
                    "The administrator, installation or published section changed.",
                );
            }
            $old = $this->sections->link($binding->id, $section);
            if ($old !== null) {
                if ($old["moodle_course_id"] !== $course->value) {
                    throw new ConcurrentModificationException("A different section link was retained.");
                }
                return ["linked" => true];
            }
            $this->requireNoCreationRequest($binding->id, $section);
            $at = $this->clock->now()->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d\TH:i:s\Z");
            $this->sections->add(
                EntityId::generate(),
                $binding->id,
                $section,
                $course->value,
                $actor,
                $at,
                $this->cipher->seal(
                    json_encode(["source_hash" => $source["hash"], "reason" => $reason], JSON_THROW_ON_ERROR),
                ),
            );
            $this->audit->record($actor, "moodle.section_link_approved", $section);
            $this->authorize();
            return ["linked" => true];
        });
    }
    private function requireNoCreationRequest(EntityId $binding, EntityId $section): void
    {
        if ($this->courseRequests->forSection($binding, $section) !== null) {
            throw new ConcurrentModificationException(
                'Review this section’s original Moodle creation request before linking a different course.',
            );
        }
    }
    private function authorize(): EntityId
    {
        $actor = $this->access->requirePermission(MoodleAdministrationService::MANAGE);
        if ($this->access->requirePermission("sis:catalog:manage")->value !== $actor->value) {
            throw new AccessDeniedException("The administrator changed.");
        }
        return $actor;
    }
    private function workerAuthority(): EntityId
    {
        if (!$this->policy->enabled()) {
            throw new AccessDeniedException("Section synchronization is disabled.");
        }
        $actor = $this->policy->actor();
        $this->roles->requireUser($actor);
        $permissions = [
            MoodleAdministrationService::MANAGE,
            "sis:catalog:manage",
            "sis:student:read",
            "sis:person:manage",
        ];
        foreach ($permissions as $permission) {
            if (!$this->roles->allows($actor->value, $permission)) {
                throw new AccessDeniedException(
                    "The integration account needs current synchronization authority.",
                );
            }
        }
        return $actor;
    }
    public function runOne(): string
    {
        if (!$this->policy->enabled()) {
            return "disabled";
        }
        $actor = $this->workerAuthority();
        $binding =
        $this->bindings->current() ??
        throw new AccessDeniedException("An approved installation is required.");
        $now = $this->clock->now()->getTimestamp();
        $claim = $this->transactions->run(fn() => $this->sections->claim($binding->id, $now));
        if ($claim === null) {
            return "idle";
        }
        $id = new EntityId($claim["id"]);
        $section = new EntityId($claim["section_id"]);
        $version = (int) $claim["version"];
        $after = $claim["after_person"] === null ? null : new EntityId($claim["after_person"]);
        try {
            $this->sections->section($section);
            $person = $this->sections->candidate($section, $id, $after);
            if ($person === null) {
                $this->sections->finish($id, $version, null, $now, 0, "none");
                return "cycle_completed";
            }
            $desired = $this->sections->desired($section, $id, $person);
            $this->transactions->run(
                fn() => $this->sections->remember($id, $person, $desired["enrollment_id"]),
            );
            $link = $this->people->forPerson($binding->id, $person);
            $issue = $desired["issue"];
            if ($link === null) {
                if ($desired["account_eligible"]) {
                    $issue = $this->automatic->ensure($section, $id, $person);
                }
            } else {
                $policy = $this->remote->rosterPolicy($binding);
                $this->workerAuthority();
                if (
                    $this->bindings->current()?->describe() !== $binding->describe() ||
                    $this->sections->desired($section, $id, $person)["hash"] !== $desired["hash"]
                ) {
                    throw new ConcurrentModificationException("The desired membership changed.");
                }
                $result = $this->remote->syncMember($binding, $policy["policy_hash"], [
                    "section" => $section->value,
                    "course" => $claim["moodle_course_id"],
                    "person" => $person->value,
                    "user" => $link->external->value,
                    "version" => $version,
                    "student" => $desired["student"],
                    "instructor" => $desired["instructor"],
                ]);
                if ($result === "externally_managed") {
                    $issue = "externally_managed";
                }
            }
            return $this->transactions->run(function () use (
                $actor,
                $binding,
                $section,
                $id,
                $version,
                $person,
                $desired,
                $issue,
            ): string {
                $this->roles->lock();
                if (
                    $this->workerAuthority()->value !== $actor->value ||
                    $this->bindings->current()?->describe() !== $binding->describe() ||
                    $this->sections->desired($section, $id, $person)["hash"] !== $desired["hash"]
                ) {
                    throw new ConcurrentModificationException("Membership changed during synchronization.");
                }
                $this->sections->memberResult($id, $person, $desired["hash"], $issue);
                $this->sections->finish($id, $version, $person, 0, 0, $issue);
                $this->audit->record($actor, "moodle.section_member_reconciled", $person);
                return $issue === "none" ? "synchronized" : "setup_required";
            });
        } catch (ConcurrentModificationException) {
            $this->sections->finish($id, $version, $after, 0, 0, "source_changed");
            return "source_changed";
        } catch (\Throwable) {
            $this->sections->finish($id, $version, $after, 0, $now + 60, "review_required");
            return "review_required";
        }
    }
}
