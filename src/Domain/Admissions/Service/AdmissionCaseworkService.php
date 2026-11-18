<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use DateTimeZone;
use Domain\Admissions\AdmissionCasework;
use Domain\Admissions\Enum\ApplicationStatus;
use Domain\Admissions\AdmissionChecklistPreset;
use Domain\Admissions\Repository\AdmissionCaseworkRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Admissions\Repository\AdmissionUpdatesRepository;
use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\ValueObject\CaseworkChecklist;
use Domain\Admissions\ValueObject\AdmissionChecklistProof;
use Domain\Audit\Repository\ActorDirectory;
use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

final readonly class AdmissionCaseworkService
{
    public function __construct(
        private AdmissionCaseworkRepository $cases,
        private AdmissionsRepository $admissions,
        private PersonAccountRepository $people,
        private StudentAccess $access,
        private ActorDirectory $actors,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private AccessRepository $permissions,
        private AdmissionUpdatesRepository $updates,
        private \Domain\Admissions\Repository\AdmissionPolicyRepository $policy,
    ) {
    }

    private function manager(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission('sis:admissions:manage');
        if ($expected !== null && $actor->value !== $expected->value) {
            throw new AccessDeniedException('The admissions account changed.');
        }
        return $actor;
    }
    private function forms(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission('sis:forms:manage');
        if ($expected !== null && $actor->value !== $expected->value) {
            throw new AccessDeniedException('The checklist author changed.');
        }
        return $actor;
    }
    private function canEditPresets(EntityId $actor): bool
    {
        try {
            $this->forms($actor);
            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }
    private function version(int $version): void
    {
        if ($version < 0 || $version === PHP_INT_MAX) {
            CaseworkChecklist::invalid('version', 'Reload before saving.');
        }
    }
    private function at(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /** @return array<string,mixed> */
    public function view(EntityId $application): array
    {
        $actor = $this->manager();
        $app = $this->admissions->application($application);
        $stored = $this->cases->find($application)['case'] ?? null;
        $case = $stored ?? AdmissionCasework::fromInput($application, [], null, 0);
        $name = $case->assignedTo === null ? 'Unassigned'
        : ($this->actors->names([$case->assignedTo])[$case->assignedTo->value] ?? 'Unavailable account');
        $readiness = AdmissionChecklistProof::capture($stored, $this->policy->checklist());
        $this->manager($actor);
        return ['readiness' => AdmissionChecklistProof::staff($readiness),
            'case' => $case->facts(), 'assignee_name' => $name,
            'program_id' => $app->programId->value, 'new_preset_id' => EntityId::generate()->value];
    }

    /** @return array<string,mixed> */
    public function applicant(EntityId $application): array
    {
        $actor = $this->access->authenticatedUserId();
        $this->owner($actor, $application);
        $stored = $this->cases->find($application)['case'] ?? null;
        $case = $stored ?? AdmissionCasework::fromInput($application, [], null, 0);
        $view = $case->applicantView();
        $app = $this->admissions->application($application);
        $readiness = AdmissionChecklistProof::capture($stored, $this->policy->checklist());
        $view['readiness'] = $app->status === ApplicationStatus::Submitted
        ? AdmissionChecklistProof::applicant($readiness) : null;
        $this->owner($actor, $application);
        return $view;
    }
    private function owner(EntityId $actor, EntityId $application): void
    {
        $person = $this->people->findByUser($actor);
        if (
            $this->access->authenticatedUserId()->value !== $actor->value || $person === null
            || $person->ownerUserId?->value !== $actor->value
            || $this->admissions->application($application)->personId->value !== $person->id->value
        ) {
            throw new AccessDeniedException('This account does not own the application.');
        }
    }

    /** @param array<string,mixed> $values */
    public function save(
        EntityId $application,
        #[\SensitiveParameter] array $values,
        int $version,
        string $claim,
        ?EntityId $selectedAssignee = null
    ): void {
        $actor = $this->manager();
        $this->version($version);
        if (
            !in_array($claim, ['keep', 'me', 'unassigned', 'selected'], true)
            || (($claim === 'selected') !== ($selectedAssignee !== null))
        ) {
            CaseworkChecklist::invalid('assignee_id', 'Choose an eligible colleague or another assignment option.');
        }
        $values = AdmissionCasework::fromInput($application, $values, null, $version + 1)->values;
        $route = $this->admissions->application($application);
        $command = [$actor->value, $application->value, $version, $values, $claim];
        if ($selectedAssignee !== null) {
            $command[] = $selectedAssignee->value;
        }
        $hash = hash('sha256', json_encode($command, JSON_THROW_ON_ERROR));
        $this->transactions->run(function () use (
            $route,
            $actor,
            $values,
            $version,
            $claim,
            $hash,
            $selectedAssignee
        ): void {
            if ($selectedAssignee !== null) {
                $this->permissions->lock();
            }
            $this->admissions->lockPerson($route->personId);
            $this->admissions->lockApplication($route->id);
            $this->manager($actor);
            if ($this->admissions->application($route->id)->personId->value !== $route->personId->value) {
                throw new ConcurrentModificationException('Reload this application.');
            }
            $stored = $this->cases->find($route->id);
            if (($stored['request_hash'] ?? null) === $hash) {
                $this->manager($actor);
                return;
            }
            $before = $stored['case'] ?? AdmissionCasework::fromInput($route->id, [], null, 0);
            if ($before->version !== $version) {
                throw new ConcurrentModificationException('Casework changed. Reload before saving.');
            }
            $assignee = match ($claim) {
                'me' => $actor, 'unassigned' => null, 'selected' => $selectedAssignee, default => $before->assignedTo
            };
            if ($selectedAssignee !== null) {
                $candidate = $this->people->findByUser($selectedAssignee);
                $allowed = array_flip($this->permissions->allowedUsers(
                    [$actor, $selectedAssignee],
                    'sis:admissions:manage'
                ));
                if (!isset($allowed[$actor->value])) {
                    throw new AccessDeniedException('The admissions account no longer has assignment authority.');
                }
                if (
                    $candidate?->ownerUserId?->value !== $selectedAssignee->value
                    || !isset($allowed[$selectedAssignee->value])
                ) {
                    CaseworkChecklist::invalid('assignee_id', 'Choose an enabled colleague with admissions access.');
                }
            }
            $next = AdmissionCasework::fromInput($route->id, $values, $assignee, $version + 1);
            if ($next->values !== $before->values || $next->assignedTo?->value !== $before->assignedTo?->value) {
                $at = $this->at();
                $this->cases->save($next, $version, $actor, $at, $hash);
                $beforePublic = $before->applicantView();
                $nextPublic = $next->applicantView();
                if ($this->admissions->application($route->id)->status === ApplicationStatus::Submitted) {
                    $policy = $this->policy->checklist();
                    $beforePublic['readiness'] = AdmissionChecklistProof::applicant(
                        AdmissionChecklistProof::capture($stored['case'] ?? null, $policy)
                    );
                    $nextPublic['readiness'] = AdmissionChecklistProof::applicant(
                        AdmissionChecklistProof::capture($next, $policy)
                    );
                }
                if ($nextPublic !== $beforePublic) {
                    $owner = $this->admissions->person($route->personId)->ownerUserId;
                    if ($owner !== null) {
                        $this->updates->record($route->id, $owner, $at);
                    }
                }
                $this->audit->record($actor, 'admissions.casework_updated', $route->id);
            }
            $this->manager($actor);
        });
    }

    /** @return array<string,mixed> */
    public function assignees(string $search = '', ?EntityId $after = null): array
    {
        $actor = $this->manager();
        $search = trim($search);
        if (
            !mb_check_encoding($search, 'UTF-8') || mb_strlen($search) > 80
            || preg_match('/[\x00-\x1f\x7f]/', $search)
        ) {
            CaseworkChecklist::invalid('search', 'Search with up to eighty characters.');
        }
        if (mb_strlen($search) < 2) {
            return ['items' => [], 'next_after' => null, 'search_required' => true];
        }
        $rows = $this->permissions->eligibleAccounts('sis:admissions:manage', $search, $after);
        $more = count($rows) > 20;
        $rows = array_slice($rows, 0, 20);
        $this->manager($actor);
        return ['items' => $rows, 'next_after' => $more ? end($rows)['id'] : null, 'search_required' => false];
    }

    /** @return array<string,mixed> */
    public function presets(EntityId $application, string $search = '', ?EntityId $after = null): array
    {
        $actor = $this->manager();
        $program = $this->admissions->application($application)->programId;
        if (mb_strlen($search) > 80 || preg_match('/[\x00-\x1f\x7f]/', $search)) {
            CaseworkChecklist::invalid('search', 'Search with up to eighty characters.');
        }
        $rows = $this->cases->presets($program, trim($search), $after, $this->canEditPresets($actor));
        $more = count($rows) > 20;
        $rows = array_slice($rows, 0, 20);
        $this->manager($actor);
        return ['items' => $rows, 'next_after' => $more ? end($rows)['id'] : null];
    }

    /** @return array<string,mixed> */
    public function preset(EntityId $id): array
    {
        $actor = $this->presetReader();
        $preset = $this->cases->preset($id)['preset'] ?? throw new RecordNotFoundException('Preset not found.');
        if (!$preset->enabled && !$this->canEditPresets($actor)) {
            throw new AccessDeniedException('The checklist preset is unavailable.');
        }
        $this->presetReader($actor);
        return ['id' => $preset->id->value, 'version' => $preset->version, 'values' => $preset->values()];
    }
    private function presetReader(?EntityId $expected = null): EntityId
    {
        try {
            return $this->manager($expected);
        } catch (AccessDeniedException) {
            return $this->forms($expected);
        }
    }

    /** @param array<string,mixed> $values */
    public function savePreset(EntityId $id, #[\SensitiveParameter] array $values, int $version): void
    {
        $actor = $this->forms();
        $this->version($version);
        $next = AdmissionChecklistPreset::fromInput($id, $values, $version + 1);
        if ($next->items === []) {
            CaseworkChecklist::invalid('items', 'Add at least one checklist item.');
        }
        $command = [$actor->value, $id->value, $version, $next->values()];
        $hash = hash('sha256', json_encode($command, JSON_THROW_ON_ERROR));
        $this->transactions->run(function () use ($actor, $next, $version, $hash): void {
            $this->cases->lockActor($actor);
            $before = $this->cases->preset($next->id);
            if ($before !== null) {
                $this->cases->lockPreset($next->id);
                $before = $this->cases->preset($next->id);
            }
            $this->forms($actor);
            if (($before['request_hash'] ?? null) === $hash) {
                return;
            }
            if (($before['preset']->version ?? 0) !== $version) {
                throw new ConcurrentModificationException('Checklist preset changed. Reload before saving.');
            }
            if ($next->program !== null) {
                $this->cases->requireProgram($next->program);
            }
            if ($before === null || $before['preset']->values() !== $next->values()) {
                $this->cases->savePreset($next, $version, $actor, $this->at(), $hash);
                $this->audit->record($actor, 'admissions.checklist_preset_updated', $next->id);
            }
            $this->forms($actor);
        });
    }

    /** @return array<string,mixed> */
    public function queue(string $scope = 'all', bool $dueOnly = false, ?EntityId $after = null): array
    {
        $actor = $this->manager();
        if (!in_array($scope, ['all', 'mine', 'unassigned'], true)) {
            CaseworkChecklist::invalid('scope', 'Choose all, my or unassigned casework.');
        }
        $today = $this->clock->now()->format('Y-m-d');
        $rows = $this->cases->queue(
            $scope === 'mine' ? $actor : null,
            $scope === 'unassigned',
            $dueOnly,
            $today,
            $after
        );
        $more = count($rows) > 20;
        $rows = array_slice($rows, 0, 20);
        $ids = [];
        foreach ($rows as $row) {
            if ($row['assigned_to'] !== null) {
                $ids[$row['assigned_to']] = new EntityId($row['assigned_to']);
            }
        }
        $names = $this->actors->names(array_values($ids));
        foreach ($rows as &$row) {
            $row['assignee_name'] = $row['assigned_to'] === null ? 'Unassigned'
            : ($names[$row['assigned_to']] ?? 'Unavailable account');
            unset($row['assigned_to']);
        }
        unset($row);
        $this->manager($actor);
        return ['items' => $rows, 'next_after' => $more ? end($rows)['id'] : null, 'today' => $today];
    }
}
