<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Audit\Repository\AuditTrail;
use Domain\Portal\Service\ProgramDiscoveryService;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlCatalogYearRepository;
use Infrastructure\Persistence\Repository\SqlCampusRepository;
use Infrastructure\Persistence\Repository\SqlProgramDefinitionRepository;
use Infrastructure\Persistence\Repository\SqlProgramDiscoveryRepository;
use Infrastructure\Portal\SymfonyContentMarkup;
use ProgramPublicationFixture;
use SisFixture;

final class ProgramDiscoveryFixture
{
    public ProgramPublicationFixture $publication;
    public SisFixture $sis;
    private ?array $year = null;

    public function __construct(?SisFixture $sis = null)
    {
        require_once __DIR__ . '/ProgramPublicationFixture.php';
        $this->publication = new ProgramPublicationFixture($sis);
        $this->sis = $this->publication->sis;
    }

    public function service(?AuditTrail $audit = null): ProgramDiscoveryService
    {
        $s = $this->sis;
        return new ProgramDiscoveryService(
            new SqlProgramDiscoveryRepository($s->sql),
            new SqlCatalogRevisionRepository($s->sql),
            new SqlCatalogYearRepository($s->sql),
            new SqlProgramDefinitionRepository($s->sql),
            $this->publication->service,
            new SqlCampusRepository($s->sql, $s->clock, new SymfonyContentMarkup()),
            $s->access,
            new SqlAccessRepository($s->sql),
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
            new ReviewProof($s->dropCipher, $s->clock),
        );
    }

    public function publish(?EntityId $program = null, string $title = 'Fictional Arts program'): EntityId
    {
        $program ??= $this->sis->program;
        $service = $this->publication->service;
        $draft = $service->draft($program);
        $service->save(
            $program,
            $draft['definition']['code'],
            $title,
            'Fictional published description <script>literal</script>.',
            null,
            null,
            null,
            $draft['revision']
        );
        $year = $this->year ??= $this->publication->calendar->year(2024);
        $plan = $service->review($program, new EntityId($year['id']), 'Fictional academic publication.');
        return new EntityId($service->publish($program, $plan['review_token'])['operation_id']);
    }

    public function commit(array $plan, ?ProgramDiscoveryService $service = null): array
    {
        return ($service ?? $this->service())->commit(
            $this->sis->program,
            $plan['catalog_id'] === null ? null : new EntityId($plan['catalog_id']),
            $plan['visible'],
            $plan['version'],
            new EntityId($plan['operation_id']),
            $plan['revision'],
            $plan['review_token'],
        );
    }
}
