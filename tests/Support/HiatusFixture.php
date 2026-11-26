<?php

declare(strict_types=1);

require_once __DIR__ . "/SisFixture.php";

use Defuse\Crypto\Key;
use Domain\Audit\Repository\AuditTrail;
use Domain\Student\Service\HiatusService;
use Domain\Student\Service\HiatusWorkflowService;
use Domain\Student\ValueObject\HiatusPolicy;
use Infrastructure\Communications\DefuseMessageCipher;
use Infrastructure\Persistence\Repository\SqlHiatusRepository;
use Infrastructure\Persistence\Repository\SqlHiatusImpactRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use Qubus\Config\Collection;

final class HiatusFixture
{
    public SisFixture $sis;
    public SqlHiatusRepository $repository;
    public DefuseMessageCipher $cipher;
    public HiatusService $history;

    public function __construct(?SisFixture $sis = null)
    {
        $this->sis = $f = $sis ?? new SisFixture();
        $config = Collection::factory([]);
        $config->setConfigKey("app", [
            "crypto_key" => Key::createNewRandomKey()->saveToAsciiSafeString(),
        ]);
        $this->cipher = $sis?->dropCipher ?? new DefuseMessageCipher($config);
        $this->repository = new SqlHiatusRepository($f->sql);
        $this->history = $this->service();
    }

    public function ledger(): \Domain\Student\Service\HiatusLedger
    {
        return new \Domain\Student\Service\HiatusLedger(
            $this->repository,
            EventStoreFixture::history($this->sis->connection->queryBuilder()),
            $this->cipher,
            $this->sis->clock,
        );
    }

    public function service(?AuditTrail $audit = null): HiatusService
    {
        $f = $this->sis;
        return new HiatusService(
            $this->repository,
            $f->admissions,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $this->cipher,
            $f->clock,
            $this->ledger(),
        );
    }

    public function returns(
        ?AuditTrail $audit = null,
    ): \Domain\Student\Service\HiatusReturnService {
        $f = $this->sis;
        return new \Domain\Student\Service\HiatusReturnService(
            $this->repository,
            $f->admissions,
            new SqlStudentRecordRepository($f->sql),
            $f->registration,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $this->cipher,
            $f->clock,
            $f->activityLedger(),
            $this->ledger(),
            new \Domain\Shared\Service\ReviewProof($this->cipher, $f->clock),
        );
    }

    public function workflow(
        ?AuditTrail $audit = null,
        ?HiatusPolicy $policy = null,
    ): HiatusWorkflowService {
        $f = $this->sis;
        return new HiatusWorkflowService(
            $this->service($audit),
            $this->repository,
            new SqlHiatusImpactRepository($f->sql),
            $f->registration,
            $f->admissions,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $f->clock,
            $policy ?? new HiatusPolicy(),
            $f->drops,
            $f->dropPolicies,
            $f->activityLedger(),
            new \Domain\Shared\Service\ReviewProof($this->cipher, $f->clock),
            new \Infrastructure\Persistence\EventSourcedRegistrationBilling(
                $f->accounts,
                $f->sql,
                new \Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository(
                    $f->sql,
                    EventStoreFixture::framework($f->connection->queryBuilder()),
                    $f->transactions,
                    EventStoreFixture::financialWriter($f->connection->queryBuilder()),
                ),
                $f->admissions,
                $f->primaryProgramLedger(),
            ),
        );
    }
}
