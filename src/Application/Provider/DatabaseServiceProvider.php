<?php

declare(strict_types=1);

namespace Application\Provider;

use Application\Service\DatabaseService;
use Codefy\Framework\Support\CodefyServiceProvider;
use Qubus\Expressive\Database;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Connection\DriverConnection;
use Infrastructure\Persistence\SqliteConnection;

class DatabaseServiceProvider extends CodefyServiceProvider
{
    public function register(): void
    {
        $this->codefy->singleton(Connection::class, function (): Connection {
            $config = $this->codefy->configContainer;
            $name = $config->getConfigKey("database.default");
            $settings = $config->getConfigKey("database.connections.{$name}");
            if (
                is_array($settings) &&
                in_array($settings["driver"] ?? null, ["sqlite", "pdo_sqlite"], true)
            ) {
                $settings["driver"] = "pdo_sqlite";
                return new SqliteConnection($settings);
            }
            return DriverConnection::make($settings);
        });
        $this->codefy->share(nameOrInstance: Connection::class);
        $this->codefy->singleton(
            key: Database::class,
            value: function () {
                return DatabaseService::fromInstance($this->codefy->getDbConnection());
            },
        );
    }
}
