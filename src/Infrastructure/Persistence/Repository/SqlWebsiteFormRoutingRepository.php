<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\ValueObject\EntityId;
use Domain\WebsiteForms\Repository\WebsiteFormRoutingRepository;
use Domain\WebsiteForms\Service\WebsiteFormCatalog;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlWebsiteFormRoutingRepository implements WebsiteFormRoutingRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function permits(EntityId $user, string $kind, string $scope, string $key): bool
    {
        return $this->grant($kind, $scope, $key, $user) !== null;
    }

    public function departments(string $search, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            'SELECT ' . implode(', ', array_map($q, ['id', 'code', 'title']))
            . ' FROM ' . $q('sis_catalog_classifications') . ' WHERE ' . $q('kind') . " = 'department' AND "
            . $q('active') . ' = 1 AND (' . $q('code') . ' LIKE ? OR ' . $q('title') . ' LIKE ?)'
            . ($after === null ? '' : ' AND ' . $q('id') . ' > ?') . ' ORDER BY ' . $q('id'),
            ['%' . $search . '%', '%' . $search . '%', ...$after === null ? [] : [$after->value]],
            21
        );
    }

    public function pages(string $search, ?string $after = null): array
    {
        $q = $this->sql->quote(...);
        $source = 'SELECT ' . $this->pageIdentity($q('id')) . ' AS ' . $q('id')
        . ', ' . $q('name') . ' FROM ' . $q('pages')
        . ' UNION SELECT ' . $q('page_id') . ', MIN(' . $q('page_title') . ') AS ' . $q('name')
        . ' FROM ' . $q('sis_website_form_inbox') . ' WHERE NOT EXISTS (SELECT 1 FROM '
        . $q('pages') . ' WHERE ' . $this->pageIdentity($q('pages') . '.' . $q('id')) . ' = '
        . $q('sis_website_form_inbox') . '.' . $q('page_id') . ') GROUP BY ' . $q('page_id');
        return $this->sql->fetch('SELECT * FROM (' . $source . ') ' . $q('choices')
            . ' WHERE ' . $q('name') . ' LIKE ?' . ($after === null ? '' : ' AND ' . $q('id') . ' > ?')
            . ' ORDER BY ' . $q('id'), ['%' . $search . '%', ...$after === null ? [] : [$after]], 21);
    }

    private function pageIdentity(string $column): string
    {
        $type = match ($this->sql->driver()) {
            'mysql' => 'CHAR', 'oci' => 'VARCHAR2', default => 'VARCHAR',
        };
        return 'CAST(' . $column . ' AS ' . $type . '(191))';
    }

    public function forms(string $page): array
    {
        $q = $this->sql->quote(...);
        $row = $this->sql->fetch('SELECT * FROM ' . $q('pages') . ' WHERE '
            . $this->pageIdentity($q('id')) . ' = ?', [$page], 1)[0] ?? null;
        $forms = [];
        if ($row !== null && is_string($row['data']) && strlen($row['data']) <= 1048576) {
            try {
                $data = json_decode($row['data'], true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $data = [];
            }
            foreach (array_slice(array_keys($data['blocks'] ?? []), 0, 20) as $locale) {
                try {
                    $definitions = WebsiteFormCatalog::definitions(['page' => $row], $locale);
                } catch (\Domain\Shared\Exception\ValidationException) {
                    // An unfinished invalid draft cannot hide access setup for retained inquiries below.
                    continue;
                }
                foreach ($definitions as $key => $definition) {
                    $forms[$key] = ['key' => $key, 'title' => $definition->facts['title'],
                        'department_id' => $definition->facts['department_id']];
                }
            }
        }
        // Retained inquiries remain assignable after a page or form was removed from the website.
        $q = $this->sql->quote(...);
        foreach (
            $this->sql->fetch('SELECT DISTINCT ' . $q('form_key') . ', ' . $q('form_title')
            . ', ' . $q('department_id') . ' FROM ' . $q('sis_website_form_inbox') . ' WHERE '
            . $q('page_id') . ' = ? ORDER BY ' . $q('form_key'), [$page], 100) as $old
        ) {
            $forms[$old['form_key']] ??= ['key' => $old['form_key'], 'title' => $old['form_title'],
                'department_id' => $old['department_id']];
        }
        return array_values($forms);
    }

    public function members(string $kind, string $scope, string $key, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            'SELECT ' . $q('g') . '.*, ' . $q('p') . '.' . $q('given_name')
            . ', ' . $q('p') . '.' . $q('family_name') . ' FROM ' . $q('sis_website_form_access') . ' ' . $q('g')
            . ' LEFT JOIN ' . $q('sis_persons') . ' ' . $q('p') . ' ON ' . $q('p') . '.' . $q('owner_user_id')
            . ' = ' . $q('g') . '.' . $q('user_id') . ' WHERE ' . $q('g') . '.' . $q('scope_kind') . ' = ? AND '
            . $q('g') . '.' . $q('scope_id') . ' = ? AND ' . $q('g') . '.' . $q('form_key') . ' = ?'
            . ($after === null ? '' : ' AND ' . $q('g') . '.' . $q('id') . ' > ?')
            . ' ORDER BY ' . $q('g') . '.' . $q('id'),
            [$kind, $scope, $key, ...$after === null ? [] : [$after->value]],
            21
        );
    }

    public function grant(string $kind, string $scope, string $key, EntityId $user): ?array
    {
        return $this->sql->rows('sis_website_form_access', ['scope_kind' => $kind, 'scope_id' => $scope,
            'form_key' => $key, 'user_id' => $user->value], 1)[0] ?? null;
    }

    public function save(string $kind, string $scope, string $key, EntityId $user, bool $grant): EntityId
    {
        $old = $this->grant($kind, $scope, $key, $user);
        $id = new EntityId($old['id'] ?? EntityId::generate()->value);
        if ($grant) {
            $this->sql->insert('sis_website_form_access', ['id' => $id->value, 'scope_kind' => $kind,
                'scope_id' => $scope, 'form_key' => $key, 'user_id' => $user->value, 'version' => 0]);
        } else {
            $q = $this->sql->quote(...);
            $this->sql->execute(
                'DELETE FROM ' . $q('sis_website_form_access') . ' WHERE ' . $q('id') . ' = ?',
                [$id->value]
            );
        }
        return $id;
    }
}
