<?php

declare(strict_types=1);

namespace Infrastructure\EventSourcing;

use Domain\Shared\EventSourcing\HistoryInventory;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;
use RuntimeException;

/** Ordered streaming fingerprint. Fixed identifiers, keyset reads, no unbounded result or payload output. */
final readonly class SqlHistoryInventory implements HistoryInventory
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function __debugInfo(): array
    {
        return ['inventory' => '[private journal storage]'];
    }

    public function fingerprint(int $rowLimit): array
    {
        if ($rowLimit < 1 || $rowLimit > 10000) {
            throw new InvalidArgumentException('Use an inventory row limit from 1 through 10000.');
        }
        $hash = hash_init('sha256');
        $counts = ['streams' => 0, 'events' => 0, 'operations' => 0];
        $rowsSeen = $bytes = 0;
        $deadline = hrtime(true) + 5_000_000_000;
        foreach ($counts as $kind => $_count) {
            $table = $this->sql->quote('sis_history_' . $kind);
            $id = $this->sql->quote('id');
            hash_update($hash, $kind . "\n");
            $after = null;
            do {
                $rows = $this->sql->fetch(
                    'SELECT * FROM ' . $table . ($after === null ? '' : ' WHERE ' . $id . ' > ?') . ' ORDER BY ' . $id,
                    $after === null ? [] : [$after],
                    min(10, $rowLimit - $rowsSeen + 1),
                );
                foreach ($rows as $row) {
                    if (++$rowsSeen > $rowLimit || hrtime(true) >= $deadline) {
                        throw new RuntimeException('The complete inventory exceeds its row or time allowance.');
                    }
                    // Normalize PDO scalar representations; ciphertext and all persisted columns remain exact.
                    $values = [];
                    foreach ($row as $column => $value) {
                        if ($value !== null && !is_scalar($value)) {
                            throw new RuntimeException('The inventory driver representation is unsupported.');
                        }
                        $values[$column] = $value === null ? null : (string) $value;
                    }
                    $content = ProtectedEventCodec::canonical($values) . "\n";
                    $bytes += strlen($content);
                    if ($bytes > 16777216) {
                        throw new RuntimeException('The complete inventory exceeds its byte allowance.');
                    }
                    hash_update($hash, $content);
                    $counts[$kind]++;
                    $after = $row['id'];
                }
            } while ($rows !== []);
        }
        return [...$counts, 'digest' => hash_final($hash)];
    }
}
