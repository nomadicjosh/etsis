<?php

declare(strict_types=1);

use Application\Service\DatabaseService;
use Codefy\CommandBus\Container;
use Codefy\CommandBus\Containers\InjectorContainer;
use Codefy\Domain\EventSourcing\TransactionalEventStore;
use Domain\User\Repository\UserAggregateRepository;
use Domain\User\Service\UserProjection;
use Qubus\Config\Collection;
use Qubus\Expressive\Database;
use Qubus\Injector\Injector;

use function Codefy\Framework\Helpers\app;
use function Codefy\Framework\Helpers\base_path;
use function Codefy\Framework\Helpers\config_path;
use function Codefy\Framework\Helpers\dbal;
use function Codefy\Framework\Helpers\env;

return [
    "container" => [
        Injector::DELEGATIONS => [
            // Share application bindings, encryption configuration and transaction boundaries.
            UserAggregateRepository::class => static fn() => app()->make(UserAggregateRepository::class),
            UserProjection::class => static fn() => app()->make(UserProjection::class),
            TransactionalEventStore::class => static fn() => app()->make(TransactionalEventStore::class),
            Domain\Person\Repository\PersonAccountRepository::class => static fn() => app()->make(
                Domain\Person\Repository\PersonAccountRepository::class,
            ),
            Domain\Access\Repository\AccessRepository::class => static fn() => app()->make(
                Domain\Access\Repository\AccessRepository::class,
            ),
            Domain\User\Service\UserAccountAccess::class => static fn() => app()->make(
                Domain\User\Service\UserAccountAccess::class,
            ),
            Domain\Admissions\Service\MatriculationService::class => static fn() => app()->make(
                Domain\Admissions\Service\MatriculationService::class,
            ),
            Domain\Registration\Service\RegistrationService::class => static fn() => app()->make(
                Domain\Registration\Service\RegistrationService::class,
            ),
            Domain\Transfer\Service\TransferService::class => static fn() => app()->make(
                Domain\Transfer\Service\TransferService::class,
            ),
            Domain\Shared\Security\StudentAccess::class => static fn() => app()->make(
                Domain\Shared\Security\StudentAccess::class,
            ),
        ],
        Injector::ARGUMENT_DEFINITIONS => [
            Collection::class => [
                "config" => [
                    "path" => config_path(),
                    "dotenv" => base_path(),
                    "environment" => env(key: "APP_ENV", default: "local"),
                ],
            ],
            DatabaseService::class => [
                "connection" => dbal(),
                "tablePrefix" => env(key: "DB_TABLE_PREFIX", default: ""),
                "primaryKeyName" => "id",
                "foreignKeyName" => "%s_id",
            ],
        ],
        Injector::STANDARD_ALIASES => [
            Domain\Admissions\Repository\AdmissionsRepository::class =>
                Infrastructure\Persistence\Repository\PdoAdmissionsRepository::class,
            Container::class => InjectorContainer::class,
            Database::class => DatabaseService::class,
        ],
    ],
];
