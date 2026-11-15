<?php

declare(strict_types=1);

namespace Domain\Scheduling\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Scheduling\BookingCategory;
use Domain\Scheduling\Repository\BookingCategoryRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

final readonly class BookingCategoryService
{
    public function __construct(
        private BookingCategoryRepository $categories,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit
    ) {
    }

    /** @return array<string,mixed> */
    public function view(EntityId $id): array
    {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        $result = $this->categories->get($id)->details();
        $this->authorize($actor);
        return $result;
    }

    public function create(string $code, string $name, string $tone): EntityId
    {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        $category = new BookingCategory(EntityId::generate(), trim($code), trim($name), $tone);
        $this->transactions->run(function () use ($actor, $category): void {
            $this->authorize($actor);
            $this->categories->add($category);
            $this->audit->record($actor, 'booking_category.created', $category->id);
            $this->authorize($actor);
        });
        return $category->id;
    }

    public function edit(EntityId $id, string $code, string $name, string $tone, bool $active, int $version): void
    {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        $next = new BookingCategory($id, trim($code), trim($name), $tone, $active, $version + 1);
        $this->transactions->run(function () use ($actor, $next, $version): void {
            $this->categories->lock($next->id);
            $this->authorize($actor);
            if ($this->categories->get($next->id)->version !== $version) {
                throw new ConcurrentModificationException('Reload the category before editing.');
            }
            $this->categories->save($next, $version);
            $this->audit->record($actor, 'booking_category.updated', $next->id);
            $this->authorize($actor);
        });
    }

    private function authorize(EntityId $actor): void
    {
        if ($this->access->requirePermission('sis:scheduling:manage')->value !== $actor->value) {
            throw new AccessDeniedException('The scheduling session changed.');
        }
    }
}
