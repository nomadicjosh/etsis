<?php

declare(strict_types=1);

namespace Domain\Payments\ValueObject;

use Domain\Finance\ValueObject\GatewayReceipt;
use Domain\Finance\ValueObject\LedgerSchema;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\ValueObject\EntityId;
use DomainException;

/** Shared, replayable capture budget. Provider credentials and responses never belong here. */
final class CaptureState
{
    public static function plan(array $p): void
    {
        LedgerSchema::keys($p, ['payment_id', 'session', 'intent', 'authorized_minor', 'minor',
            'currency', 'owner_id', 'receipt']);
        new EntityId($p['payment_id']);
        new EntityId($p['owner_id']);
        new GatewayReceipt($p['receipt']);
        if (
            !is_string($p['session']) || preg_match('/^cs_[A-Za-z0-9_]{1,180}$/D', $p['session']) !== 1
            || !is_string($p['intent']) || preg_match('/^pi_[A-Za-z0-9_]{1,180}$/D', $p['intent']) !== 1
            || !is_int($p['minor']) || !is_int($p['authorized_minor']) || $p['authorized_minor'] <= 0
            || $p['minor'] < 0 || $p['minor'] > $p['authorized_minor']
            || $p['receipt']['asset']['currency'] !== $p['currency']
        ) {
            throw new DomainException('Invalid payment capture plan.');
        }
        new Money($p['authorized_minor'], $p['currency']);
    }

    public static function reserve(array $states, array $plan, int $available, string $currency): array
    {
        self::plan($plan);
        if (
            isset($states[$plan['payment_id']]) || $currency !== $plan['currency']
            || $plan['minor'] > max(0, $available - self::held($states))
        ) {
            throw new DomainException('The capture exceeds the unreserved balance.');
        }
        $states[$plan['payment_id']] = ['plan' => $plan, 'state' => 'reserved', 'received_minor' => 0];
        return $states;
    }

    public static function progress(array $p): void
    {
        LedgerSchema::keys($p, ['payment_id', 'state', 'received_minor']);
        new EntityId($p['payment_id']);
        if (
            !in_array($p['state'], ['received', 'paid', 'cancelled', 'review'], true)
            || !is_int($p['received_minor']) || $p['received_minor'] < 0 || $p['received_minor'] > Money::MAX_MINOR
            || ($p['state'] === 'cancelled' && $p['received_minor'] !== 0)
            || (in_array($p['state'], ['received', 'paid'], true) && $p['received_minor'] === 0)
        ) {
            throw new DomainException('Invalid capture progress.');
        }
    }

    public static function advance(array $states, array $progress): array
    {
        self::progress($progress);
        $id = $progress['payment_id'];
        $old = $states[$id] ?? throw new DomainException('A capture needs its retained plan.');
        if (
            in_array($old['state'], ['paid', 'cancelled'], true)
            || ($progress['state'] === 'paid' && ($old['state'] !== 'received'
                || $old['received_minor'] !== $progress['received_minor']))
            || ($progress['state'] === 'cancelled' && $old['received_minor'] !== 0)
            || (in_array($progress['state'], ['paid', 'received'], true)
                && $progress['received_minor'] > $old['plan']['minor'])
            || ($old['received_minor'] > 0 && $progress['received_minor'] !== $old['received_minor']
                && !($old['state'] === 'review' && $progress['state'] === 'review'
                    && $progress['received_minor'] > $old['received_minor']))
        ) {
            throw new DomainException('Capture progress conflicts with its earlier facts.');
        }
        $states[$id] = ['plan' => $old['plan'], 'state' => $progress['state'],
            'received_minor' => $progress['received_minor']];
        return $states;
    }

    public static function held(array $states): int
    {
        $held = 0;
        foreach ($states as $state) {
            if (!in_array($state['state'], ['paid', 'cancelled'], true)) {
                $held += $state['plan']['minor'];
            }
        }
        return $held;
    }

    public static function assertBudget(array $states, int $balance): void
    {
        $held = self::held($states);
        if ($held > 0 && $balance < $held) {
            throw new \Domain\Payments\Exception\CapturePendingException();
        }
    }
}
