<?php

declare(strict_types=1);

namespace Domain\Finance\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Frozen gross-receipt clearing assignment. It contains no provider credential or card information. */
final readonly class GatewayReceipt
{
    public function __construct(private array $data)
    {
        LedgerSchema::keys($data, ['connection_id', 'version', 'provider', 'merchant', 'live', 'asset']);
        new EntityId($data['connection_id']);
        if (
            !is_int($data['version']) || $data['version'] < 1 || $data['provider'] !== 'stripe'
            || !is_bool($data['live']) || !is_string($data['merchant'])
            || preg_match('/^acct_[a-zA-Z0-9]+$/D', $data['merchant']) !== 1 || !is_array($data['asset'])
        ) {
            throw new InvalidArgumentException('Invalid gateway receipt.');
        }
        $a = $data['asset'];
        LedgerSchema::keys($a, ['id', 'code', 'title', 'currency', 'kind']);
        new EntityId($a['id']);
        new Money(0, $a['currency']);
        if (
            $a['kind'] !== 'asset' || !is_string($a['code']) || trim($a['code']) === ''
            || strlen($a['code']) > 80 || !is_string($a['title']) || trim($a['title']) === ''
            || mb_strlen($a['title']) > 191
        ) {
            throw new InvalidArgumentException('Invalid gateway asset assignment.');
        }
    }

    public static function fromConnection(#[\SensitiveParameter] array $c): self
    {
        return new self(['connection_id' => $c['id'], 'version' => $c['version'], 'provider' => $c['provider'],
            'merchant' => $c['merchant'], 'live' => $c['live'], 'asset' => $c['asset']]);
    }

    public function values(): array
    {
        return $this->data;
    }
}
