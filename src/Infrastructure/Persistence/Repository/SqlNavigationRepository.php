<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Portal\Repository\NavigationRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlNavigationRepository implements NavigationRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function get(string $slot): array
    {
        $row = $this->sql->one('sis_navigation_menus', ['slot' => $slot]);
        $row['version'] = (int) $row['version'];
        foreach (['draft_items', 'published_items'] as $key) {
            $row[$key] = $row[$key] === null ? null : json_decode($row[$key], true, 32, JSON_THROW_ON_ERROR);
        }
        return $row;
    }

    public function lock(string $slot): void
    {
        $this->sql->lock('sis_navigation_menus', $slot, 'version', 'slot');
    }

    public function save(string $slot, array $values): void
    {
        foreach (['draft_items', 'published_items'] as $key) {
            if (isset($values[$key])) {
                $values[$key] = json_encode($values[$key], JSON_THROW_ON_ERROR);
            }
        }
        $this->sql->update('sis_navigation_menus', $values, ['slot' => $slot]);
    }

    public function receipt(EntityId $operation): ?array
    {
        $row = $this->sql->rows('sis_navigation_operations', ['id' => $operation->value], 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        unset($row['id']);
        $row['result'] = json_decode($row['result'], true, 8, JSON_THROW_ON_ERROR);
        return $row;
    }

    public function saveReceipt(EntityId $operation, array $receipt): void
    {
        $receipt['result'] = json_encode($receipt['result'], JSON_THROW_ON_ERROR);
        $this->sql->insert('sis_navigation_operations', ['id' => $operation->value] + $receipt);
    }
}
