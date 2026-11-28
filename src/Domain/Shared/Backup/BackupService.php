<?php

declare(strict_types=1);

namespace Domain\Shared\Backup;

use Domain\Shared\Operations\MaintenanceMode;
use RuntimeException;

/** Deployment operations: no account impersonation, business mutations, replay or repair. */
final readonly class BackupService
{
    public function __construct(
        private BackupEngine $engine,
        private BackupFiles $files,
        private BackupHistory $history,
        private MaintenanceMode $maintenance,
    ) {
    }

    public function capture(
        BackupPlan $plan,
        BackupBudget $budget,
        bool $quiesced,
    ): array {
        return $this->files->exclusively(function () use (
            $plan,
            $budget,
            $quiesced,
        ): array {
            $state = $this->maintenance->status();
            if (!$quiesced || $state->status !== "down") {
                throw new RuntimeException(
                    "Pause writes and workers before creating a backup.",
                );
            }
            $inventory = $this->files->inventory($plan->includes, $budget);
            $anchor = $this->history->capture($plan->scope, $plan->historyRows);
            $id = $anchor->data["id"];
            $this->history->retain($anchor, $plan);
            $database = $this->engine->database($id, $budget);
            $files = $this->engine->files($id, $plan->includes, $budget);
            $digest = $this->engine->databaseDigest($database, $budget);
            if (
                $inventory !==
                    $this->files->inventory($plan->includes, $budget) ||
                !$this->history->compare(
                    $anchor,
                    $plan->scope,
                    $plan->historyRows,
                ) ||
                $this->maintenance->status() != $state
            ) {
                throw new RuntimeException(
                    "The backup boundary changed. No complete bundle was published.",
                );
            }
            $manifest = new BackupManifest([
                "format" => 1,
                "id" => $id,
                "scope" => $plan->scope,
                "release" => $plan->release,
                "driver" => $this->engine->driver(),
                "database" => $database,
                "files" => $files,
                "database_digest" => $digest,
                "inventory" => $inventory,
                "anchor" => $anchor->data,
            ]);
            $snapshot = $this->engine->commit($manifest, $budget);
            return [
                "status" => "captured",
                "bundle" => $id,
                "snapshot" => $snapshot,
                "files" => count($inventory),
            ];
        });
    }

    public function restore(
        string $snapshot,
        string $destination,
        string $scope,
        BackupBudget $budget,
    ): array {
        BackupManifest::snapshot($snapshot);
        return $this->files->exclusively(function () use (
            $snapshot,
            $destination,
            $scope,
            $budget,
        ): array {
            $manifest = $this->engine->read($snapshot, $budget);
            if (!hash_equals($manifest->data["scope"], $scope)) {
                throw new RuntimeException(
                    "The backup belongs to a different installation scope.",
                );
            }
            if (
                count($manifest->data["inventory"]) > $budget->files ||
                array_sum(array_column($manifest->data["inventory"], "bytes")) >
                    $budget->bytes ||
                $manifest->data["database_digest"]["bytes"] > $budget->bytes
            ) {
                throw new RuntimeException(
                    "The restored bundle exceeds its allowance.",
                );
            }
            $this->files->createDestination($destination);
            $this->engine->restore($manifest, $destination, $budget);
            $this->files->verifyRestore($destination, $manifest, $budget);
            $this->files->markRestore(
                $destination,
                $snapshot,
                $manifest->data["id"],
            );
            return [
                "status" => "restored",
                "bundle" => $manifest->data["id"],
                "files" => count($manifest->data["inventory"]),
                "database_changed" => false,
                "activated" => false,
            ];
        });
    }
}
