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

    public function __construct()
    {
        $this->sis = $f = new SisFixture();
        $config = Collection::factory([]);
        $config->setConfigKey("app", ["crypto_key" => Key::createNewRandomKey()->saveToAsciiSafeString()]);
        $this->cipher = new DefuseMessageCipher($config);
        $this->repository = new SqlHiatusRepository($f->sql);
        $this->history = $this->service();
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
        );
    }

    public function returns(?AuditTrail $audit = null): \Domain\Student\Service\HiatusReturnService
    {
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
        );
    }

    public function workflow(?AuditTrail $audit = null, ?HiatusPolicy $policy = null): HiatusWorkflowService
    {
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
        );
    }
}
