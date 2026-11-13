<?php

declare(strict_types=1);

namespace Domain\Rules;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use JsonException;

/** A complete selected authoring revision, retained inside an academic group publication. */
final readonly class SavedCourseRule
{
    public CourseEligibilityRule $rule;

    /**
     * @param array<string,mixed> $snapshot
     * @throws JsonException
     */
    public function __construct(public array $snapshot)
    {
        $keys = ['id', 'rule_id', 'revision', 'code', 'title', 'created_at', 'definition', 'definition_hash'];
        if (count($snapshot) !== count($keys) || array_diff(array_keys($snapshot), $keys) !== []) {
            self::invalid();
        }
        foreach (['id', 'rule_id'] as $key) {
            if (!is_string($snapshot[$key])) {
                self::invalid();
            }
            new EntityId($snapshot[$key]);
        }
        foreach (['code' => 80, 'title' => 191, 'created_at' => 27, 'definition_hash' => 64] as $key => $maximum) {
            $value = $snapshot[$key];
            if (
                !is_string($value) || trim($value) === '' || mb_strlen($value) > $maximum ||
                !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $value)
            ) {
                self::invalid();
            }
        }
        $definition = $snapshot['definition'];
        if (
            !is_int($snapshot['revision']) || $snapshot['revision'] < 1 || $snapshot['revision'] > 2000000000 ||
            !is_array($definition) || count($definition) !== 2 || !is_array($definition['tree'] ?? null) ||
            !is_array($definition['references'] ?? null)
        ) {
            self::invalid();
        }
        $this->rule = new CourseEligibilityRule($definition['tree']);
        $expected = $this->rule->references();
        $actual = [];
        foreach ($definition['references'] as $field => $references) {
            if (!is_array($references)) {
                self::invalid();
            }
            $actual[$field] = [];
            foreach ($references as $id => $reference) {
                if (
                    !is_array($reference) || count($reference) !== 3 ||
                    array_diff(array_keys($reference), ['id', 'code', 'title']) !== [] ||
                    $reference['id'] !== $id || !is_string($reference['code']) || !is_string($reference['title']) ||
                    mb_strlen($reference['code']) > 80 || mb_strlen($reference['title']) > 191
                ) {
                    self::invalid();
                }
                $actual[$field][] = $id;
            }
            sort($actual[$field], SORT_STRING);
        }
        ksort($actual, SORT_STRING);
        if ($expected !== $actual) {
            self::invalid();
        }
        // Protected history canonicalizes map order. Rebuild the authoring format before checking its checksum.
        $references = [];
        foreach ($expected as $field => $ids) {
            foreach ($ids as $id) {
                $reference = $definition['references'][$field][$id];
                $references[$field][$id] = [
                    'id' => $reference['id'], 'code' => $reference['code'], 'title' => $reference['title'],
                ];
            }
        }
        $normalized = ['tree' => $this->rule->rules->tree, 'references' => $references];
        if (!hash_equals($snapshot['definition_hash'], hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR)))) {
            self::invalid();
        }
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException('Select a complete, verified saved course-rule revision.');
    }
}
