<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Portal\Repository\ContentAudienceRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlContentAudienceRepository implements ContentAudienceRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function roles(string $search, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        $where = [];
        $args = [];
        if ($search !== '') {
            $where[] = 'LOWER(' . $q('name') . ") LIKE ? ESCAPE '!'";
            $args[] = '%' . strtr(mb_strtolower($search), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        if ($after !== null) {
            $where[] = $q('id') . ' > ?';
            $args[] = $after->value;
        }
        return $this->sql->fetch('SELECT ' . $q('id') . ', ' . $q('name') . ' FROM '
            . $q('sis_access_roles') . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY ' . $q('id') . ' ASC', $args, 21);
    }

    public function definitions(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        if (count($ids) > 10) {
            throw new \InvalidArgumentException('Use a bounded role selection.');
        }
        $q = $this->sql->quote(...);
        return $this->sql->fetch('SELECT ' . $q('id') . ', ' . $q('name') . ' FROM '
            . $q('sis_access_roles') . ' WHERE ' . $q('id') . ' IN ('
            . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY ' . $q('id') . ' ASC', $ids, 10);
    }

    public function selected(EntityId $post): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch('SELECT ' . $q('role_id') . ' AS ' . $q('id') . ', '
            . $q('role_name') . ' AS ' . $q('name') . ' FROM ' . $q('sis_portal_audience_roles')
            . ' WHERE ' . $q('post_id') . ' = ? ORDER BY ' . $q('role_id') . ' ASC', [$post->value], 11);
        if (count($rows) > 10) {
            throw new \Domain\Shared\Exception\ConcurrentModificationException(
                \Codefy\Framework\Helpers\trans('This audience needs review before publishing.'),
            );
        }
        return $rows;
    }

    public function replace(EntityId $post, array $roles): void
    {
        if (count($roles) > 10) {
            throw new \InvalidArgumentException('Use a bounded role selection.');
        }
        $this->sql->lock('sis_portal_posts', $post->value, 'id');
        $q = $this->sql->quote(...);
        $this->sql->execute('DELETE FROM ' . $q('sis_portal_audience_roles') . ' WHERE '
            . $q('post_id') . ' = ?', [$post->value]);
        foreach ($roles as $role) {
            $this->sql->insert('sis_portal_audience_roles', ['id' => EntityId::generate()->value,
                'post_id' => $post->value, 'role_id' => $role['id'], 'role_name' => $role['name']]);
        }
    }
}
