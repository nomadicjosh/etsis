<?php

declare(strict_types=1);

require_once __DIR__ . "/SisFixture.php";

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
            throw new DomainException("Fictional provider failure.");
        }
        return [
            "session" => "cs_test_" . $payment->value,
            "url" => "https://checkout.stripe.com/c/pay/fictional-" . $payment->value,
        ];
    }

    public function verify(string $body, string $signature): ?array
    {
        if ($signature !== "fictional-valid-signature") {
            throw new InvalidArgumentException("Invalid notification.");
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
    public \Domain\Person\Service\PersonContactService $contacts;
    public \Domain\Person\Service\EmergencyContactsService $emergency;
    public \Domain\Person\Service\EducationBackgroundService $education;
    public \Domain\Person\Service\PersonAddressService $addresses;
    public CampusContentService $content;
    public PortalService $posts;
    public DefuseMessageCipher $cipher;
    public FixturePaymentGateway $gateway;
    public PortalPaymentService $checkout;
    public PaymentConfirmationService $confirmation;
    public GradebookService $gradebook;

    public function publish(EntityId $id, bool $publish, int $version): array
    {
        $saved = $this->content->get($id);
        if ($saved['version'] !== $version) {
            throw new \Domain\Shared\Exception\ConcurrentModificationException();
        }
        $window = new \Domain\Portal\ValueObject\PublicationWindow(null, null);
        $review = $this->content->review($id, $publish ? 'approve' : 'archive', $saved['revision'], $window, null);
        return $this->content->confirm(
            $id,
            new EntityId($review['operation_id']),
            $review['action'],
            $review['revision'],
            $window,
            null,
            $review['review_token'],
        );
    }

    public function __construct()
    {
        $f = $this->sis = new SisFixture();
        $this->repository = new SqlCampusRepository(
            $f->sql,
            $f->clock,
            new \Infrastructure\Portal\SymfonyContentMarkup(),
        );
        $this->payments = new SqlPaymentRepository($f->sql);
        $config = Collection::factory([]);
        $config->setConfigKey("app", ["crypto_key" => Key::createNewRandomKey()->saveToAsciiSafeString()]);
        $this->cipher = new DefuseMessageCipher($config);
        $this->gradebook = new GradebookService(
            new SqlGradebookRepository($f->sql),
            $f->registration,
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->academicCredits(),
            $f->comparisonPolicies(),
        );
        $transfers = new TransferService(
            new SqlTransferRepository($f->sql, $f->gradeCatalog()),
            $f->registration,
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->audit,
            new \Infrastructure\Persistence\Repository\SqlTransferCatalogRepository(
                $f->sql,
                $f->gradeCatalog(),
            ),
            $f->transferCredits(),
        );
        $profileAccess = new \Domain\Person\Service\PersonProfileAccess(
            new \Infrastructure\Persistence\Repository\SqlPersonAccountRepository(
                $f->sql,
                $f->admissions,
                new \Infrastructure\Identity\RandomInstitutionalIdGenerator()
            ),
            $f->admissions,
            new \Infrastructure\Persistence\Repository\SqlAccessRepository($f->sql),
            $f->access
        );
        $addressBooks = new \Infrastructure\Persistence\Repository\SqlAddressBookRepository($f->sql, $this->cipher);
        $this->addresses = new \Domain\Person\Service\PersonAddressService(
            $addressBooks,
            new \Infrastructure\Persistence\Repository\SqlPersonContactRepository($f->sql, $this->cipher),
            new \Infrastructure\Persistence\Repository\SqlPersonProfileRepository($f->sql),
            $f->profileLedger(),
            $profileAccess,
            $f->transactions,
            $f->audit,
            $f->clock
        );
        $this->contacts = new \Domain\Person\Service\PersonContactService(
            new \Infrastructure\Persistence\Repository\SqlPersonContactRepository($f->sql, $this->cipher),
            new \Infrastructure\Persistence\Repository\SqlPersonProfileRepository($f->sql),
            $f->profileLedger(),
            $profileAccess,
            $f->transactions,
            $f->audit,
            $addressBooks,
            $f->clock
        );
        $this->emergency = new \Domain\Person\Service\EmergencyContactsService(
            new \Infrastructure\Persistence\Repository\SqlEmergencyContactsRepository($f->sql, $this->cipher),
            new \Infrastructure\Persistence\Repository\SqlPersonProfileRepository($f->sql),
            $f->profileLedger(),
            $profileAccess,
            $f->transactions,
            $f->audit
        );
        $this->education = new \Domain\Person\Service\EducationBackgroundService(
            new \Infrastructure\Persistence\Repository\SqlEducationBackgroundRepository($f->sql, $this->cipher),
            new \Infrastructure\Persistence\Repository\SqlPersonProfileRepository($f->sql),
            $f->profileLedger(),
            $profileAccess,
            $f->access,
            $f->transactions,
            $f->audit
        );
        $this->service = new CampusService(
            $this->repository,
            $f->access,
            $f->service,
            $transfers,
            $this->gradebook,
            $f->finance(),
            $this->cipher,
            $f->transactions,
            $f->audit,
            $f->clock,
            $this->contacts,
            $f->registrationBatches(),
            new \Domain\AcademicRecord\Service\CeuRecordService(
                $f->inputVerifier(),
                new \Infrastructure\Persistence\Repository\SqlAcademicCreditRepository($f->sql),
                $f->admissions,
                $f->access,
                $f->transactions,
            ),
        );
        $posts = new SqlPortalRepository($f->sql, $f->clock);
        $this->posts = new PortalService(
            $posts,
            $f->access,
            $f->transactions,
            $f->audit,
            new \Infrastructure\Portal\SymfonyContentMarkup(),
        );
        $this->content = new CampusContentService(
            $posts,
            $this->repository,
            $this->posts,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->clock,
            new \Domain\Shared\Service\ReviewProof($this->cipher, $f->clock),
            new \Infrastructure\Portal\SymfonyContentMarkup(),
            new \Infrastructure\Persistence\Repository\SqlContentAudienceRepository($f->sql),
        );
        $this->gateway = new FixturePaymentGateway();
        $this->checkout = new PortalPaymentService(
            $this->payments,
            $this->repository,
            $f->accounts,
            $f->access,
            $this->gateway,
            $this->cipher,
            $f->transactions,
            $f->audit,
            $f->clock,
        );
        $this->confirmation = new PaymentConfirmationService(
            $this->gateway,
            $this->payments,
            $f->accounts,
            $f->transactions,
            $f->audit,
            $f->clock,
        );
    }
}
