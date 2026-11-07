<?php

declare(strict_types=1);

use Application\Service\DatabaseService;
use Codefy\CommandBus\Container;
use Codefy\CommandBus\Containers\InjectorContainer;
use Codefy\Framework\Proxy\Codefy;
use Qubus\Config\Collection;
use Qubus\Config\ConfigContainer;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Database;
use Qubus\Injector\Injector;

use function Codefy\Framework\Helpers\app;
use function Codefy\Framework\Helpers\base_path;
use function Codefy\Framework\Helpers\config_path;
use function Codefy\Framework\Helpers\dbal;
use function Codefy\Framework\Helpers\env;

return [
    'aliases' => [
        Injector::DELEGATIONS => [
            Domain\Admissions\Service\AdmissionsService::class => static fn () => app()->make(
                Domain\Admissions\Service\AdmissionsService::class,
            ),
            Domain\Registration\Service\RegistrationService::class => static fn () => app()->make(
                Domain\Registration\Service\RegistrationService::class,
            ),
            Domain\Transfer\Service\TransferService::class => static fn () => app()->make(
                Domain\Transfer\Service\TransferService::class,
            ),
            Domain\Shared\Security\StudentAccess::class => static fn () => app()->make(
                Domain\Shared\Security\StudentAccess::class,
            ),
        ],
        Injector::ARGUMENT_DEFINITIONS => [
            Collection::class => [
                'config' => [
                    'path' => config_path(),
                    'dotenv' => base_path(),
                    'environment' => env(key: 'APP_ENV', default: 'local'),
                ],
            ],
            DatabaseService::class => [
                'connection' => dbal(),
                'tablePrefix' => env(key: 'DB_TABLE_PREFIX', default: ''),
                'primaryKeyName' => 'id',
                'foreignKeyName' => '%s_id'
            ],
        ],
        Injector::STANDARD_ALIASES => [
            Domain\Admissions\Repository\AdmissionsRepository::class =>
                Infrastructure\Persistence\Repository\PdoAdmissionsRepository::class,
            Container::class => InjectorContainer::class,
            ConfigContainer::class => Collection::class,
            Database::class => DatabaseService::class,
        ],
    ],
];
