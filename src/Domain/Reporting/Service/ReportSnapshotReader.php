<?php

declare(strict_types=1);

namespace Domain\Reporting\Service;

use Domain\Communications\Service\MessageCipher;
use Domain\Reporting\Repository\ReportLifecycleRepository;
use Domain\Shared\ValueObject\EntityId;

/** Internal protected artifact reader; callers must authorize the report and selected definition first. */
final readonly class ReportSnapshotReader
{
    public function __construct(private ReportLifecycleRepository $storage, private MessageCipher $cipher)
    {
    }

    /** @param array<string,mixed> $state
     * @return array<string,mixed> */
    public function manifest(EntityId $id, array $state): array
    {
        $data = json_decode($this->cipher->open($state['encrypted_manifest'] ?? ''), true, 12, JSON_THROW_ON_ERROR);
        if (
            !is_array($data) || ($state['mode'] ?? null) !== 'static' || ($data['report_id'] ?? null) !== $id->value ||
            ($data['capture_id'] ?? null) !== $state['capture_id'] || !is_array($data['columns'] ?? null) ||
            !is_int($data['row_count'] ?? null) || $data['row_count'] < 0 || $data['row_count'] > 5000 ||
            !is_bool($data['grouped'] ?? null) || ($data['captured_at'] ?? null) !== $state['captured_at'] ||
            !is_int($data['definition_version'] ?? null) ||
            !is_array($data['pages'] ?? null) || count($data['pages']) !== (int) ceil($data['row_count'] / 50)
        ) {
            throw new \RuntimeException('Static report capture could not be verified.');
        }
        if (isset($data['financial_as_of'])) {
            new \Domain\Reporting\ValueObject\ReportFinancialCutoff($data['financial_as_of']);
        }
        return $data;
    }

    /** @param array<string,mixed> $manifest
     * @return list<array<string,mixed>> */
    public function page(EntityId $id, array $manifest, int $offset): array
    {
        $index = intdiv($offset, 50);
        $rows = [];
        if (isset($manifest['pages'][$index])) {
            $encrypted = $this->storage->page($id, $index) ??
            throw new \RuntimeException('Static report page is missing.');
            $packet = $this->cipher->open($encrypted);
            if (!hash_equals($manifest['pages'][$index], hash('sha256', $packet))) {
                throw new \RuntimeException('Static report page could not be verified.');
            }
            $data = json_decode($packet, true, 12, JSON_THROW_ON_ERROR);
            if (
                $data['report_id'] !== $id->value || $data['capture_id'] !== $manifest['capture_id'] ||
                $data['page'] !== $index || !is_array($data['rows']) ||
                count($data['rows']) !== min(50, $manifest['row_count'] - $offset)
            ) {
                throw new \RuntimeException('Static report page does not match its capture.');
            }
            $rows = $data['rows'];
        } elseif ($offset < $manifest['row_count']) {
            throw new \RuntimeException('Static report page is missing.');
        }
        return $rows;
    }
}
