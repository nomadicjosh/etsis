<?php

declare(strict_types=1);

namespace Domain\Advising\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Dated primary/additional advisors for one pursuit. End dates are inclusive. */
final readonly class AdvisorAssignments
{
    public array $rows;

    public function __construct(array $rows)
    {
        if (!array_is_list($rows) || count($rows) > 50) {
            throw new InvalidArgumentException('Use up to fifty dated advisor assignments.');
        }
        $ids = [];
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) !== 5 ||
                array_diff(array_keys($row), ['id', 'advisor_person_id', 'role', 'starts_on', 'ends_on']) !== [] ||
                !is_string($row['id'] ?? null) || !is_string($row['advisor_person_id'] ?? null) ||
                !in_array($row['role'] ?? null, ['primary', 'additional'], true)) {
                throw new InvalidArgumentException('Provide the complete dated advisor assignment.');
            }
            new EntityId($row['id']);
            new EntityId($row['advisor_person_id']);
            self::date($row['starts_on']);
            if ($row['ends_on'] !== null) {
                self::date($row['ends_on']);
                if ($row['ends_on'] < $row['starts_on']) {
                    throw new InvalidArgumentException('End an advisor assignment on or after its start date.');
                }
            }
            if (isset($ids[$row['id']])) {
                throw new InvalidArgumentException('Use distinct advisor assignment identities.');
            }
            $ids[$row['id']] = true;
            $normalized[] = array_intersect_key(array_replace(array_fill_keys(
                ['id', 'advisor_person_id', 'role', 'starts_on', 'ends_on'], null
            ), $row), $row);
        }
        usort($normalized, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
        foreach ($normalized as $i => $a) {
            foreach (array_slice($normalized, $i + 1) as $b) {
                if (($a['role'] === 'primary' && $b['role'] === 'primary') ||
                    $a['advisor_person_id'] === $b['advisor_person_id']) {
                    if ($a['starts_on'] <= ($b['ends_on'] ?? '9999-12-31') &&
                        $b['starts_on'] <= ($a['ends_on'] ?? '9999-12-31')) {
                        throw new InvalidArgumentException('Primary advisors cannot overlap; the same advisor cannot overlap themselves.');
                    }
                }
            }
        }
        $this->rows = $normalized;
    }

    public static function date(mixed $date): void
    {
        if (!is_string($date) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) !== 1 ||
            !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException('Use valid calendar dates for advisor assignments.');
        }
    }

    public function on(string $date): array
    {
        self::date($date);
        return array_values(array_filter($this->rows, static fn(array $row): bool =>
            $row['starts_on'] <= $date && ($row['ends_on'] === null || $row['ends_on'] >= $date)));
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->rows, JSON_THROW_ON_ERROR));
    }
}
