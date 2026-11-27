<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Registration\ValueObject\CourseAuditing;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

/** Catalog working policy. The existing reviewed offering publication makes it authoritative. */
final readonly class CourseAuditingPolicyService
{
    public function __construct(
        private CatalogRevisionRepository $catalog,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    public function current(EntityId $section): array
    {
        $actor = $this->access->requirePermission('sis:catalog:manage');
        return $this->transactions->run(function () use ($section, $actor): array {
            $this->catalog->lock('sections', $section);
            $row = $this->catalog->get('sections', $section);
            $result = $this->describe($row);
            $this->actor($actor);
            return $result;
        });
    }

    public function save(EntityId $section, ?CourseAuditing $policy, string $revision): array
    {
        $actor = $this->access->requirePermission('sis:catalog:manage');
        return $this->transactions->run(function () use ($section, $policy, $revision, $actor): array {
            $this->catalog->lock('sections', $section);
            $row = $this->catalog->get('sections', $section);
            if (!hash_equals($this->describe($row)['revision'], $revision)) {
                throw new ConcurrentModificationException(\Codefy\Framework\Helpers\trans(
                    'The auditing policy changed. Reload it before saving.'
                ));
            }
            $value = $policy === null ? null : json_encode($policy->facts(), JSON_THROW_ON_ERROR);
            $this->catalog->save('sections', $section, ['auditing' => $value]);
            $this->audit->record($actor, 'section.auditing_policy_saved', $section);
            $this->actor($actor);
            $saved = $this->catalog->get('sections', $section);
            if ($saved['auditing'] !== $value || $saved['currency'] !== $row['currency']) {
                throw new ConcurrentModificationException(\Codefy\Framework\Helpers\trans(
                    'The auditing policy changed while saving.'
                ));
            }
            return $this->describe($saved);
        });
    }

    private function describe(array $row): array
    {
        $policy = CourseAuditing::stored($row['auditing']);
        return ['section_code' => $row['code'], 'currency' => $row['currency'],
            'policy' => $policy?->facts(), 'revision' => hash('sha256', json_encode([
                $row['id'], $row['currency'], $policy?->facts(),
            ], JSON_THROW_ON_ERROR))];
    }

    private function actor(EntityId $actor): void
    {
        if ($this->access->requirePermission('sis:catalog:manage')->value !== $actor->value) {
            throw new AccessDeniedException('Access denied.');
        }
    }
}
