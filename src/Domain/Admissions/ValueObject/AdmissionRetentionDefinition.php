<?php

declare(strict_types=1);

namespace Domain\Admissions\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** Operational category definitions; each accepted file keeps its own selected policy snapshot. */
final readonly class AdmissionRetentionDefinition
{
    public const int LIMIT = 25;

    public function __construct(public array $categories = [], public ?string $defaultId = null)
    {
        if (!array_is_list($categories) || count($categories) > self::LIMIT) {
            self::invalid();
        }
        $ids = $codes = [];
        $defaultFound = $defaultId === null;
        foreach ($categories as $item) {
            if (
                !is_array($item) || count($item) !== 5
                || !is_string($item['id'] ?? null) || !is_string($item['code'] ?? null)
                || !is_string($item['title'] ?? null) || !is_int($item['days'] ?? null)
                || !is_bool($item['active'] ?? null)
                || preg_match('/^[A-Z][A-Z0-9._-]{0,29}$/D', $item['code']) !== 1
                || trim($item['title']) !== $item['title'] || $item['title'] === ''
                || mb_strlen($item['title']) > 120 || !mb_check_encoding($item['title'], 'UTF-8')
                || preg_match('/[\x00-\x1f\x7f]/', $item['title']) || $item['days'] < 1 || $item['days'] > 3650
                || isset($ids[$item['id']]) || isset($codes[$item['code']])
            ) {
                self::invalid();
            }
            new EntityId($item['id']);
            $ids[$item['id']] = $codes[$item['code']] = true;
            if ($item['id'] === $defaultId && $item['active']) {
                $defaultFound = true;
            }
        }
        if (!$defaultFound) {
            self::invalid();
        }
    }

    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 2 || !is_array($data['categories'] ?? null)
            || !array_key_exists('default_id', $data)
            || ($data['default_id'] !== null && !is_string($data['default_id']))
        ) {
            self::invalid();
        }
        return new self($data['categories'], $data['default_id']);
    }

    public function data(): array
    {
        return ['categories' => array_map(static fn(array $item): array => [
            'id' => $item['id'], 'code' => $item['code'], 'title' => $item['title'],
            'days' => $item['days'], 'active' => $item['active'],
        ], $this->categories), 'default_id' => $this->defaultId];
    }

    public function retains(self $previous): void
    {
        $current = array_column($this->categories, null, 'id');
        foreach ($previous->categories as $item) {
            if (!isset($current[$item['id']]) || $current[$item['id']]['code'] !== $item['code']) {
                throw new ValidationException([['field' => 'categories',
                    'message' => 'Keep existing category identities and codes. '
                        . 'Retire a category instead of removing it.']]);
            }
        }
    }

    public function category(string $id): array
    {
        foreach ($this->categories as $item) {
            if ($item['id'] === $id && $item['active']) {
                return $item;
            }
        }
        self::invalid();
    }

    private static function invalid(): never
    {
        throw new ValidationException([['field' => 'categories',
            'message' => 'Use up to 25 distinct categories, valid codes, titles and 1–3,650 retention days. '
                . 'Choose an active default or the deployment fallback.']]);
    }
}
