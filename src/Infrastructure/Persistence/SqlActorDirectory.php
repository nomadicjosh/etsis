<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use Domain\Audit\Repository\ActorDirectory;
use InvalidArgumentException;

final readonly class SqlActorDirectory implements ActorDirectory
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function names(array $actors): array
    {
        if (count($actors) > 20) {
            throw new InvalidArgumentException('Use a bounded actor page.');
        }
        if ($actors === []) {
            return [];
        }
        $ids = array_values(array_unique(array_map(static fn($id): string => $id->value, $actors)));
        $q = $this->sql->quote(...);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $rows = $this->sql->fetch('SELECT ' . $q('user_id') . ', ' . $q('first_name') . ', ' . $q('last_name')
            . ' FROM ' . $q('users') . ' WHERE ' . $q('user_id') . ' IN (' . $placeholders . ')', $ids, 20);
        $names = [];
        foreach ($rows as $row) {
            $name = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
            if ($name !== '') {
                $names[(string) $row['user_id']] = $name;
            }
        }
        return $names;
    }
}
