<?php

declare(strict_types=1);

namespace Domain\Admissions\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** Immutable selected-file facts in protected decision metadata. Missing metadata means unknown earlier evidence. */
final class AdmissionEvidence
{
    public const int MAXIMUM = 10;

    public static function validate(#[\SensitiveParameter] mixed $items): void
    {
        if (!is_array($items) || !array_is_list($items) || count($items) > self::MAXIMUM) {
            throw new CorruptHistoryException();
        }
        $previous = '';
        foreach ($items as $item) {
            $keys = is_array($item) ? array_keys($item) : [];
            $expected = ['id', 'version', 'name', 'bytes', 'sha256', 'scanned_at'];
            sort($keys);
            sort($expected);
            if ($keys !== $expected) {
                throw new CorruptHistoryException();
            }
            if (!is_string($item['id'])) {
                throw new CorruptHistoryException();
            }
            new EntityId($item['id']);
            if (
                $item['id'] <= $previous || !is_int($item['version']) || $item['version'] < 1
                || !is_string($item['name']) || trim($item['name']) === '' || mb_strlen($item['name']) > 120
                || !mb_check_encoding($item['name'], 'UTF-8') || preg_match('~[\x00-\x1f\x7f/\\\\]~', $item['name'])
                || !is_int($item['bytes']) || $item['bytes'] < 1 || $item['bytes'] > 5242880
                || !is_string($item['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $item['sha256'])
                || !is_string($item['scanned_at'])
                || !preg_match(
                    '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z$/D',
                    $item['scanned_at']
                )
            ) {
                throw new CorruptHistoryException();
            }
            $at = \DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i:s.u\Z',
                $item['scanned_at'],
                new \DateTimeZone('UTC')
            );
            if ($at === false || $at->format('Y-m-d\TH:i:s.u\Z') !== $item['scanned_at']) {
                throw new CorruptHistoryException();
            }
            $previous = $item['id'];
        }
    }

    /** @param list<array<string,mixed>>|null $items @return list<array{name:string}>|null */
    public static function display(?array $items): ?array
    {
        if ($items === null) {
            return null;
        }
        self::validate($items);
        return array_map(static fn(array $item): array => ['name' => $item['name']], $items);
    }
}
