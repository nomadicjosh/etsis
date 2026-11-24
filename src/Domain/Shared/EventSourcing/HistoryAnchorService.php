<?php

declare(strict_types=1);

namespace Domain\Shared\EventSourcing;

use DateTimeZone;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use RuntimeException;

/** Independent checkpoint comparison after bounded complete authentication; no projectors or business writes. */
final readonly class HistoryAnchorService
{
    public function __construct(
        private HistoryInventory $inventory,
        private HistoricalIntegrityVerifier $verifier,
        private Clock $clock,
    ) {
    }

    public function capture(string $scope, int $rowLimit = 2000): HistoryAnchor
    {
        self::scope($scope);
        return new HistoryAnchor([
            'format' => 1,
            'id' => EntityId::generate()->value,
            'scope' => $scope,
            'captured_at' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'inventory' => $this->verified($rowLimit),
        ]);
    }

    public function compare(HistoryAnchor $anchor, string $scope, int $rowLimit = 2000): bool
    {
        self::scope($scope);
        if (!hash_equals($anchor->data['scope'], $scope)) {
            return false;
        }
        $current = $this->verified($rowLimit);
        return array_all(
            ['streams', 'events', 'operations', 'digest'],
            fn($field) => $anchor->data['inventory'][$field] === $current[$field]
        );
    }

    private static function scope(string $scope): void
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,63}$/D', $scope) !== 1) {
            throw new InvalidArgumentException('Use a stable installation scope of 1–64 letters, numbers, . _ : or -.');
        }
    }

    public function __debugInfo(): array
    {
        return ['checkpoint' => '[read-only checkpoint service]'];
    }

    private function verified(int $rowLimit): array
    {
        if ($rowLimit < 1 || $rowLimit > 10000) {
            throw new InvalidArgumentException('Use an inventory row limit from 1 through 10000.');
        }
        $deadline = hrtime(true) + 10_000_000_000;
        $before = $this->inventory->fingerprint($rowLimit);
        $cursor = null;
        for ($pass = 0; $pass < 64; $pass++) {
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('Checkpoint verification exceeded its bounded allowance.');
            }
            $result = $this->verifier->verify($cursor, 50, min(2000, $rowLimit));
            $cursor = $result->nextCursor;
            if ($result->complete) {
                $after = $this->inventory->fingerprint($rowLimit);
                if (hrtime(true) >= $deadline || $before !== $after) {
                    throw new RuntimeException('The journal changed or the checkpoint allowance expired.');
                }
                return $after;
            }
        }
        throw new RuntimeException('Complete authentication did not fit the checkpoint allowance.');
    }
}
