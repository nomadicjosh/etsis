<?php

declare(strict_types=1);

namespace Domain\Reporting\ValueObject;

use Domain\Shared\Exception\ValidationException;

/** Expand named copies of approved relationship branches, preserving every source and field restriction. */
final class ReportGraph
{
    /** @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    public static function expand(array $schema, mixed $copies): array
    {
        if (!is_array($copies) || count($copies) > 3) {
            self::invalid('Add up to three named record copies.');
        }
        $base = $schema;
        foreach ($copies as $id => $copy) {
            if (
                !is_string($id) || preg_match('/^copy[1-3]$/D', $id) !== 1 ||
                isset($base['relationships'][$id]) || !is_array($copy) ||
                array_diff(array_keys($copy), ['relation', 'label']) !== [] ||
                !is_string($copy['relation'] ?? null) || !isset($base['relationships'][$copy['relation']]) ||
                !is_string($copy['label'] ?? null) || trim($copy['label']) === '' ||
                mb_strlen($copy['label']) > 48 || !mb_check_encoding($copy['label'], 'UTF-8') ||
                preg_match('/[\x00-\x1F\x7F]/', $copy['label'])
            ) {
                self::invalid('Choose an approved record and a readable copy name of up to 48 characters.');
            }
            $origin = $copy['relation'];
            $mapped = [$origin => $id];
            foreach ($base['relationships'] as $name => $relation) {
                for (
                    $current = $name; $current !== $base['root'];
                    $current = $base['relationships'][$current]['parent']
                ) {
                    if ($current === $origin && $name !== $origin) {
                        $mapped[$name] = $id . '__' . $name;
                        break;
                    }
                }
            }
            foreach ($mapped as $name => $alias) {
                if (isset($schema['relationships'][$alias])) {
                    self::invalid('This record copy conflicts with an existing relationship.');
                }
                $relation = $base['relationships'][$name];
                $schema['relationships'][$alias] = array_replace($relation, [
                    'parent' => $mapped[$relation['parent']] ?? $relation['parent'],
                    'label' => $name === $origin ? $copy['label'] : $copy['label'] . ' · ' . $relation['label'],
                    'copy_of' => $name, 'copy_group' => $id,
                ]);
            }
            foreach ($base['fields'] as $field => $metadata) {
                if (isset($mapped[$metadata['relation']])) {
                    if (isset($schema['fields'][$id . '__' . $field])) {
                        self::invalid('This record copy conflicts with an existing field.');
                    }
                    $schema['fields'][$id . '__' . $field] = array_replace($metadata, [
                        'relation' => $mapped[$metadata['relation']], 'copy_group' => $id,
                    ]);
                    if (isset($metadata['money_grain'])) {
                        $schema['fields'][$id . '__' . $field]['money_grain'] =
                        $mapped[$metadata['money_grain']] ?? $metadata['money_grain'];
                    }
                }
            }
        }
        if (count($schema['relationships']) > 64) {
            self::invalid('This report graph exceeds its relationship limit.');
        }
        return $schema;
    }
    private static function invalid(string $message): never
    {
        throw new ValidationException([['field' => 'aliases', 'message' => $message]]);
    }
}
