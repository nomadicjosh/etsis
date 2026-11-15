<?php

declare(strict_types=1);

require_once __DIR__ . '/SisFixture.php';

use Domain\Admissions\Service\AdmissionsService;
use Domain\Person\Person;
use Domain\Person\Service\PersonProfileEditor;
use Domain\User\Service\PersonAccountEditor;
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
        $history = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(EventStoreFixture::config($db)),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry()
        );
        $this->users = new EventSourcedUserRepository(
            EventStoreFixture::framework($db),
            new ExpressiveDbalUserProjection($db, $this->access, $people),
            $db,
            $history,
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
            $f->profileLedger(),
            new \Infrastructure\Persistence\Repository\SqlPersonProfileRepository($f->sql),
        );
        $this->profiles = new PersonProfileEditor(
            $f->admissions,
            new SqlPersonProfileRepository($f->sql),
            $this->users,
            $this->guard,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->profileLedger(),
        );
        $this->accounts = new PersonAccountEditor(
            $f->admissions,
            $this->users,
            $this->guard,
            $f->access,
            $f->audit,
            $f->profileLedger(),
            $history
        );
    }

    public function person(bool $account = true): Person
    {
        $f = $this->sis;
        $name = 'fixture_' . chr(97 + ++$this->counter);
        // Fictional pre-retrofit profile/account evidence for observation tests.
        $person = new Person(
            \Domain\Shared\ValueObject\EntityId::generate(),
            null,
            'Alex',
            'Example',
            new \Domain\Person\ValueObject\InstitutionalId('T-' . $this->counter)
        );
        $f->sql->insert('sis_persons', ['id' => $person->id->value, 'owner_user_id' => null,
            'given_name' => 'Alex', 'family_name' => 'Example', 'directory_restricted' => 1]);
        $f->sql->insert('sis_person_identifiers', ['id' => \Domain\Shared\ValueObject\EntityId::generate()->value,
            'person_id' => $person->id->value, 'institutional_id' => $person->institutionalId->value, 'version' => 0]);
        if ($account) {
            $user = \Domain\User\User::createUser(
                new \Domain\User\ValueObject\UserId(),
                new \Domain\User\ValueObject\Username($name),
                new \Domain\User\ValueObject\UserToken(),
                new \Qubus\ValueObjects\Person\Name(
                    new \Qubus\ValueObjects\StringLiteral\StringLiteral('Alex'),
                    new \Qubus\ValueObjects\StringLiteral\StringLiteral(''),
                    new \Qubus\ValueObjects\StringLiteral\StringLiteral('Example')
                ),
                new \Qubus\ValueObjects\Web\EmailAddress($name . '@example.invalid'),
                new \Domain\User\ValueObject\UserRole('user'),
                new \Qubus\ValueObjects\StringLiteral\StringLiteral(\Codefy\Framework\Support\Password::hash(self::PASSWORD)),
                $f->clock->now(),
                $person->id
            );
            $this->users->saveAggregateRoot($user);
        }
        return $f->admissions->person($person->id);
    }
}
