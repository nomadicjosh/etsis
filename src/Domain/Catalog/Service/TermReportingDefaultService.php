<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Catalog\Repository\ClassificationRepository;
use Domain\Catalog\Repository\TermReportingDefaultRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

/** An audited draft preset; changing it never reassigns a section or rewrites a publication. */
final readonly class TermReportingDefaultService
{
    public function __construct(
        private TermReportingDefaultRepository $defaults,
        private CatalogRevisionRepository $catalog,
        private ClassificationRepository $references,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function show(EntityId $term): array
    {
        $actor = $this->access->requirePermission('sis:catalog:manage');
        $result = $this->describe($term);
        $this->authorize($actor);
        return $result;
    }

    /** @return array<string,mixed> */
    public function save(EntityId $term, ?EntityId $group, string $revision): array
    {
        $actor = $this->access->requirePermission('sis:catalog:manage');
        return $this->transactions->run(function () use ($term, $group, $revision, $actor): array {
            $this->catalog->lock('terms', $term);
            $state = $this->defaults->current($term);
            $ids = array_unique(array_filter([$state['reporting_group_id'], $group?->value]));
            sort($ids, SORT_STRING);
            foreach ($ids as $id) {
                $this->references->lock(new EntityId($id));
            }
            $old = $this->describe($term);
            $this->authorize($actor);
            if (!hash_equals($old['revision'], $revision)) {
                throw new ConcurrentModificationException('Reload the term reporting-group default before saving.');
            }
            $source = $group === null ? null : $this->references->get($group);
            if (
                $source !== null && ($source['kind'] !== 'reporting_group' ||
                (!$source['active'] && $state['reporting_group_id'] !== $group->value))
            ) {
                throw new ValidationException([['field' => 'reporting_group_id',
                    'message' => 'Choose an active reporting group.']]);
            }
            $version = $state['version'] + 1;
            $this->defaults->save($term, $group, $version);
            $saved = $this->describe($term);
            $this->audit->record($actor, 'term.reporting_default_saved', $term);
            $this->authorize($actor);
            if (
                $this->defaults->current($term) !== ['reporting_group_id' => $group?->value, 'version' => $version] ||
                $this->describe($term) !== $saved ||
                ($group !== null && $this->references->get($group) !== $source)
            ) {
                throw new ConcurrentModificationException(
                    'The term reporting-group default changed before saving completed.',
                );
            }
            return $saved;
        });
    }

    /** @return array<string,mixed> */
    private function describe(EntityId $term): array
    {
        $row = $this->catalog->get('terms', $term);
        $state = $this->defaults->current($term);
        $source = $state['reporting_group_id'] === null ? null :
        $this->references->get(new EntityId($state['reporting_group_id']));
        if ($source !== null && $source['kind'] !== 'reporting_group') {
            throw new CorruptHistoryException();
        }
        return [
            'term' => ['id' => $term->value, 'code' => $row['code']],
            'reporting_group' => $source === null ? null :
                array_intersect_key($source, array_flip(['id', 'code', 'title', 'active'])),
            'revision' => hash('sha256', json_encode([$row, $state, $source], JSON_THROW_ON_ERROR)),
        ];
    }

    private function authorize(EntityId $actor): void
    {
        if ($this->access->requirePermission('sis:catalog:manage')->value !== $actor->value) {
            throw new AccessDeniedException('Access denied.');
        }
    }
}
