<?php

declare(strict_types=1);

namespace Domain\Portal\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Catalog\Repository\CatalogYearRepository;
use Domain\Catalog\Repository\ProgramDefinitionRepository;
use Domain\Catalog\Service\ProgramPublicationService;
use Domain\Portal\Repository\CampusRepository;
use Domain\Portal\Repository\ProgramDiscoveryRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Explicit website visibility is working publishing state, separate from immutable academic facts. */
final readonly class ProgramDiscoveryService
{
    public function __construct(
        private ProgramDiscoveryRepository $listings,
        private CatalogRevisionRepository $programs,
        private CatalogYearRepository $catalogs,
        private ProgramDefinitionRepository $definitions,
        private ProgramPublicationService $publications,
        private CampusRepository $campus,
        private StudentAccess $access,
        private AccessRepository $permissions,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs,
    ) {
    }

    public function page(string $search = '', ?EntityId $after = null): array
    {
        if (
            !mb_check_encoding($search, 'UTF-8') || mb_strlen($search) > 80
            || preg_match('/[\x00-\x1F\x7F]/', $search)
        ) {
            throw new InvalidArgumentException('Use a program code or title up to eighty characters.');
        }
        $rows = $this->listings->page(trim($search), $after);
        return ['items' => array_slice($rows, 0, 12), 'next_after' => count($rows) > 12 ? $rows[11]['id'] : null];
    }

    public function detail(EntityId $program): array
    {
        $row = $this->listings->state($program);
        if ($row === null || !$this->isVisible($row) || $row['catalog_id'] === null) {
            throw new RecordNotFoundException('Program unavailable in the public catalog.');
        }
        $release = $this->catalogs->release(new EntityId($row['catalog_id']));
        $source = $this->definitions->retained(new EntityId($row['catalog_id']));
        if (
            $source === null || $source['program_id'] !== $program->value || !(bool) $release['published']
            || !hash_equals($row['publication_hash'], $this->hash($source))
        ) {
            throw new RecordNotFoundException('Program unavailable in the public catalog.');
        }
        $result = $this->display($program, $source);
        $result['application_available'] = $this->applicationAvailable($program);
        if (
            $this->listings->state($program) != $row
            || $this->definitions->retained(new EntityId($row['catalog_id'])) != $source
        ) {
            throw new RecordNotFoundException('The public program changed. Reload the catalog.');
        }
        return $result;
    }

    public function current(EntityId $program): array
    {
        $actor = $this->authorize();
        $base = $this->programs->get('programs', $program);
        $row = $this->listings->state($program);
        $this->authorize($actor);
        return ['program' => ['id' => $program->value, 'code' => $base['code'], 'title' => $base['title']],
            'version' => $row === null ? 0 : (int) $row['version'], 'visible' => $this->isVisible($row),
            'catalog_id' => $row['catalog_id'] ?? null];
    }

    public function choices(EntityId $program, ?EntityId $after = null): array
    {
        $actor = $this->authorize();
        $this->programs->get('programs', $program);
        $rows = $this->listings->choices($program, $after);
        $more = count($rows) > 50;
        $rows = array_map(static fn (array $r): array => [
            'id' => $r['id'], 'year_code' => $r['year_code'], 'revision' => (int) $r['revision'],
            'title' => $r['program_title'],
        ], array_slice($rows, 0, 50));
        $this->authorize($actor);
        return ['items' => $rows, 'next_after' => $more ? $rows[49]['id'] : null];
    }

    public function review(EntityId $program, ?EntityId $catalog, bool $visible, int $expected): array
    {
        $actor = $this->authorize();
        $plan = $this->plan($program, $catalog, $visible, $expected);
        $revision = $this->hash($plan);
        $operation = EntityId::generate();
        $proof = $this->proofs->issue('program-discovery', 'publish', $program, $actor, $operation, $revision);
        $this->authorize($actor);
        return ['operation_id' => $operation->value, 'revision' => $revision,
            'review_token' => $proof,
            'program' => $plan['source'] === null ? null : $this->display($program, $plan['source']),
            'visible' => $visible, 'catalog_id' => $catalog?->value, 'version' => $expected];
    }

    public function commit(
        EntityId $program,
        ?EntityId $catalog,
        bool $visible,
        int $expected,
        EntityId $operation,
        string $revision,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->authorize();
        return $this->transactions->run(function () use (
            $program,
            $catalog,
            $visible,
            $expected,
            $operation,
            $revision,
            $token,
            $actor,
        ): array {
            $this->permissions->lock();
            $this->catalogs->lockProgram($program);
            $this->authorize($actor);
            $row = $this->listings->state($program);
            $request = $this->hash([$catalog?->value, $visible, $expected, $revision]);
            $accepted = ($row['operation_id'] ?? null) === $operation->value;
            $this->proofs->validate(
                $token,
                'program-discovery',
                'publish',
                $program,
                $actor,
                $operation,
                $revision,
                $accepted,
            );
            if ($accepted) {
                if ($row['actor_id'] !== $actor->value || !hash_equals($row['request_hash'], $request)) {
                    throw new ConcurrentModificationException('This publication belongs to another catalog command.');
                }
                return $this->current($program);
            }
            $plan = $this->plan($program, $catalog, $visible, $expected);
            if (!hash_equals($revision, $this->hash($plan))) {
                throw new ConcurrentModificationException('The catalog listing changed. Review it again.');
            }
            $values = ['catalog_id' => $catalog?->value,
                'publication_hash' => $plan['source'] === null ? null : $this->hash($plan['source']),
                'visible' => (int) $visible, 'version' => $expected + 1, 'operation_id' => $operation->value,
                'request_hash' => $request, 'actor_id' => $actor->value,
                'updated_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z')];
            $this->listings->save($program, $values);
            $this->audit->record($actor, $visible ? 'portal.program_published' : 'portal.program_hidden', $program);
            $this->authorize($actor);
            if (
                $this->listings->state($program) != ['id' => $program->value] + $values
                || ($catalog !== null && $this->source($program, $catalog) != $plan['source'])
            ) {
                throw new ConcurrentModificationException('The catalog source changed before confirmation completed.');
            }
            $this->authorize($actor);
            return $this->current($program);
        });
    }

    private function plan(EntityId $program, ?EntityId $catalog, bool $visible, int $expected): array
    {
        $this->programs->get('programs', $program);
        $state = $this->listings->state($program);
        if ($expected < 0 || $expected > 2147483645 || (int) ($state['version'] ?? 0) !== $expected) {
            throw new ConcurrentModificationException('Reload the website catalog settings.');
        }
        if ($visible && $catalog === null) {
            throw new InvalidArgumentException('Choose a published academic program definition first.');
        }
        return ['state' => $state, 'visible' => $visible,
            'source' => $catalog === null ? null : $this->source($program, $catalog)];
    }

    private function source(EntityId $program, EntityId $catalog): array
    {
        $release = $this->catalogs->release($catalog);
        if (!(bool) $release['published'] || $release['program_id'] !== $program->value) {
            throw new RecordNotFoundException('Choose a published definition of this program.');
        }
        return $this->publications->published($program, $catalog);
    }

    private function display(EntityId $program, array $source): array
    {
        $definition = $source['definition'];
        $labels = [];
        foreach (['academic_level', 'award', 'school', 'field_of_study', 'campus'] as $kind) {
            $value = $definition['classifications'][$kind] ?? null;
            $labels[$kind] = $value === null ? null : ['code' => $value['code'], 'title' => $value['title']];
        }
        $offered = array_column($source['specializations']['offered'], null, 'id');
        $publicChoice = static fn (string $id): array => [
            'code' => $offered[$id]['code'], 'title' => $offered[$id]['title'],
        ];
        $incompatible = null;
        if (array_key_exists('component_exclusions', $source['specializations'])) {
            $incompatible = array_map(
                static fn (array $pair): array => array_map($publicChoice, $pair),
                $source['specializations']['component_exclusions']
            );
        }
        return ['id' => $program->value, 'code' => $definition['code'], 'title' => $definition['title'],
            'description' => $definition['description'], 'academic_year_code' => $source['academic_year_code'],
            'classifications' => $labels,
            'additional_campuses' => array_map(static fn (array $r): array => [
                'code' => $r['code'], 'title' => $r['title'],
            ], $definition['classifications']['additional_campuses'] ?? []),
            'components' => array_map(static fn (array $r): array => [
                'code' => $r['code'], 'title' => $r['title'], 'kind' => $r['kind'] ?? 'specialization',
            ], $source['specializations']['offered']),
            'selection_limits' => $source['specializations']['kind_limits'] ?? null,
            'incompatible_components' => $incompatible];
    }

    private function applicationAvailable(EntityId $program): bool
    {
        foreach ($this->campus->programs($program->value) as $item) {
            if ($item['id'] === $program->value) {
                return $item['form_id'] !== null;
            }
        }
        return false;
    }

    private function isVisible(?array $row): bool
    {
        return in_array($row['visible'] ?? false, [true, 1, '1', 't'], true);
    }

    private function authorize(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission('sis:catalog:manage');
        foreach (['sis:portal:manage', 'sis:portal:publish'] as $permission) {
            if ($this->access->requirePermission($permission)->value !== $actor->value) {
                throw new AccessDeniedException();
            }
        }
        if (
            ($expected !== null && $expected->value !== $actor->value)
            || $this->access->authenticatedUserId()->value !== $actor->value
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    private function hash(array $value): string
    {
        $sort = static function (mixed $v) use (&$sort): mixed {
            if (!is_array($v)) {
                return $v;
            }
            if (!array_is_list($v)) {
                ksort($v, SORT_STRING);
            }
            return array_map($sort, $v);
        };
        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR));
    }
}
