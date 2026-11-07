<?php

declare(strict_types=1);

require_once __DIR__ . '/SisFixture.php';

use Defuse\Crypto\Key;
use Domain\Finance\Service\StudentAccountService;
use Domain\Finance\ValueObject\Money;
use Domain\Gradebook\Service\GradebookService;
use Domain\Portal\Service\CampusContentService;
use Domain\Portal\Service\CampusService;
use Domain\Portal\Service\PaymentConfirmationService;
use Domain\Portal\Service\PaymentGateway;
use Domain\Portal\Service\PortalPaymentService;
use Domain\Portal\Service\PortalService;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Service\TransferService;
use Infrastructure\Communications\DefuseMessageCipher;
use Infrastructure\Persistence\Repository\SqlCampusRepository;
use Infrastructure\Persistence\Repository\SqlGradebookRepository;
use Infrastructure\Persistence\Repository\SqlPaymentRepository;
use Infrastructure\Persistence\Repository\SqlPortalRepository;
use Infrastructure\Persistence\Repository\SqlTransferRepository;
use Qubus\Config\Collection;

final class FixturePaymentGateway implements PaymentGateway
{
    public bool $available = true;
    public bool $failOnce = false;
    public array $calls = [];
    public ?array $confirmation = null;

    public function enabled(): bool
    {
        return $this->available;
    }

    public function checkout(EntityId $payment, Money $amount, int $expiresAt): array
    {
        $this->calls[] = [$payment->value, $amount->minor, $amount->currency, $expiresAt];
        if ($this->failOnce) {
            $this->failOnce = false;
            throw new DomainException('Fictional provider failure.');
        }
        return ['session' => 'cs_test_' . $payment->value,
            'url' => 'https://checkout.stripe.com/c/pay/fictional-' . $payment->value];
    }

    public function verify(string $body, string $signature): ?array
    {
        if ($signature !== 'fictional-valid-signature') {
            throw new InvalidArgumentException('Invalid notification.');
        }
        return $this->confirmation;
    }
}

final class CampusFixture
{
    public SisFixture $sis;
    public SqlCampusRepository $repository;
    public SqlPaymentRepository $payments;
    public CampusService $service;
    public CampusContentService $content;
    public PortalService $posts;
    public DefuseMessageCipher $cipher;
    public FixturePaymentGateway $gateway;
    public PortalPaymentService $checkout;
    public PaymentConfirmationService $confirmation;
    public GradebookService $gradebook;

    public function __construct()
    {
        $f = $this->sis = new SisFixture();
        $this->repository = new SqlCampusRepository($f->sql);
        $this->payments = new SqlPaymentRepository($f->sql);
        $config = Collection::factory([]);
        $config->setConfigKey('app', ['crypto_key' => Key::createNewRandomKey()->saveToAsciiSafeString()]);
        $this->cipher = new DefuseMessageCipher($config);
        $this->gradebook = new GradebookService(new SqlGradebookRepository($f->sql), $f->registration,
            $f->admissions, $f->access, $f->transactions, $f->audit);
        $transfers = new TransferService(new SqlTransferRepository($f->sql), $f->registration,
            $f->admissions, $f->access, $f->transactions, $f->audit);
        $this->service = new CampusService($this->repository, $f->access, $f->service, $transfers,
            $this->gradebook, new StudentAccountService($f->accounts, $f->admissions, $f->access),
            $this->cipher, $f->transactions, $f->audit, $f->clock);
        $posts = new SqlPortalRepository($f->sql);
        $this->posts = new PortalService($posts, $f->access, $f->transactions, $f->audit);
        $this->content = new CampusContentService($posts, $this->repository, $this->posts,
            $f->access, $f->transactions, $f->audit);
        $this->gateway = new FixturePaymentGateway();
        $this->checkout = new PortalPaymentService($this->payments, $this->repository, $f->accounts,
            $f->access, $this->gateway, $this->cipher, $f->transactions, $f->audit, $f->clock);
        $this->confirmation = new PaymentConfirmationService($this->gateway, $this->payments,
            $f->accounts, $f->transactions, $f->audit);
    }
}
