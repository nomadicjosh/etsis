<?php

declare(strict_types=1);

namespace Domain\Admissions\ValueObject;

use InvalidArgumentException;

/** Presentation containers reference questions; they never become answer fields. */
final readonly class ApplicationFormLayout
{
    /** @var list<array{key:string,label:string,help:string,columns:list<list<string>>}> */
    public array $containers;

    /** @param list<mixed> $containers
     * @param list<string> $fieldKeys */
    public function __construct(array $containers, array $fieldKeys)
    {
        if (!array_is_list($containers) || count($containers) > 12) {
            throw new InvalidArgumentException('Use up to twelve form containers.');
        }
        $clean = [];
        $keys = [];
        $placed = [];
        foreach ($containers as $container) {
            if (
                !is_array($container) || !is_string($container['key'] ?? null)
                || preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $container['key']) !== 1
                || isset($keys[$container['key']]) || !is_string($container['label'] ?? null)
                || trim($container['label']) === '' || mb_strlen($container['label']) > 120
                || !is_string($container['help'] ?? '') || mb_strlen($container['help'] ?? '') > 300
                || preg_match('/[\x00-\x1f\x7f]/', $container['label'] . ($container['help'] ?? ''))
                || !is_array($container['columns'] ?? null) || !array_is_list($container['columns'])
                || count($container['columns']) < 1 || count($container['columns']) > 3
            ) {
                throw new InvalidArgumentException(
                    'A form container needs a unique key, title and one to three columns.'
                );
            }
            foreach ($container['columns'] as $column) {
                if (!is_array($column) || !array_is_list($column) || count($column) > 40) {
                    throw new InvalidArgumentException('A form column must contain question keys.');
                }
                foreach ($column as $key) {
                    if (!is_string($key)) {
                        throw new InvalidArgumentException('A form column must contain question keys.');
                    }
                    $placed[] = $key;
                    if (count($placed) > 40) {
                        throw new InvalidArgumentException('Place each of the form’s forty or fewer questions once.');
                    }
                }
            }
            $keys[$container['key']] = true;
            $clean[] = ['key' => $container['key'], 'label' => $container['label'],
                'help' => $container['help'] ?? '', 'columns' => $container['columns']];
        }
        if ($containers !== [] && $placed !== $fieldKeys) {
            throw new InvalidArgumentException(
                'Place every question exactly once, in its reading and condition order.'
            );
        }
        $this->containers = $clean;
    }
}
