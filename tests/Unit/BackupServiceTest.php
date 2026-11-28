<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Shared\Backup\BackupBudget;
use Domain\Shared\Backup\BackupEngine;
use Domain\Shared\Backup\BackupFiles;
use Domain\Shared\Backup\BackupHistory;
use Domain\Shared\Backup\BackupManifest;
use Domain\Shared\Backup\BackupPlan;
use Domain\Shared\Backup\BackupService;
use Domain\Shared\EventSourcing\HistoryAnchor;
use Domain\Shared\Operations\MaintenanceMode;
use Domain\Shared\Operations\MaintenanceState;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackupServiceTest extends TestCase
{
    private function anchor(): HistoryAnchor
    {
        return new HistoryAnchor([
            "format" => 1,
            "id" => EntityId::generate()->value,
            "scope" => "fictional-campus",
            "captured_at" => "2026-11-27T00:00:00Z",
            "inventory" => [
                "streams" => 0,
                "events" => 0,
                "operations" => 0,
                "digest" => str_repeat("a", 64),
            ],
        ]);
    }

    #[DataProvider("stoppedCaptures")]
    public function testAnIncompleteCaptureNeverPublishesACompletedBundle(
        string $failure,
    ): void {
        $engine = $this->createMock(BackupEngine::class);
        $files = $this->createStub(BackupFiles::class);
        $history = $this->createStub(BackupHistory::class);
        $maintenance = $this->createStub(MaintenanceMode::class);
        $down = MaintenanceState::down(
            300,
            new \DateTimeImmutable("2026-11-27T00:00:00Z"),
        );
        $maintenance
            ->method("status")
            ->willReturnOnConsecutiveCalls(
                $failure === "up" ? MaintenanceState::up() : $down,
                $failure === "maintenance_changed"
                    ? MaintenanceState::up()
                    : $down,
            );
        $files
            ->method("exclusively")
            ->willReturnCallback(
                static fn(callable $operation) => $operation(),
            );
        $inventory = [
            [
                "path" => "/private/original",
                "bytes" => 10,
                "sha256" => str_repeat("a", 64),
            ],
        ];
        $files->method("inventory")->willReturnOnConsecutiveCalls(
            $inventory,
            $failure === "files_changed"
                ? [
                    [
                        "path" => "/private/other",
                        "bytes" => 10,
                        "sha256" => str_repeat("b", 64),
                    ],
                ]
                : $inventory,
        );
        $history->method("capture")->willReturn($this->anchor());
        $history->method("compare")->willReturn($failure !== "journal_changed");
        foreach (["database", "files", "databaseDigest"] as $method) {
            if ($failure === $method) {
                $engine
                    ->method($method)
                    ->willThrowException(
                        new \RuntimeException("Fictional interrupted stage."),
                    );
            } elseif ($method === "databaseDigest") {
                $engine->method($method)->willReturn([
                    "bytes" => 10,
                    "sha256" => str_repeat("a", 64),
                ]);
            } else {
                $engine
                    ->method($method)
                    ->willReturn(
                        str_repeat($method === "files" ? "b" : "a", 64),
                    );
            }
        }
        $engine->expects(self::never())->method("commit");
        $service = new BackupService($engine, $files, $history, $maintenance);
        $this->expectException(\RuntimeException::class);
        $service->capture(
            new BackupPlan(
                "fictional-campus",
                "reviewed-release",
                ["/private/original"],
                "/recovery",
                "/key",
            ),
            new BackupBudget(10, 100, 10),
            true,
        );
    }

    public static function stoppedCaptures(): array
    {
        return array_map(static fn(string $failure): array => [$failure], [
            "up",
            "database",
            "files",
            "databaseDigest",
            "files_changed",
            "journal_changed",
            "maintenance_changed",
        ]);
    }

    public function testOpaqueManifestsCannotRevealPrivatePathsThroughJsonOrDebugging(): void
    {
        $anchor = $this->anchor();
        $manifest = new BackupManifest([
            "format" => 1,
            "id" => $anchor->data["id"],
            "scope" => "fictional-campus",
            "release" => "reviewed-release",
            "driver" => "pgsql",
            "database" => str_repeat("a", 64),
            "files" => str_repeat("b", 64),
            "database_digest" => [
                "bytes" => 10,
                "sha256" => str_repeat("a", 64),
            ],
            "inventory" => [
                [
                    "path" => "/fictional-private/application.key",
                    "bytes" => 10,
                    "sha256" => str_repeat("c", 64),
                ],
            ],
            "anchor" => $anchor->data,
        ]);
        self::assertStringNotContainsString(
            "application.key",
            json_encode($manifest, JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString(
            "application.key",
            print_r($manifest, true),
        );
        self::assertStringNotContainsString(
            "application.key",
            serialize($manifest),
        );
    }

    #[DataProvider("invalidReferences")]
    public function testRestoreRequiresAnExactReferenceBeforeAnyRepositoryOrDestinationAccess(
        string $reference,
    ): void {
        $engine = $this->createMock(BackupEngine::class);
        $engine->expects(self::never())->method("read");
        $service = new BackupService(
            $engine,
            $this->createStub(BackupFiles::class),
            $this->createStub(BackupHistory::class),
            $this->createStub(MaintenanceMode::class),
        );
        $this->expectException(\InvalidArgumentException::class);
        $service->restore(
            $reference,
            "/private/new",
            "fictional-campus",
            new BackupBudget(10, 100, 10),
        );
    }

    public static function invalidReferences(): array
    {
        return [
            ["latest"],
            ["abcdef01"],
            ["--host=other"],
            [str_repeat("A", 64)],
            [""],
        ];
    }
}
