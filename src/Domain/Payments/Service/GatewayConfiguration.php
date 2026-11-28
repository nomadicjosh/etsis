<?php

declare(strict_types=1);

namespace Domain\Payments\Service;

use Domain\Communications\Service\MessageCipher;
use Domain\Finance\Service\LedgerControl;
use Domain\Finance\ValueObject\Money;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Payments\Repository\GatewayRepository;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use InvalidArgumentException;
use Throwable;

/** Operational secrets stay sealed; accepted receipts retain a separate, credential-free financial fact. */
final readonly class GatewayConfiguration
{
    public const array PURPOSES = ['account', 'application', 'transcript'];

    public function __construct(
        public GatewayRepository $records,
        private MessageCipher $cipher,
        private LedgerControl $ledger,
    ) {
    }

    public static function routeId(string $purpose, string $currency): EntityId
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new InvalidArgumentException('Invalid payment purpose.');
        }
        new Money(0, $currency);
        return PublishedGradeId::forVersion(
            new EntityId('00000000000000000000000001'),
            'payment-route:' . $purpose . ':' . $currency
        );
    }

    public function seal(#[\SensitiveParameter] array $data): string
    {
        return $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function open(#[\SensitiveParameter] string $sealed): array
    {
        try {
            if (strlen($sealed) > 65536) {
                throw new InvalidArgumentException();
            }
            $data = json_decode($this->cipher->open($sealed), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new InvalidArgumentException();
            }
            return $data;
        } catch (Throwable) {
            throw new DomainException('Payment configuration could not be authenticated.');
        }
    }

    public function connection(array $row): array
    {
        $data = $this->open($row['sealed_data']);
        if (($data['id'] ?? null) !== $row['id'] || ($data['version'] ?? null) !== (int) $row['version']) {
            throw new DomainException('Payment connection changed outside its reviewed configuration.');
        }
        return $this->validateConnection($data, true);
    }

    private function validateConnection(#[\SensitiveParameter] array $data, bool $requireApiKey): array
    {
        \Domain\Finance\ValueObject\LedgerSchema::keys($data, ['id', 'version', 'name', 'provider', 'merchant',
            'live', 'currency', 'enabled', 'asset', 'secret', 'webhook']);
        if (
            $data['provider'] !== 'stripe' || !is_bool($data['live']) || !is_bool($data['enabled'])
            || !is_string($data['name']) || trim($data['name']) === '' || mb_strlen($data['name']) > 120
            || !is_int($data['version']) || $data['version'] < 1 || !is_string($data['webhook'])
            || preg_match('/^whsec_[a-zA-Z0-9_]+$/D', $data['webhook']) !== 1
        ) {
            throw new DomainException('Invalid retained payment connection.');
        }
        \Domain\Finance\ValueObject\GatewayReceipt::fromConnection($data);
        new Money(0, $data['currency']);
        if ($data['asset']['currency'] !== $data['currency']) {
            throw new DomainException('Invalid gateway asset currency.');
        }
        if ($requireApiKey || $data['secret'] !== null) {
            self::credentials($data['secret'], $data['webhook'], $data['live']);
        }
        return $data;
    }

    public function route(array $row): array
    {
        $data = $this->open($row['sealed_data']);
        if (
            ($data['id'] ?? null) !== $row['id'] || ($data['version'] ?? null) !== (int) $row['version']
            || ($data['purpose'] ?? null) !== $row['purpose'] || ($data['currency'] ?? null) !== $row['currency']
            || $row['id'] !== self::routeId($row['purpose'], $row['currency'])->value
        ) {
            throw new DomainException('Payment routing changed outside its reviewed configuration.');
        }
        if ($data['connection_id'] !== null) {
            new EntityId($data['connection_id']);
        }
        return $data;
    }

    public static function credentials(
        #[\SensitiveParameter] string $secret,
        #[\SensitiveParameter] string $webhook,
        bool $live
    ): void {
        if (
            strlen($secret) > 512 || strlen($webhook) > 512
            || preg_match('/^(sk|rk)_' . ($live ? 'live' : 'test') . '_[a-zA-Z0-9_]+$/D', $secret) !== 1
            || preg_match('/^whsec_[a-zA-Z0-9_]+$/D', $webhook) !== 1
        ) {
            throw new InvalidArgumentException('Provide a matching Stripe API key and webhook signing secret.');
        }
    }

    public static function summary(#[\SensitiveParameter] array $data): array
    {
        unset($data['secret'], $data['webhook']);
        return $data;
    }

    public function asset(EntityId $id, string $currency, bool $lock = false): array
    {
        $row = $this->records->asset($id, $lock);
        if ($row['currency'] !== $currency || ($row['kind'] ?? null) !== 'asset') {
            throw new DomainException('Choose a classified asset account in the connection currency.');
        }
        $book = $this->ledger->book($currency);
        if (($book['definition']['receivable']['id'] ?? null) === $id->value) {
            throw new DomainException('The receivables control account cannot receive gateway funds.');
        }
        return array_intersect_key($row, array_flip(['id', 'code', 'title', 'currency', 'kind']));
    }

    public function select(string $purpose, string $currency, bool $lock = false): array
    {
        $row = $this->records->route(self::routeId($purpose, $currency), $lock);
        if ($row === null || ($route = $this->route($row))['connection_id'] === null) {
            throw new DomainException('No payment connection is assigned for this purpose and currency.');
        }
        $row = $this->records->connection(new EntityId($route['connection_id']), $lock);
        if ($row === null || !($data = $this->connection($row))['enabled'] || $data['currency'] !== $currency) {
            throw new DomainException('This payment connection is unavailable.');
        }
        if ($this->asset(new EntityId($data['asset']['id']), $currency, $lock) !== $data['asset']) {
            throw new DomainException('Review the changed gateway asset assignment.');
        }
        if ($purpose === 'account' && $this->ledger->book($currency) === null) {
            throw new DomainException('Publish the student ledger mapping before accepting account payments.');
        }
        return $data;
    }

    public function bind(EntityId $id, array $row, string $baseUrl): array
    {
        $connection = $this->select($row['purpose'], $row['currency'], true);
        return $row + ['sealed_gateway' => $this->seal([
            'kind' => 'checkout', 'capture' => 'manual', 'base_url' => $baseUrl,
            'payment' => self::payment($id, $row), 'connection' => $connection])];
    }

    public function binding(array $row): array
    {
        $data = $this->open($row['sealed_gateway'] ?? '');
        \Domain\Finance\ValueObject\LedgerSchema::keys($data, ['kind', 'capture', 'base_url', 'payment', 'connection']);
        if (
            ($data['kind'] ?? null) !== 'checkout' || ($data['capture'] ?? null) !== 'manual'
            || ($data['payment'] ?? null) !== self::payment(new EntityId($row['id']), $row)
        ) {
            throw new DomainException('Checkout does not match its retained payment configuration.');
        }
        $url = parse_url($data['base_url'] ?? '');
        if (
            !is_array($url) || ($url['scheme'] ?? '') !== 'https' || !isset($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
        ) {
            throw new DomainException('Invalid retained checkout return URL.');
        }
        $this->validateConnection($data['connection'], false);
        return $data;
    }

    public function checkpoint(array $row): string
    {
        $binding = $this->binding($row);
        if (in_array($row['status'], ['paid', 'cancelled'], true)) {
            $binding['connection']['secret'] = null;
        }
        return $this->seal($binding);
    }

    private static function payment(EntityId $id, array $r): array
    {
        return ['id' => $id->value, 'account_id' => $r['account_id'], 'fee_id' => $r['fee_id'],
            'purpose' => $r['purpose'], 'owner_user_id' => $r['owner_user_id'],
            'amount_minor' => (int) $r['amount_minor'], 'currency' => $r['currency'],
            'expires_at' => (int) $r['expires_at']];
    }
}
