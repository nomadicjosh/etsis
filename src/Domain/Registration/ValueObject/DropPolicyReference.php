<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

final class DropPolicyReference
{
    /** @param mixed $reference */
    public static function validate(mixed $reference, int $version): void
    {
        if (
            !is_array($reference) ||
            !in_array(count($reference), [4, 5], true) ||
            array_diff(array_keys($reference), [
                "term_id",
                "section_id",
                "publication_id",
                "version",
                "definition_hash",
            ]) !== [] ||
            !is_int($reference["version"] ?? null) ||
            $reference["version"] < 0 ||
            $reference["version"] !== $version ||
            !is_string($reference["definition_hash"] ?? null) ||
            preg_match('/^[a-f0-9]{64}$/D', $reference["definition_hash"]) !== 1
        ) {
            throw new CorruptHistoryException();
        }
        if (count($reference) === 5 && !is_string($reference['section_id'] ?? null)) {
            throw new CorruptHistoryException();
        }
        foreach (["term_id", "publication_id", ...(count($reference) === 5 ? ['section_id'] : [])] as $key) {
            if (!is_string($reference[$key] ?? null)) {
                throw new CorruptHistoryException();
            }
            new EntityId($reference[$key]);
        }
    }
}
