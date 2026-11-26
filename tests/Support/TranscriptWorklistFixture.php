<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Access\Enum\GrantKind;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transcript\Service\TranscriptWorklistService;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use Infrastructure\Persistence\Repository\SqlTranscriptWorklistRepository;

final class TranscriptWorklistFixture
{
    public \TranscriptFixture $transcripts;
    public EntityId $request;
    public SqlAccessRepository $permissions;
    public function __construct()
    {
        require_once __DIR__ . "/TranscriptFixture.php";
        $this->transcripts = new \TranscriptFixture();
        $this->request = $this->transcripts->request();
        $this->permissions = new SqlAccessRepository($this->transcripts->sis->sql);
        $this->grant($this->transcripts->sis->access->actor);
    }
    public function grant(EntityId $user, array $duties = TranscriptWorklistService::DUTIES): void
    {
        $f = $this->transcripts->sis;
        $f->sql->update("users", ["sign_in_enabled" => 1], ["user_id" => $user->value]);
        foreach ($duties as $duty) {
            $permission = new EntityId($f->sql->one("sis_access_permissions", ["name" => $duty])["id"]);
            $this->permissions->assign(GrantKind::UserPermission, $user, $permission, true);
        }
    }
    public function colleague(string $name = "Registrar", array $duties = TranscriptWorklistService::DUTIES): array
    {
        $f = $this->transcripts->sis;
        $user = $f->user();
        $person = $f->legacyPerson($user, "Fictional", $name);
        $this->grant($user, $duties);
        return [$user, $person->id];
    }
    public function service(?AuditTrail $audit = null): TranscriptWorklistService
    {
        $f = $this->transcripts->sis;
        return new TranscriptWorklistService(
            new SqlTranscriptWorklistRepository($f->sql),
            $this->transcripts->repository,
            $f->admissions,
            new SqlPersonAccountRepository($f->sql, $f->admissions, new RandomInstitutionalIdGenerator()),
            $this->permissions,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $f->clock,
        );
    }
}
