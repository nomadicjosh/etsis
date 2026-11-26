<?php

declare(strict_types=1);

namespace Domain\Transcript\Service;

use DateTimeZone;
use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transcript\Repository\TranscriptRepository;
use Domain\Transcript\Repository\TranscriptWorklistRepository;

use function Codefy\Framework\Helpers\trans_html;

/** Current office routing only: assignment never grants access or changes fulfillment. */
final readonly class TranscriptWorklistService
{
    public const array DUTIES = ["sis:transcript:manage", "sis:student:read"];
    public function __construct(
        private TranscriptWorklistRepository $work,
        private TranscriptRepository $records,
        private AdmissionsRepository $admissions,
        private PersonAccountRepository $people,
        private AccessRepository $permissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }
    private function actor(): EntityId
    {
        $actor = $this->access->authenticatedUserId();
        foreach (self::DUTIES as $duty) {
            if (
                $this->access->requirePermission($duty)->value !== $actor->value ||
                !in_array($actor->value, $this->permissions->allowedUsers([$actor], $duty), true)
            ) {
                throw new AccessDeniedException();
            }
        }
        $this->person($actor);
        return $actor;
    }
    private function person(EntityId $user): EntityId
    {
        $person = $this->people->findByUser($user);
        if ($person?->ownerUserId?->value !== $user->value) {
            throw new AccessDeniedException();
        }
        return $person->id;
    }
    private function stableActor(EntityId $actor, EntityId $person): void
    {
        if ($this->actor()->value !== $actor->value || $this->person($actor)->value !== $person->value) {
            throw new AccessDeniedException();
        }
    }
    private function candidate(?EntityId $user, ?EntityId $person): void
    {
        if (($user === null) !== ($person === null)) {
            throw new ValidationException([
                [
                    "field" => "assignee_id",
                    "message" => trans_html("Choose an eligible colleague or explicitly clear the assignment."),
                ],
            ]);
        }
        if ($user === null) {
            return;
        }
        $candidate = $this->people->findByUser($user);
        if ($candidate?->ownerUserId?->value !== $user->value || $candidate?->id->value !== $person->value) {
            throw new ConcurrentModificationException(trans_html("The selected colleague changed. Search again."));
        }
        foreach (self::DUTIES as $duty) {
            if (!in_array($user->value, $this->permissions->allowedUsers([$user], $duty), true)) {
                throw new ValidationException([
                    [
                        "field" => "assignee_id",
                        "message" => trans_html(
                            "Choose an enabled colleague with transcript and student-read access.",
                        ),
                    ],
                ]);
            }
        }
    }
    private function search(string $search): string
    {
        $search = trim($search);
        if (
            !mb_check_encoding($search, "UTF-8") ||
            mb_strlen($search) > 80 ||
            preg_match('/[\x00-\x1f\x7f]/', $search)
        ) {
            throw new ValidationException([
                ["field" => "search", "message" => trans_html("Search with up to eighty characters.")],
            ]);
        }
        return $search;
    }
    public function assignees(string $search = "", ?EntityId $after = null): array
    {
        $actor = $this->actor();
        $person = $this->person($actor);
        $search = $this->search($search);
        if (mb_strlen($search) < 2) {
            return ["items" => [], "next_after" => null, "search_required" => true];
        }
        $rows = $this->permissions->eligibleAccounts(self::DUTIES, $search, $after);
        $this->stableActor($actor, $person);
        if ($rows !== $this->permissions->eligibleAccounts(self::DUTIES, $search, $after)) {
            throw new ConcurrentModificationException(trans_html("Eligible colleagues changed. Search again."));
        }
        $page = array_slice($rows, 0, 20);
        return [
            "items" => $page,
            "next_after" => count($rows) > 20 ? $page[19]["id"] : null,
            "search_required" => false,
        ];
    }
    public function page(
        string $scope = "all",
        string $status = "active",
        string $search = "",
        ?EntityId $after = null,
    ): array {
        $actor = $this->actor();
        $person = $this->person($actor);
        $search = $this->search($search);
        if (
            !in_array($scope, ["all", "mine", "unassigned"], true) ||
            !in_array($status, ["active", "pending", "ready", "finished", "all"], true)
        ) {
            throw new ValidationException([
                ["field" => "scope", "message" => trans_html("Choose a transcript worklist and status.")],
            ]);
        }
        $rows = $this->work->page($actor, $person, $scope, $status, $search, $after);
        $page = array_slice($rows, 0, 20);
        $users = array_values(array_filter(array_column($page, "assignee_id")));
        $eligible = $this->eligible($users);
        foreach ($page as &$row) {
            $row["assignee_name"] =
            $row["assignee_id"] === null
            ? trans_html("Unassigned")
            : ($row["linked_person_id"] === null || !in_array($row["assignee_id"], $eligible, true)
                        ? trans_html("Assigned account unavailable")
                        : trim($row["given_name"] . " " . $row["family_name"]));
            unset(
                $row["assignee_id"],
                $row["assignee_person_id"],
                $row["linked_person_id"],
                $row["given_name"],
                $row["family_name"],
            );
        }
        unset($row);
        $this->stableActor($actor, $person);
        if (
            $rows !== $this->work->page($actor, $person, $scope, $status, $search, $after) ||
            $eligible !== $this->eligible($users)
        ) {
            throw new ConcurrentModificationException(trans_html("The transcript worklist changed. Reload it."));
        }
        return ["items" => $page, "next_after" => count($rows) > 20 ? $page[19]["id"] : null];
    }
    /** @param list<string> $users At most one twenty-row queue page. */
    private function eligible(array $users): array
    {
        $eligible = array_values(array_unique($users));
        foreach (self::DUTIES as $duty) {
            if ($eligible === []) {
                break;
            }
            $candidates = array_map(static fn (string $id): EntityId => new EntityId($id), $eligible);
            $eligible = array_values(array_intersect(
                $eligible,
                $this->permissions->allowedUsers($candidates, $duty),
            ));
        }
        return $eligible;
    }
    private function name(?string $user, ?string $person): string
    {
        if ($user === null) {
            return trans_html("Unassigned");
        }
        $current = $this->people->findByUser(new EntityId($user));
        if (
            $current?->id->value !== $person ||
            $current?->ownerUserId?->value !== $user ||
            $this->eligible([$user]) === []
        ) {
            return trans_html("Assigned account unavailable");
        }
        return trim($current->givenName . " " . $current->familyName);
    }
    public function view(EntityId $request): array
    {
        $actor = $this->actor();
        $person = $this->person($actor);
        $row = $this->work->request($request);
        $assignment = $this->work->assignment($request);
        $result = $this->describe($row, $assignment, $actor, $person);
        $this->stableActor($actor, $person);
        if ($this->work->request($request) !== $row || $this->work->assignment($request) !== $assignment) {
            throw new ConcurrentModificationException(trans_html("The transcript assignment changed. Reload it."));
        }
        return $result;
    }

    private function describe(array $request, ?array $assignment, EntityId $actor, EntityId $person): array
    {
        return [
            "version" => (int) ($assignment["version"] ?? 0),
            "request_version" => (int) $request["version"],
            "status" => $request["status"],
            "assignee_id" => $assignment["assignee_id"] ?? null,
            "assignee_person_id" => $assignment["assignee_person_id"] ?? null,
            "assignee_name" => $this->name(
                $assignment["assignee_id"] ?? null,
                $assignment["assignee_person_id"] ?? null,
            ),
            "actor_id" => $actor->value,
            "actor_person_id" => $person->value,
            "can_assign" => in_array($request["status"], ["pending", "ready"], true),
        ];
    }
    public function save(
        EntityId $request,
        int $version,
        int $requestVersion,
        ?EntityId $assignee,
        ?EntityId $assigneePerson,
    ): array {
        $actor = $this->actor();
        $person = $this->person($actor);
        // Discover routing outside the transaction: current() would otherwise lock the request first.
        $initial = $this->work->request($request);
        $student = new EntityId($initial["student_id"]);
        $parent = $this->admissions->student($student)->personId;
        return $this->transactions->run(function () use (
            $request,
            $version,
            $requestVersion,
            $assignee,
            $assigneePerson,
            $actor,
            $person,
            $initial,
            $student,
            $parent,
        ): array {
            $this->permissions->lock();
            $this->records->lockStudent($student);
            $this->records->lockPerson($parent);
            $this->records->lockRequest($request);
            $this->stableActor($actor, $person);
            $this->candidate($assignee, $assigneePerson);
            $before = $this->work->assignment($request);
            $row = $this->records->request($request);
            if (
                $row["student_id"] !== $initial["student_id"] ||
                $row["status"] !== $initial["status"] ||
                $row["version"] != $requestVersion ||
                (int) ($before["version"] ?? 0) !== $version ||
                !in_array($row["status"], ["pending", "ready"], true) ||
                $this->admissions->student($student)->personId->value !== $parent->value
            ) {
                throw new ConcurrentModificationException(
                    trans_html("The request or assignment changed. Reload before saving."),
                );
            }
            $expected = $before;
            if (
                ($before["assignee_id"] ?? null) !== $assignee?->value ||
                ($before["assignee_person_id"] ?? null) !== $assigneePerson?->value
            ) {
                $at = $this->clock->now()->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d\TH:i:s.u\Z");
                $this->work->save($request, $version, $assignee, $assigneePerson, $at);
                $expected = [
                    "id" => $request->value,
                    "version" => $version + 1,
                    "assignee_id" => $assignee?->value,
                    "assignee_person_id" => $assigneePerson?->value,
                    "updated_at" => $at,
                ];
                $this->audit->record($actor, "transcript.work_assigned", $request);
            }
            $this->stableActor($actor, $person);
            $this->candidate($assignee, $assigneePerson);
            if (
                $this->records->request($request) !== $row ||
                $this->work->assignment($request) != $expected ||
                $this->admissions->student($student)->personId->value !== $parent->value
            ) {
                throw new ConcurrentModificationException(
                    trans_html("Transcript assignment changed before completion."),
                );
            }
            return $this->describe($row, $expected, $actor, $person);
        });
    }
}
