<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Access\Enum\GrantKind;
use Domain\Finance\Service\LedgerService;
use Domain\Payments\Service\GatewayConfiguration;
use Domain\Payments\Service\GatewaySetup;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Payments\ConfiguredPaymentGateway;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlGatewayRepository;

final class PaymentGatewayFixture
{
    public \CampusFixture $campus;
    public \SisFixture $sis;
    public SqlAccessRepository $roles;
    public LedgerService $ledger;
    public GatewayConfiguration $configuration;
    public GatewaySetup $setup;
    public FictionalGatewayPolicy $policy;
    public FictionalGatewayClient $client;
    public ConfiguredPaymentGateway $gateway;
    public EntityId $bank;
    public EntityId $other;
    public EntityId $income;
    public EntityId $connection;
    public array $input;

    public function __construct(bool $configure = true, ?\Domain\Audit\Repository\AuditTrail $audit = null)
    {
        require_once __DIR__ . '/CampusFixture.php';
        require_once __DIR__ . '/FictionalGatewayPolicy.php';
        require_once __DIR__ . '/FictionalGatewayClient.php';
        $this->campus = new \CampusFixture();
        $s = $this->sis = $this->campus->sis;
        $s->registrationService();
        $s->sql->update('users', ['sign_in_enabled' => 1], ['user_id' => $s->access->actor->value]);
        $this->roles = new SqlAccessRepository($s->sql);
        foreach ([GatewaySetup::MANAGE, 'sis:finance:ledger'] as $name) {
            $p = $s->sql->one('sis_access_permissions', ['name' => $name]);
            $this->roles->assign(GrantKind::UserPermission, $s->access->actor, new EntityId($p['id']), true);
        }
        $this->ledger = new LedgerService(
            $s->journalRepository(),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->financialCommands(),
            $s->ledgerControl()
        );
        $this->bank = $this->ledger->addAccount('CLEAR', 'Fictional processor clearing', 'USD', 'asset');
        $this->other = $this->ledger->addAccount('CLEAR.2', 'Fictional second clearing', 'USD', 'asset');
        $this->income = $this->ledger->addAccount('REV', 'Fictional revenue', 'USD', 'income');
        $service = $s->studentLedger();
        $review = $service->reviewMapping(
            'USD',
            'America/Los_Angeles',
            'AR',
            'Fictional receivable',
            $this->income,
            $this->other,
            $this->other,
            [],
            [],
            'Approve fictional accounting mapping.'
        );
        $service->confirm($review['review_token']);
        $review = $service->reviewPeriod(
            'USD',
            'Fictional August',
            '2026-08-01',
            '2026-08-31',
            'Open fictional accounting period.'
        );
        $service->confirm($review['review_token']);
        $this->configuration = new GatewayConfiguration(
            new SqlGatewayRepository($s->sql),
            $this->campus->cipher,
            $s->ledgerControl()
        );
        $this->policy = new FictionalGatewayPolicy();
        $this->client = new FictionalGatewayClient();
        $this->setup = new GatewaySetup(
            $this->configuration,
            $this->client,
            $this->policy,
            $this->ledger,
            $s->access,
            $this->roles,
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
            new ReviewProof($this->campus->cipher, $s->clock)
        );
        $this->gateway = new ConfiguredPaymentGateway(
            $this->configuration,
            $this->client,
            $this->policy,
            $this->campus->payments
        );
        $this->input = ['id' => null, 'version' => 0, 'name' => 'Fictional tuition gateway', 'enabled' => true,
            'live' => false, 'currency' => 'USD', 'asset_id' => $this->bank->value,
            'secret' => 'sk_test_fictional', 'webhook' => 'whsec_fictional'];
        if ($configure) {
            $this->connection = new EntityId($this->save('connection', $this->input)['id']);
            foreach (GatewayConfiguration::PURPOSES as $purpose) {
                $this->save('route', ['purpose' => $purpose, 'currency' => 'USD', 'version' => 0,
                    'connection_id' => $this->connection->value]);
            }
        }
    }

    public function save(string $kind, array $data): array
    {
        $review = $this->setup->review($kind, $data, 'Approve fictional reviewed payment setup.');
        return $this->setup->confirm(new EntityId($review['review_id']), $review['review_token']);
    }

    public function charged(): array
    {
        $s = $this->sis;
        $student = $s->student();
        $s->service->register($student->id, $s->section(fee: 12345)->id);
        $id = new EntityId($this->campus->service->me()['accounts'][0]['id']);
        $hosted = new \Domain\Portal\Service\HostedCheckout(
            $this->campus->payments,
            $this->gateway,
            $this->campus->cipher,
            $s->transactions,
            $s->clock
        );
        $checkout = new \Domain\Portal\Service\PortalPaymentService(
            $this->campus->payments,
            $this->campus->repository,
            $s->accounts,
            $s->access,
            $this->gateway,
            $s->transactions,
            $s->audit,
            $s->clock,
            $hosted
        );
        $confirmation = new \Domain\Portal\Service\PaymentConfirmationService(
            $this->gateway,
            $this->campus->payments,
            $s->transactions,
            $s->clock,
        );
        return [$id, $checkout, $confirmation];
    }

    public function notification(EntityId $payment, string $secret = 'whsec_fictional', ?array $changes = null): array
    {
        $row = $this->campus->payments->find($payment);
        $binding = $this->configuration->binding($row);
        $c = $binding['connection'];
        $event = ['id' => 'evt_fictional', 'object' => 'event', 'type' => 'checkout.session.completed',
            'livemode' => $c['live'], 'data' => ['object' => array_replace([
                'id' => 'cs_test_' . $payment->value, 'object' => 'checkout.session',
                'client_reference_id' => $payment->value, 'metadata' => ['sis_payment_id' => $payment->value,
                    'sis_connection_id' => $c['id'], 'sis_connection_version' => (string) $c['version']],
                'mode' => 'payment', 'payment_status' => 'paid', 'amount_total' => 12345, 'currency' => 'usd',
            ], $changes ?? [])]];
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $time = time();
        return [$body, 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $body, $secret)];
    }
}
