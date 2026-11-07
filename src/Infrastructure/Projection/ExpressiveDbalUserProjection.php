<?php

declare(strict_types=1);

namespace Infrastructure\Projection;

use Codefy\Domain\EventSourcing\BaseProjection;
use Domain\Access\Repository\AccessRepository;
use Domain\User\Event\EmailAddressWasChanged;
use Domain\User\Event\NameWasChanged;
use Domain\User\Event\PasswordWasChanged;
use Domain\User\Event\RoleWasChanged;
use Domain\User\Event\UserWasCreated;
use Domain\User\Event\UserWasDeleted;
use Domain\User\Service\UserProjection;
use Exception;
use Qubus\Exception\Data\TypeException;
use Qubus\Expressive\Database;
use Qubus\Expressive\QueryBuilderException;

final class ExpressiveDbalUserProjection extends BaseProjection implements UserProjection
{
    public function __construct(
        private readonly Database $db,
        private readonly AccessRepository $access,
        private readonly \Domain\Person\Repository\PersonAccountRepository $people
    ) {
    }

    /**
     * @throws TypeException
     * @throws Exception
     */
    public function projectWhenUserWasCreated(UserWasCreated $event): void
    {
        try {
            \Infrastructure\Persistence\PortableTransactions::run(db: $this->db, callback: function () use ($event) {
                $this->db
                    ->table(tableName: 'users')
                    ->set([
                        'user_id' => $event->userId()->__toString(),
                        'username' => $event->username()->__toString(),
                        'token' => $event->token()->__toString(),
                        'first_name' => $event->name()->getFirstName()->toNative(),
                        'middle_name' => $event->name()->getMiddleName()->toNative(),
                        'last_name' => $event->name()->getLastName()->toNative(),
                        'email' => $event->emailAddress()->toNative(),
                        'role' => 'user',
                        'password' => $event->password()->toNative(),
                        'created_on' => $event->createdOn()->format('Y-m-d H:i:s'),
                ])
                ->save();
                // RoleWasChanged remains legacy profile metadata; access assignments have their own boundary.
                $this->access->assignDefaultRole($event->userId()->__toString());
                $personId = $event->payload()['person_id'] ?? null;
                $this->people->ensure(
                    new \Domain\Shared\ValueObject\EntityId((string) $event->userId()),
                    $event->name()->getFirstName()->toNative(),
                    $event->name()->getLastName()->toNative(),
                    is_string($personId) ? new \Domain\Shared\ValueObject\EntityId($personId) : null
                );
            });
        } catch (QueryBuilderException $e) {
            throw new Exception(message: $e->getMessage());
        }
    }

    /**
     * @throws TypeException
     * @throws Exception
     */
    public function projectWhenEmailAddressWasChanged(EmailAddressWasChanged $event): void
    {
        try {
            \Infrastructure\Persistence\PortableTransactions::run(db: $this->db, callback: function () use ($event) {
                $this->db
                    ->table(tableName: 'users')
                    ->set([
                        'email' => $event->emailAddress()->toNative(),
                ])
                ->where('user_id = ?', $event->userId()->__toString())
                ->update();
            });
        } catch (QueryBuilderException $e) {
            throw new Exception(message: $e->getMessage());
        }
    }

    /**
     * @throws TypeException
     * @throws Exception
     */
    public function projectWhenNameWasChanged(NameWasChanged $event): void
    {
        try {
            \Infrastructure\Persistence\PortableTransactions::run(db: $this->db, callback: function () use ($event) {
                $this->db
                    ->table(tableName: 'users')
                    ->set([
                        'first_name' => $event->firstName(),
                        'middle_name' => $event->middleName(),
                        'last_name' => $event->lastName(),
                ])
                ->where('user_id = ?', $event->userId()->__toString())
                ->update();
                $this->people->updateNames(
                    new \Domain\Shared\ValueObject\EntityId((string) $event->userId()),
                    $event->firstName(),
                    $event->lastName()
                );
            });
        } catch (QueryBuilderException $e) {
            throw new Exception(message: $e->getMessage());
        }
    }

    /**
     * @throws TypeException
     * @throws Exception
     */
    public function projectWhenRoleWasChanged(RoleWasChanged $event): void
    {
        try {
            \Infrastructure\Persistence\PortableTransactions::run(db: $this->db, callback: function () use ($event) {
                $this->db
                    ->table(tableName: 'users')
                    ->set([
                        'role' => $event->role()->toNative(),
                    ])
                    ->where('user_id = ?', $event->userId()->__toString())
                    ->update();
            });
        } catch (QueryBuilderException $e) {
            throw new Exception(message: $e->getMessage());
        }
    }

    /**
     * @throws TypeException
     * @throws Exception
     */
    public function projectWhenPasswordWasChanged(PasswordWasChanged $event): void
    {
        try {
            \Infrastructure\Persistence\PortableTransactions::run(db: $this->db, callback: function () use ($event) {
                $this->db
                    ->table(tableName: 'users')
                    ->set([
                        'password' => $event->password()->toNative(),
                        'token' => $event->token()->toNative(),
                ])
                ->where('user_id = ?', $event->userId()->__toString())
                ->update();
            });
        } catch (QueryBuilderException $e) {
            throw new Exception(message: $e->getMessage());
        }
    }

    /**
     * @throws Exception
     */
    public function projectWhenUserWasDeleted(UserWasDeleted $event): void
    {
        try {
            \Infrastructure\Persistence\PortableTransactions::run(db: $this->db, callback: function () use ($event) {
                $this->access->removeUserAssignments($event->userId()->toNative());
                $this->db
                    ->table(tableName: 'users')
                    ->where('user_id = ?', $event->userId()->toNative())
                    ->delete();
            });
        } catch (QueryBuilderException $e) {
            throw new Exception(message: $e->getMessage());
        }
    }
}
