<?php

declare(strict_types=1);

require_once __DIR__ . '/SisFixture.php';

use Domain\Admissions\Service\AdmissionsService;
use Domain\Person\Person;
use Domain\Person\Service\PersonProfileEditor;
use Domain\User\Service\PersonAccountEditor;
use Domain\User\Service\PersonAccountService;
use Domain\User\Service\UserAccountAccess;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\PdoTransactionalEventStore;
use Infrastructure\Persistence\Repository\EventSourcedUserRepository;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use Infrastructure\Persistence\Repository\SqlPersonProfileRepository;
use Infrastructure\Projection\ExpressiveDbalUserProjection;

final class PersonManagementFixture
{
    public const string PASSWORD = 'fictional-test-password-for-an-account';
    public SisFixture $sis;
    public SqlAccessRepository $access;
    public EventSourcedUserRepository $users;
    public PersonProfileEditor $profiles;
    public PersonAccountEditor $accounts;
    public UserAccountAccess $guard;
    public AdmissionsService $admissions;
    private int $counter = 0;

    public function __construct()
    {
        $this->sis = $f = new SisFixture();
        foreach (['username', 'token', 'middle_name', 'password', 'created_on'] as $column) {
            $f->connection->pdo->exec('ALTER TABLE users ADD COLUMN ' . $column . ' VARCHAR(191)');
        }
        $f->connection->pdo->exec('CREATE UNIQUE INDEX fictional_user_email ON users (email)');
        $db = $f->connection->queryBuilder();
        $this->access = new SqlAccessRepository($f->sql);
        $people = new SqlPersonAccountRepository($f->sql, $f->admissions, new RandomInstitutionalIdGenerator());
        $this->users = new EventSourcedUserRepository(
            EventStoreFixture::framework($db),
            new ExpressiveDbalUserProjection($db, $this->access, $people),
            $db
        );
        $this->guard = new UserAccountAccess($this->access, $f->access, $f->transactions);
        $this->admissions = new AdmissionsService(
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->audit,
            new RandomInstitutionalIdGenerator(),
            $f->decisionLedger(),
            $f->submissionService(),
        );
        $this->profiles = new PersonProfileEditor(
            $f->admissions,
            new SqlPersonProfileRepository($f->sql),
            $this->users,
            $this->guard,
            $f->access,
            $f->transactions,
            $f->audit
        );
        $this->accounts = new PersonAccountEditor($f->admissions, $this->users, $this->guard, $f->access, $f->audit);
    }

    public function person(bool $account = true): Person
    {
        $f = $this->sis;
        $name = 'fixture_' . chr(97 + ++$this->counter);
        $person = $this->admissions->createPerson(null, 'Alex', 'Example', 'T-' . $this->counter);
        if ($account) {
            $service = new PersonAccountService(
                $f->admissions,
                $this->users,
                $f->access,
                $f->transactions,
                $f->audit,
                new SqlPersonProfileRepository($f->sql)
            );
            $service->enable($person->id, $name, $name . '@example.invalid', self::PASSWORD);
        }

        return $f->admissions->person($person->id);
    }
}
