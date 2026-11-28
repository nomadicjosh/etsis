<?php

declare(strict_types=1);

namespace Application\Console\Commands;

use Codefy\Framework\Console\ConsoleCommand;
use Domain\Shared\Backup\BackupManifest;
use Infrastructure\Backup\BackupConfiguration;
use Infrastructure\Backup\BackupRuntime;
use Symfony\Component\Console\Output\OutputInterface;

use function Codefy\Framework\Helpers\env;

/** Trusted deployment operator only. No browser endpoint or account impersonation. */
final class BackupCommand extends ConsoleCommand
{
    protected string $name = "sis:backup";
    protected string $description = "Create encrypted backups or restore a verified isolated recovery package.";
    protected array $options = [
        ["action", null, "required", "init, capture, list or restore.", "list"],
        [
            "config-file",
            null,
            "required",
            "Private operator JSON file; defaults to SIS_BACKUP_CONFIG_FILE.",
        ],
        [
            "quiesced",
            null,
            "none",
            "Attest that workers and every application/administrative writer are paused.",
        ],
        [
            "snapshot",
            null,
            "required",
            "Exact 64-character completed-bundle snapshot reference.",
        ],
        [
            "destination",
            null,
            "required",
            "New isolated directory below an owned private recovery directory.",
        ],
        [
            "json",
            null,
            "none",
            "Emit fixed status and opaque backup references only.",
        ],
    ];

    protected function configure(): void
    {
        parent::configure();
        $this->getDefinition()->getOption('action')->setDefault('list');
    }

    public function handle(): int
    {
        try {
            $action = $this->getOptions("action");
            $path =
            $this->getOptions("config-file") ??
            $this->codefy->configContainer->getConfigKey(
                "backups.configuration_file",
            );
            $snapshot = $this->getOptions("snapshot");
            $destination = $this->getOptions("destination");
            $quiesced = $this->getOptions("quiesced") === true;
            if (
                !in_array(
                    $action,
                    ["init", "capture", "list", "restore"],
                    true,
                ) ||
                !is_string($path) ||
                $path === "" ||
                ($action === "capture" && !$quiesced) ||
                ($action !== "capture" && $quiesced) ||
                ($action === "restore" &&
                    (!is_string($snapshot) ||
                        !is_string($destination) ||
                        $destination === "")) ||
                ($action !== "restore" &&
                    ($snapshot !== null || $destination !== null))
            ) {
                throw new \InvalidArgumentException();
            }
            if ($action === "restore") {
                BackupManifest::snapshot($snapshot);
            }
        } catch (\Throwable) {
            return $this->result(["status" => "invalid_input"], self::INVALID);
        }
        try {
            $keyring = env("EVENT_HISTORY_KEYS_FILE");
            $config = BackupConfiguration::load(
                $path,
                $this->codefy->basePath(),
                is_string($keyring) ? $keyring : null,
                $action === "capture",
            );
            $runtime = $this->codefy->make(BackupRuntime::class);
            $budget = $config->budget();
            $result = match ($action) {
                "init" => $this->initializeRepository(
                    $runtime,
                    $config,
                    $budget,
                ),
                "list" => [
                    "status" => "listed",
                    "items" => $runtime->engine($config)->recent($budget),
                ],
                "capture" => $runtime
                    ->service($config)
                    ->capture($config->plan, $budget, $quiesced),
                "restore" => $runtime
                    ->service($config)
                    ->restore(
                        $snapshot,
                        $destination,
                        $config->plan->scope,
                        $budget,
                    ),
            };
            return $this->result($result, self::SUCCESS);
        } catch (\Throwable) {
            // No paths, process stderr, DSNs, credentials, manifests, keys or nested exceptions leave this boundary.
            return $this->result(["status" => "not_completed"], self::FAILURE);
        }
    }

    private function initializeRepository(
        BackupRuntime $runtime,
        BackupConfiguration $config,
        \Domain\Shared\Backup\BackupBudget $budget,
    ): array {
        $runtime->engine($config)->initialize($budget);
        return ["status" => "initialized"];
    }

    private function result(array $result, int $status): int
    {
        if ($this->getOptions("json") === true) {
            $this->output->writeln(
                json_encode($result, JSON_THROW_ON_ERROR),
                OutputInterface::OUTPUT_RAW,
            );
        } else {
            $this->terminalRaw(
                match ($result["status"]) {
                    "initialized"
                        => "Encrypted backup repository initialized. Retain its independent recovery password.",
                    "captured"
                        => "Encrypted database/private-file bundle captured with a retained history checkpoint.",
                    "listed" => "Recent completed encrypted backup bundles:",
                    "restored"
                        => "Isolated recovery package verified. No application database was changed or activated.",
                    "invalid_input"
                        => "Use --action=init|list|capture|restore and a private --config-file. Capture requires " .
                        "--quiesced; restore requires --snapshot and --destination.",
                    default
                        => "Backup operation not completed. Check the documented private configuration, tools, " .
                        "quiesced boundary and limits. Preserve incomplete recovery output for review.",
                },
            );
            if (isset($result['bundle'])) {
                $this->terminalRaw('Bundle: ' . $result['bundle']);
            }
            if (isset($result["snapshot"])) {
                $this->terminalRaw('Snapshot: ' . $result["snapshot"]);
            }
            foreach ($result["items"] ?? [] as $item) {
                $this->terminalRaw($item["snapshot"] . " " . $item["time"]);
            }
        }
        return $status;
    }
}
