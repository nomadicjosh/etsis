<?php

declare(strict_types=1);

namespace Domain\ServiceFee\ValueObject;

use Domain\Finance\LedgerLine;
use Domain\Finance\ValueObject\LedgerSchema;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\ValueObject\EntityId;

/** Retained institutional recognition policy and deterministic gross financial effects. */
final class FeeAccounting
{
    public static function mapping(array $d): void
    {
        LedgerSchema::keys($d, ['cash', 'liability', 'expense', 'application', 'transcript', 'recognition']);
        if (!in_array($d['recognition'], ['acceptance', 'receipt'], true)) {
            throw new \InvalidArgumentException('Choose an explicit fee recognition policy.');
        }
        foreach (['cash', 'liability', 'expense', 'application', 'transcript'] as $key) {
            LedgerSchema::account($d[$key]);
        }
        foreach (['cash', 'expense', 'application', 'transcript'] as $key) {
            if ($d[$key]['id'] === $d['liability']['id']) {
                throw new \InvalidArgumentException('Unearned fees need a distinct liability account.');
            }
        }
        $different = [$d['cash']['id'], $d['liability']['id'], $d['expense']['id']];
        if (
            count(array_unique($different)) !== 3
            || in_array($d['application']['id'], $different, true)
            || in_array($d['transcript']['id'], $different, true)
        ) {
            throw new \InvalidArgumentException('Assets, liabilities, expenses and income need distinct accounts.');
        }
    }

    public static function snapshot(?array $s): void
    {
        if ($s === null) {
            return;
        }
        LedgerSchema::keys($s, ['configuration_event_id', 'definition']);
        new EntityId($s['configuration_event_id']);
        self::mapping($s['definition']);
    }

    private static function income(array $s): int
    {
        $a = $s['assessment'];
        $enabled = $a['accounting']['definition']['recognition'] === 'receipt' || $s['applied_to'] !== null;
        return $enabled ? min($a['policy']['minor'], max(0, $s['net_minor'])) : 0;
    }

    /** @return list<LedgerLine> */
    public static function lines(array $before, array $after, array $p): array
    {
        $a = $after['assessment'];
        $map = $a['accounting']['definition'] ?? null;
        if ($map === null || !in_array($p['action'], ['payment', 'refund', 'apply'], true)) {
            return [];
        }
        $d = $p['details'];
        $income = self::income($after) - self::income($before);
        $cash = match ($p['action']) {
            'payment' => $d['minor'], 'refund' => -$d['minor'], default => 0,
        };
        $parts = [];
        if ($cash !== 0) {
            $receipt = $p['action'] === 'payment' ? $after['payments'][$d['payment_id']]
            : $before['payments'][$d['payment_id']];
            LedgerSchema::account($receipt['asset']);
            $parts[$receipt['asset']['id']] = $cash;
        }
        $parts[$map['liability']['id']] = ($parts[$map['liability']['id']] ?? 0) - $cash + $income;
        $parts[$map[$a['purpose']]['id']] = ($parts[$map[$a['purpose']]['id']] ?? 0) - $income;
        $lines = [];
        foreach ($parts as $id => $minor) {
            if ($minor !== 0) {
                $lines[] = new LedgerLine(new EntityId($id), new Money($minor, $a['policy']['currency']));
            }
        }
        return $lines;
    }
}
