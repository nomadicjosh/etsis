<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Access\Enum\GrantKind;
use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Person;
use Domain\Person\Service\PersonProfileAccess;
use Domain\Person\Service\PersonSsnService;
use Domain\Person\ValueObject\SocialSecurityNumber;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use Infrastructure\Persistence\Repository\SqlPersonProfileRepository;
use Infrastructure\Persistence\Repository\SqlPersonSsnRepository;

final class PersonSsnFixture
{
    public \SisFixture $sis;
    public Person $person;
    public SqlAccessRepository $roles;

    public function __construct()
    {
        require_once __DIR__ . '/SisFixture.php';
        $this->sis = new \SisFixture();
        $this->roles = new SqlAccessRepository($this->sis->sql);
        $this->person = $this->sis->legacyPerson($this->sis->user(), 'Fictional', 'Profile');
        $this->sis->sql->update('users', ['sign_in_enabled' => 1], ['user_id' => $this->sis->access->actor->value]);
        foreach (['sis:person:manage', PersonSsnService::READ, PersonSsnService::MANAGE, 'sis:audit:read'] as $duty) {
            $this->grant($duty, true);
        }
    }

    public function grant(string $duty, bool $grant): void
    {
        $id = new EntityId($this->sis->sql->one('sis_access_permissions', ['name' => $duty])['id']);
        $this->roles->assign(GrantKind::UserPermission, $this->sis->access->actor, $id, $grant);
    }

    public function service(?AuditTrail $audit = null): PersonSsnService
    {
        $s = $this->sis;
        return new PersonSsnService(
            new SqlPersonSsnRepository($s->sql),
            new SqlPersonProfileRepository($s->sql),
            new PersonProfileAccess(new SqlPersonAccountRepository(
                $s->sql,
                $s->admissions,
                new RandomInstitutionalIdGenerator()
            ), $s->admissions, $this->roles, $s->access),
            $this->roles,
            $s->access,
            \EventStoreFixture::history($s->connection->queryBuilder()),
            $s->dropCipher,
            new ReviewProof($s->dropCipher, $s->clock),
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock
        );
    }

    public function review(?string $digits = '000001234', int $version = 0): array
    {
        return $this->service()->review(
            $this->person->id,
            $version,
            $digits === null ? null : new SocialSecurityNumber($digits),
            'Fictional verified correction.'
        );
    }

    public function confirm(array $review, ?string $digits = '000001234', ?AuditTrail $audit = null): array
    {
        return $this->service($audit)->confirm(
            $this->person->id,
            new EntityId($review['operation_id']),
            $review['version'],
            $digits === null ? null : new SocialSecurityNumber($digits),
            $review['reason'],
            $review['review_token']
        );
    }

    public function facts(): array
    {
        return array_map($this->sis->sql->rows(...), ['sis_person_ssns', 'sis_history_events',
            'sis_history_streams', 'sis_history_operations', 'sis_persons', 'sis_person_identifiers', 'users',
            'sis_access_user_permissions', 'sis_access_user_roles']);
    }
}
