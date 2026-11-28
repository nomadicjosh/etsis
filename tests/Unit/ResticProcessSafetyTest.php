<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Shared\Backup\BackupBudget;
use Domain\Shared\Backup\BackupPlan;
use Infrastructure\Backup\BackupConfiguration;
use Infrastructure\Backup\ResticProcess;
use PHPUnit\Framework\TestCase;

final class ResticProcessSafetyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root =
        sys_get_temp_dir() .
        "/etsis-backup-process-" .
        bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . "/*") as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    private function process(string $program): ResticProcess
    {
        $path = $this->root . "/fake-restic";
        file_put_contents($path, "#!" . PHP_BINARY . "\n<?php\n" . $program);
        chmod($path, 0700);
        $config = new BackupConfiguration(
            new BackupPlan(
                "fixture",
                "release",
                ["/fixture"],
                "/checkpoint",
                "/key",
            ),
            $path,
            $this->root,
            $this->root . "/password",
            $this->root,
            [
                "AWS_ACCESS_KEY_ID" => "fictional-id",
                "AWS_SECRET_ACCESS_KEY" => "fictional-purpose-secret",
            ],
            [],
            10,
            1024,
            10,
        );
        return new ResticProcess($config);
    }

    public function testOnlyPurposeCredentialsReachTheChildAndOnlyFixedStatusLeavesIt(): void
    {
        putenv("ETSIS_UNRELATED_PRIVATE_VALUE=fictional-parent-secret");
        try {
            $program = '$ok = getenv("AWS_SECRET_ACCESS_KEY") === "fictional-purpose-secret" '
            . '&& getenv("ETSIS_UNRELATED_PRIVATE_VALUE") === false '
            . '&& getenv("GOMAXPROCS") === "1" && getenv("GOMEMLIMIT") === "96MiB"; '
            . 'echo json_encode(["purpose_environment" => $ok], JSON_THROW_ON_ERROR);';
            $process = $this->process($program);
            self::assertSame(
                ["purpose_environment" => true],
                json_decode(
                    $process->run([], new BackupBudget(10, 1024, 10)),
                    true,
                ),
            );
        } finally {
            putenv("ETSIS_UNRELATED_PRIVATE_VALUE");
        }
    }

    public function testFailureDiscardsUpstreamDiagnosticsRatherThanRetainingSecretsInExceptions(): void
    {
        $process = $this->process(
            "fwrite(STDERR, 'fictional-credential-bearing-diagnostic'); exit(17);",
        );
        try {
            $process->run([], new BackupBudget(10, 1024, 10));
            self::fail("A failed process was accepted.");
        } catch (\RuntimeException $error) {
            self::assertStringNotContainsString(
                "fictional-credential-bearing-diagnostic",
                $error->getMessage(),
            );
        }
    }

    public function testAStalledProcessCannotOutliveItsExplicitOperationBudget(): void
    {
        $process = $this->process("usleep(3000000);");
        $started = microtime(true);
        try {
            $process->run([], new BackupBudget(1, 1024, 10));
            self::fail("A stalled child outlived the operation.");
        } catch (\RuntimeException) {
            self::assertLessThan(2.5, microtime(true) - $started);
        }
    }
}
