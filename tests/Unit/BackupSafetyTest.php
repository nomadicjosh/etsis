<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Shared\Backup\BackupBudget;
use Infrastructure\Backup\BackupConfiguration;
use Infrastructure\Backup\LocalBackupFiles;
use Infrastructure\Backup\NativeDatabaseSource;
use Infrastructure\Backup\PrivateBackupPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\BackupFixture;

final class BackupSafetyTest extends TestCase
{
    private BackupFixture $fixture;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . "/Support/BackupFixture.php";
        $this->fixture = new BackupFixture(realpath("/usr/bin/true"));
    }

    protected function tearDown(): void
    {
        $this->fixture->dispose();
    }

    #[DataProvider("unsafeConfiguration")]
    public function testUnsafePrivateConfigurationIsRefused(string $case): void
    {
        $f = $this->fixture;
        if ($case === "public_repository") {
            $f->settings["repository"] = $f->project . "/public/themes";
        } elseif ($case === "http_s3") {
            $f->settings["repository"] = "s3:http://example.invalid/bucket";
        } elseif ($case === "embedded_secret") {
            $f->settings["repository"] =
            "s3:https://user:secret@example.invalid/bucket";
        } elseif ($case === "recursive_repository") {
            $f->settings["includes"][] = $f->root;
        } elseif ($case === "lost_key") {
            $f->settings["includes"] = [
                $f->project . "/.env",
                $f->project . "/storage/app",
                $f->project . "/public/themes",
            ];
        } elseif ($case === "weak_password") {
            file_put_contents($f->root . "/operator/password", "short");
        } elseif ($case === "permission") {
            chmod($f->root . "/operator/password", 0644);
        } elseif ($case === "symlink") {
            symlink($f->root . "/repository", $f->root . "/repository-link");
            $f->settings["repository"] = $f->root . "/repository-link";
        } elseif ($case === "source_key_reuse") {
            file_put_contents(
                $f->root . "/operator/password",
                file_get_contents($f->project . "/.enc.key"),
            );
        } elseif ($case === "external_event_key") {
            $f->saveSettings();
            $this->expectException(\RuntimeException::class);
            BackupConfiguration::load(
                $f->configurationFile,
                $f->project,
                $f->root . "/missing-event-key",
                true,
            );
            return;
        }
        $f->saveSettings();
        $this->expectException(\RuntimeException::class);
        BackupConfiguration::load(
            $f->configurationFile,
            $f->project,
            capture: true,
        );
    }

    public static function unsafeConfiguration(): array
    {
        return array_map(static fn(string $case): array => [$case], [
            "public_repository",
            "http_s3",
            "embedded_secret",
            "recursive_repository",
            "lost_key",
            "weak_password",
            "permission",
            "symlink",
            "source_key_reuse",
            "external_event_key",
        ]);
    }

    public function testExplicitHttpsS3CredentialsAreAcceptedButNeverSerialized(): void
    {
        $f = $this->fixture;
        $f->settings["repository"] =
        "s3:https://s3.us-east-1.amazonaws.com/fictional-bucket/installation";
        $file = $f->root . "/operator/cloud.json";
        file_put_contents(
            $file,
            json_encode(
                [
                    "AWS_ACCESS_KEY_ID" => "fictional-access",
                    "AWS_SECRET_ACCESS_KEY" => "fictional-private-secret",
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
        chmod($file, 0600);
        $f->settings["credentials_file"] = $file;
        $f->saveSettings();
        $config = BackupConfiguration::load(
            $f->configurationFile,
            $f->project,
            capture: true,
        );
        self::assertSame(
            "fictional-private-secret",
            $config->credentials["AWS_SECRET_ACCESS_KEY"],
        );
        self::assertStringNotContainsString(
            "fictional-private-secret",
            json_encode($config, JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString(
            "fictional-private-secret",
            print_r($config, true),
        );
        self::assertStringNotContainsString(
            "fictional-private-secret",
            serialize($config),
        );
    }

    public function testAnEventKeyCannotDoubleAsTheRepositoryPassword(): void
    {
        $f = $this->fixture;
        $ring = json_decode(
            file_get_contents($f->project . "/event-keys.json"),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        file_put_contents($f->root . "/operator/password", $ring["v1"]);
        $this->expectException(\RuntimeException::class);
        BackupConfiguration::load(
            $f->configurationFile,
            $f->project,
            $f->project . "/event-keys.json",
            true,
        );
    }

    public function testFilesLocksAndSymlinkInventoriesCannotFollowUnreviewedSources(): void
    {
        $f = $this->fixture;
        $file = $f->project . "/storage/app/link";
        symlink($f->project . "/.env", $file);
        try {
            $f->files->inventory(
                $f->config->plan->includes,
                new BackupBudget(10, 100000, 10),
            );
            self::fail("A symbolic artifact was followed.");
        } catch (\RuntimeException) {
            self::assertTrue(is_link($file));
        }
        $second = new LocalBackupFiles(
            new PrivateBackupPaths($f->project . "/public"),
            $f->root . "/work",
        );
        $this->expectException(\RuntimeException::class);
        $f->files->exclusively(
            static fn() => $second->exclusively(static fn() => null),
        );
    }

    public function testPostgresCredentialsStayOutOfArgumentsAndUnsupportedDriversFailClosed(): void
    {
        $source = new NativeDatabaseSource(
            [
                "dsn" => "pgsql:host=127.0.0.1;port=5432;dbname=fictional",
                "username" => "fictional-reader",
                "password" => "fictional-password",
            ],
            ["pgsql" => "/usr/bin/true"],
        );
        $export = $source->export();
        self::assertSame(
            "fictional-password",
            $export["environment"]["PGPASSWORD"],
        );
        self::assertStringNotContainsString(
            "fictional-password",
            json_encode($export["command"], JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString(
            "fictional-password",
            print_r($source, true),
        );
        $this->expectException(\RuntimeException::class);
        new NativeDatabaseSource(
            ["dsn" => "mysql:host=127.0.0.1;dbname=fictional"],
            [],
        )->export();
    }
}
