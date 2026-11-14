<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Catalog\Repository\ProgramSpecializationRepository;
use Domain\Catalog\Repository\SpecializationPolicyRepository;
use Domain\Catalog\ValueObject\ProgramSpecializationSnapshot;
use Domain\Catalog\ValueObject\ComponentCourseSharing;
use Domain\Catalog\ValueObject\SpecializationPolicy;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

final readonly class SpecializationPolicyService
{
    public function __construct(
        private SpecializationPolicyRepository $policies,
        private ProgramSpecializationRepository $specializations,
        private CatalogRevisionRepository $programs,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function settings(EntityId $program): array
    {
        $actor = $this->access->requirePermission('sis:catalog:manage');
        $parent = $this->programs->get('programs', $program);
        $institution = $this->policies->get(null);
        $local = $this->policies->get($program);
        $errors = [];
        $effective = null;
        try {
            $policy = $this->resolve($institution, $local);
            $effective = ['minimum' => $policy->minimum, 'maximum' => $policy->effectiveMaximum];
        } catch (ValidationException $e) {
            $errors = $e->errors;
        }
        try {
            $manage = $this->access->requirePermission('sis:catalog:policy')->value === $actor->value;
        } catch (AccessDeniedException) {
            $manage = false;
        }
        $this->authorize($actor, 'sis:catalog:manage');
        return [
            'program' => ['id' => $program->value, 'code' => $parent['code'], 'title' => $parent['title']],
            'institution' => $institution,
            'settings' => $local,
            'effective' => $effective,
            'errors' => $errors,
            'can_manage_institution' => $manage,
        ];
    }

    public function saveProgram(
        EntityId $program,
        int $revision,
        int $minimum,
        ?int $maximum,
        string $componentSharing = ComponentCourseSharing::OneComponent->value,
    ): void {
        $actor = $this->access->requirePermission('sis:catalog:manage');
        if (ComponentCourseSharing::tryFrom($componentSharing) === null) {
            throw new ValidationException([['field' => 'component_sharing',
                'message' => 'Choose whether completed courses may satisfy more than one curriculum component.',
            ]]);
        }
        $this->transactions->run(function () use (
            $program,
            $revision,
            $minimum,
            $maximum,
            $componentSharing,
            $actor,
        ): void {
            $this->policies->lockInstitution();
            $this->programs->lock('programs', $program);
            $this->authorize($actor, 'sis:catalog:manage');
            $old = $this->policies->get($program);
            $this->revision($revision, $old['revision']);
            $local = [
                'revision' => $revision + 1,
                'minimum' => $minimum,
                'maximum' => $maximum,
                'default_maximum' => null,
                'component_sharing' => $componentSharing,
            ];
            $this->resolve($this->policies->get(null), $local);
            $this->policies->save($program, $local);
            $this->audit->record($actor, 'program.specialization_policy_saved', $program);
        });
    }

    public function saveInstitution(int $revision, int $maximum, int $default): void
    {
        $actor = $this->access->requirePermission('sis:catalog:policy');
        new SpecializationPolicy($maximum, $default);
        $this->transactions->run(function () use ($revision, $maximum, $default, $actor): void {
            $this->policies->lockInstitution();
            $this->authorize($actor, 'sis:catalog:policy');
            $this->revision($revision, $this->policies->get(null)['revision']);
            $this->policies->save(null, [
                'revision' => $revision + 1,
                'minimum' => 0,
                'maximum' => $maximum,
                'default_maximum' => $default,
            ]);
            $this->audit->record(
                $actor,
                'institution.specialization_policy_saved',
                new EntityId(SpecializationPolicyRepository::INSTITUTION_ID),
            );
        });
    }

    /** Publication lock order: institution, program, academic year, classifications. */
    public function lockInstitution(): void
    {
        $this->policies->lockInstitution();
    }

    /** Caller owns the publication locks/authority. No new authorization or current-state fallback in replay. */
    public function snapshot(EntityId $program): ProgramSpecializationSnapshot
    {
        $institution = $this->policies->get(null);
        $local = $this->policies->get($program);
        $policy = $this->resolve($institution, $local);
        $offered = $this->specializations->offered($program);
        if ($policy->minimum > count($offered)) {
            throw new ValidationException([
                [
                    'field' => 'minimum',
                    'message' =>
                        'Offer enough active specializations to meet the required number before publishing.',
                ],
            ]);
        }
        $snapshot = [
            'program_id' => $program->value,
            'institution' => [
                'revision' => $institution['revision'],
                'maximum' => $institution['maximum'],
                'default_maximum' => $institution['default_maximum'],
            ],
            'program' => [
                'revision' => $local['revision'],
                'minimum' => $local['minimum'],
                'maximum' => $local['maximum'],
            ],
            'effective' => ['minimum' => $policy->minimum, 'maximum' => $policy->effectiveMaximum],
            'offered' => $offered,
            'component_sharing' => $local['component_sharing'] ??
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException(),
        ];
        if (strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)) > 49152) {
            throw new ValidationException([
                ['field' => 'specializations',
                    'message' => 'Shorten specialization descriptions to fit one program publication.'],
            ]);
        }
        return new ProgramSpecializationSnapshot($snapshot);
    }

    /** @param array{revision:int,minimum:int,maximum:?int,default_maximum:?int,component_sharing?:?string} $institution
     * @param array{revision:int,minimum:int,maximum:?int,default_maximum:?int,component_sharing?:?string} $local */
    private function resolve(array $institution, array $local): SpecializationPolicy
    {
        if ($institution['maximum'] === null || $institution['default_maximum'] === null) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        return new SpecializationPolicy(
            $institution['maximum'],
            $institution['default_maximum'],
            $local['minimum'],
            $local['maximum'],
        );
    }
    private function revision(int $expected, int $actual): void
    {
        if ($expected < 0 || $expected >= 2000000000 || $expected !== $actual) {
            throw new ConcurrentModificationException('Reload the specialization policy before saving.');
        }
    }
    private function authorize(EntityId $actor, string $permission): void
    {
        if ($this->access->requirePermission($permission)->value !== $actor->value) {
            throw new AccessDeniedException('Access denied.');
        }
    }
}
