<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\ProgramSpecialization;
use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Catalog\Repository\ProgramSpecializationRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class ProgramSpecializationService
{
    public function __construct(
        private ProgramSpecializationRepository $specializations,
        private CatalogRevisionRepository $programs,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function page(EntityId $program, string $search = '', ?EntityId $after = null): array
    {
        $actor = $this->access->requirePermission('sis:catalog:manage');
        if (
            mb_strlen($search) > 100 ||
            !mb_check_encoding($search, 'UTF-8') ||
            preg_match('/[\x00-\x1F\x7F]/', $search)
        ) {
            throw new InvalidArgumentException('Use a shorter specialization search.');
        }
        $parent = $this->programs->get('programs', $program);
        $items = $this->specializations->page($program, trim($search), $after);
        $this->authorize($actor);
        return [
            'program' => ['id' => $program->value, 'code' => $parent['code'], 'title' => $parent['title']],
            'items' => array_slice($items, 0, 20),
            'next_after' => count($items) > 20 ? $items[19]['id'] : null,
        ];
    }

    /** @return array<string,scalar|null> */
    public function get(EntityId $program, EntityId $id): array
    {
        $actor = $this->access->requirePermission('sis:catalog:manage');
        $this->programs->get('programs', $program);
        $value = $this->specializations->get($program, $id);
        $this->authorize($actor);
        return $value->describe();
    }

    /** Nullable identity/revision are required command inputs; null explicitly requests a new reference.
     * @return array<string,scalar|null> */
    public function save(
        EntityId $program,
        ?EntityId $id,
        ?int $expectedRevision,
        string $code,
        string $title,
        ?string $description,
        bool $active,
    ): array {
        $actor = $this->access->requirePermission('sis:catalog:manage');
        if (
            ($id === null) !== ($expectedRevision === null) ||
            ($expectedRevision !== null && ($expectedRevision < 1 || $expectedRevision >= 2000000000))
        ) {
            throw new InvalidArgumentException('Supply the saved revision when editing a specialization.');
        }
        foreach (['code' => $code, 'title' => $title, 'description' => $description] as $field => $text) {
            $pattern = $field === 'description' ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
            if ($text !== null && preg_match($pattern, $text)) {
                throw new \Domain\Shared\Exception\ValidationException([
                    ['field' => $field, 'message' => 'Remove unsupported control characters.'],
                ]);
            }
        }
        $value = new ProgramSpecialization(
            $id ?? EntityId::generate(),
            $program,
            strtoupper(trim($code)),
            trim($title),
            $description === null || trim($description) === '' ? null : trim($description),
            $active,
            ($expectedRevision ?? 0) + 1,
        );
        return $this->transactions->run(function () use (
            $program,
            $id,
            $expectedRevision,
            $actor,
            $value,
        ): array {
            $this->programs->lock('programs', $program);
            $this->authorize($actor);
            if ($id !== null && $this->specializations->get($program, $id)->revision !== $expectedRevision) {
                throw new ConcurrentModificationException(
                    'Reload this specialization before saving your changes.',
                );
            }
            $this->specializations->save($value, $id === null);
            $this->audit->record(
                $actor,
                $id === null ? 'program.specialization_created' : 'program.specialization_updated',
                $value->id,
            );
            return $value->describe();
        });
    }

    private function authorize(EntityId $actor): void
    {
        if ($this->access->requirePermission('sis:catalog:manage')->value !== $actor->value) {
            throw new AccessDeniedException('Access denied.');
        }
    }
}
