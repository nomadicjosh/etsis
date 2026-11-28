<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Access\Enum\GrantKind;
use Domain\Audit\Repository\AuditTrail;
use Domain\Import\Enum\CatalogImportTarget;
use Domain\Import\Service\ImportQueueService;
use Domain\Import\Service\ImportWorker;
use Domain\Import\Service\PersonImportService;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Import\LeaguePersonCsvMapper;
use Infrastructure\Import\ReviewedImportBatchProcessor;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlImportQueueRepository;
use Infrastructure\Persistence\Repository\SqlPersonImportRepository;
use Infrastructure\Security\SqlLoginAccountStateReader;
use SisFixture;

/** Private fictional composition only; all writes and rules belong to the ordinary import services. */
final readonly class ImportRecoveryServices
{
    public function __construct(private SisFixture $sis)
    {
    }

    public function prepare(): void
    {
        $this->sis->registrationService();
        $this->sis->sql->update('users', ['token' => 'fictional-command-token',
            'password' => 'fictional-non-authentication-hash'], ['user_id' => $this->sis->access->actor->value]);
        $roles = new SqlAccessRepository($this->sis->sql);
        foreach (['sis:import:manage', 'sis:person:manage'] as $name) {
            $permission = $this->sis->sql->one('sis_access_permissions', ['name' => $name]);
            $roles->assign(GrantKind::UserPermission, $this->sis->access->actor, new EntityId($permission['id']), true);
        }
    }

    public function queue(?AuditTrail $audit = null): ImportQueueService
    {
        $s = $this->sis;
        return new ImportQueueService(
            new SqlImportQueueRepository($s->sql),
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->dropCipher,
            $s->clock
        );
    }

    public function people(?AuditTrail $audit = null): PersonImportService
    {
        $s = $this->sis;
        return new PersonImportService(
            new LeaguePersonCsvMapper(),
            new SqlPersonImportRepository($s->sql, $s->admissions),
            $s->registrationService(),
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->dropCipher,
            $s->clock
        );
    }

    public function worker(?AuditTrail $audit = null, ?UnitOfWork $transactions = null): ImportWorker
    {
        $s = $this->sis;
        $roles = new SqlAccessRepository($s->sql);
        require_once dirname(__DIR__) . '/Support/PhotoFixture.php';
        $processor = new ReviewedImportBatchProcessor(
            $s->imports($audit),
            $this->people($audit),
            $roles,
            new SqlLoginAccountStateReader($s->sql),
            PhotoFixture::imports($s)
        );
        return new ImportWorker(
            new SqlImportQueueRepository($s->sql),
            $processor,
            $roles,
            $transactions ?? $s->transactions,
            $audit ?? $s->audit,
            $s->dropCipher,
            $s->clock
        );
    }

    public function review(string $target, int $count): array
    {
        if ($target === 'people') {
            $csv = "Given,Family,Email\n";
            for ($i = 0; $i < $count; $i++) {
                $csv .= "Fictional$i,Intake,recovery$i@example.invalid\n";
            }
            $request = ['csv' => $csv, 'mapping' => ['given_name' => 0, 'family_name' => 1, 'email' => 2],
                'delimiter' => ',', 'reason' => 'Fictional recovery intake'];
            $preview = $this->people()->analyze(...$request);
        } else {
            $csv = "ID,Code,Title,Credits\n";
            for ($i = 0; $i < $count; $i++) {
                $csv .= "external$i,REC.$i,Fictional definition $i,3\n";
            }
            $request = ['source' => 'fictional-recovery', 'csv' => $csv,
                'mapping' => ['external_id' => 0, 'code' => 1, 'title' => 2], 'delimiter' => ',',
                'choices' => [], 'target' => CatalogImportTarget::from($target)];
            if ($target === 'course') {
                $request['mapping']['credits'] = 3;
            }
            $preview = $this->sis->imports()->preview(...$request);
            $request['target'] = $target;
        }
        return $this->queue()->stage($target, $request, $preview);
    }
}
