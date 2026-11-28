<?php

declare(strict_types=1);

namespace Infrastructure\Backup;

use Domain\Shared\Backup\BackupHistory;
use Domain\Shared\Backup\BackupService;
use Domain\Shared\Operations\MaintenanceMode;
use Qubus\Config\ConfigContainer;

/** Per-invocation composition of explicit private recovery configuration and lazy domain ports. */
final readonly class BackupRuntime
{
    public function __construct(
        private ConfigContainer $configuration,
        private MaintenanceMode $maintenance,
        private BackupHistory $history,
        private string $publicDirectory,
    ) {
    }

    public function engine(BackupConfiguration $config): ResticBackupEngine
    {
        $name = $this->configuration->getConfigKey("database.default");
        $connection = is_string($name)
        ? $this->configuration->getConfigKey(
            "database.connections." . $name,
            [],
        )
        : [];
        return new ResticBackupEngine(
            $config,
            new ResticProcess($config),
            new NativeDatabaseSource(
                is_array($connection) ? $connection : [],
                $config->tools,
            ),
            new PrivateBackupPaths($this->publicDirectory),
        );
    }

    public function service(BackupConfiguration $config): BackupService
    {
        return new BackupService(
            $this->engine($config),
            new LocalBackupFiles(
                new PrivateBackupPaths($this->publicDirectory),
                $config->workDirectory,
            ),
            $this->history,
            $this->maintenance,
        );
    }
}
