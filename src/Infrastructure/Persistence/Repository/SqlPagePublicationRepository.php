<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Portal\Repository\PagePublicationRepository;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlPagePublicationRepository implements PagePublicationRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function lock(string $page): void
    {
        $this->sql->lock('pages', $page, 'name');
    }

    public function source(string $page): array
    {
        $row = $this->sql->one('pages', ['id' => $page]);
        $translations = $this->sql->rows('page_translations', ['page_id' => $page], 21);
        if (count($translations) > 20 || strlen((string) $row['data']) > 1048576) {
            throw new ValidationException([['field' => 'page',
                'message' => 'This page exceeds the supported publication review size.']]);
        }
        usort($translations, static fn (array $a, array $b): int => strcmp($a['locale'], $b['locale']));
        $normalize = static function (array $values): array {
            ksort($values);
            return array_map(static fn (mixed $value): ?string => $value === null ? null : (string) $value, $values);
        };
        return ['page' => $normalize($row), 'translations' => array_map($normalize, $translations)];
    }

    public function state(string $page): ?array
    {
        $row = $this->sql->rows('sis_page_publications', ['page_id' => $page], 1)[0] ?? null;
        if ($row !== null) {
            $row['version'] = (int) $row['version'];
            ksort($row);
        }
        return $row;
    }

    public function save(string $page, array $state): void
    {
        if ($this->state($page) === null) {
            $this->sql->insert('sis_page_publications', $state);
        } else {
            $this->sql->update('sis_page_publications', $state, ['page_id' => $page]);
        }
    }

    public function receipt(EntityId $operation): ?array
    {
        $row = $this->sql->rows('sis_page_publication_operations', ['id' => $operation->value], 1)[0] ?? null;
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
        $this->sql->insert('sis_page_publication_operations', ['id' => $operation->value] + $receipt);
    }

    public function directory(?string $after = null): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            'SELECT ' . $q('id') . ', ' . $q('name') . ' FROM ' . $q('pages')
            . ($after === null ? '' : ' WHERE ' . $q('id') . ' > ?') . ' ORDER BY ' . $q('id'),
            $after === null ? [] : [$after],
            21
        );
        $more = count($rows) > 20;
        return ['items' => array_slice($rows, 0, 20), 'next_after' => $more ? (string) $rows[19]['id'] : null];
    }

    public function navigationCandidates(): array
    {
        $rows = $this->sql->rows('sis_page_publications', ['status' => 'published'], 101);
        // Do not expose a partial menu when an institution exceeds the qualified navigation bound.
        return count($rows) > 100 ? [] : array_column($rows, 'page_id');
    }

    public function routes(): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch('SELECT ' . $q('id') . ', ' . $q('route') . ' FROM '
            . $q('page_translations') . ' ORDER BY ' . $q('id'), [], 1000);
        return count($rows) === 1000 ? [] : $rows;
    }
}
