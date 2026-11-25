<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** Frozen calculation basis; a term refers to verified posting attribution, never a historical cutoff. */
final readonly class StandingGpaAssessment implements \JsonSerializable
{
    public function __construct(#[\SensitiveParameter] public array $data)
    {
        try {
            if (
                count($data) !== 2 || array_diff(array_keys($data), ['basis', 'term']) !== []
                || !in_array($data['basis'], ['cumulative', 'term'], true)
            ) {
                throw new \InvalidArgumentException();
            }
            if ($data['basis'] === 'cumulative') {
                if ($data['term'] !== null) {
                    throw new \InvalidArgumentException();
                }
            } else {
                $term = $data['term'];
                if (
                    !is_array($term) || count($term) !== 2 || array_diff(array_keys($term), ['id', 'code']) !== []
                    || !is_string($term['id']) || new EntityId($term['id'])->value !== $term['id']
                    || !is_string($term['code']) || trim($term['code']) === '' || mb_strlen($term['code']) > 80
                    || !mb_check_encoding($term['code'], 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $term['code'])
                ) {
                    throw new \InvalidArgumentException();
                }
            }
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function summary(): array
    {
        return ['basis' => $this->data['basis'], ...$this->data['term'] === null ? []
            : ['term_code' => $this->data['term']['code']]];
    }

    public function jsonSerialize(): array
    {
        return ['assessment' => '[protected standing GPA assessment]'];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
