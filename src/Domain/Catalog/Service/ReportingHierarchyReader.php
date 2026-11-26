<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use Domain\Catalog\Repository\ClassificationRepository;
use Domain\Catalog\Repository\ReportingHierarchyRepository;
use Domain\Catalog\ValueObject\ClassificationSnapshot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans_html;

final readonly class ReportingHierarchyReader
{
    public function __construct(
        private ReportingHierarchyRepository $hierarchy,
        private ClassificationRepository $references,
    ) {
    }

    /** @return list<array{id: string, code: string, title: string}> Leaf first, at most eight groups. */
    public function path(EntityId $group, ?array $lockedIds = null): array
    {
        return $this->read($group, true, $lockedIds);
    }

    /** @return list<array{id:string,code:string,title:string}> No current/locking reads while collecting locks. */
    public function snapshot(EntityId $group): array
    {
        return $this->read($group, false, null);
    }

    /** @param list<string>|null $lockedIds
     * @return list<array{id:string,code:string,title:string}> */
    private function read(EntityId $group, bool $current, ?array $lockedIds): array
    {
        $path = $seen = [];
        while ($group !== null) {
            if (count($path) >= 8 || isset($seen[$group->value])) {
                throw new CorruptHistoryException();
            }
            $seen[$group->value] = true;
            if ($lockedIds !== null && !in_array($group->value, $lockedIds, true)) {
                throw new ConcurrentModificationException(
                    trans_html('Reporting hierarchy changed. Reload the section before continuing.'),
                );
            }
            $row = $current ? $this->references->get($group) : $this->references->peek($group);
            if ($row["kind"] !== "reporting_group") {
                throw new CorruptHistoryException();
            }
            $path[] = new ClassificationSnapshot($group, $row["code"], $row["title"])->describe();
            $group = $current ? $this->hierarchy->parent($group) : $this->hierarchy->peekParent($group);
        }
        return $path;
    }
}
