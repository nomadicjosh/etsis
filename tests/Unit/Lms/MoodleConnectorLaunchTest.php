<?php
declare(strict_types=1);
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
final class MoodleConnectorLaunchTest extends TestCase
{
    #[DataProvider("scenarios")]
    public function testNativeConnectorBoundariesWithoutLiveAccounts(string $scenario, array $expected): void
    {
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2) . "/Support/MoodleLaunchSandbox.php",
            $scenario,
        ]);
        $process->setTimeout(10);
        $process->mustRun();
        $data = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        foreach ($expected as $key => $value) {
            self::assertSame($value, $data[$key], $scenario . " " . $key);
        }
    }
    public static function scenarios(): array
    {
        return [
            [
                "admin_error_page",
                [
                    "status" => 403,
                    "has_admin_reason" => true,
                    "has_local_signin" => true,
                    "has_campus_return" => true,
                    "has_dead_docs_link" => false,
                    "has_private_payload" => false,
                ],
            ],
            [
                "generic_error_page",
                [
                    "status" => 403,
                    "has_admin_reason" => false,
                    "has_local_signin" => true,
                    "has_campus_return" => true,
                    "has_dead_docs_link" => false,
                    "has_private_payload" => false,
                ],
            ],
            ["valid", ["denied" => false, "logins" => 1, "marker" => true]],
            ["origin", ["denied" => true, "calls" => 0, "logins" => 0]],
            ["missing_origin", ["denied" => true, "calls" => 0, "logins" => 0]],
            ["admin", ["denied" => true, "logins" => 0, "errorcode" => "launchsiteadmin"]],
            ["suspended", ["denied" => true, "logins" => 0]],
            ["disabled_auth", ["denied" => true, "logins" => 0]],
            ["switch", ["denied" => true, "logins" => 0]],
            ["login_hook_error", ["denied" => true, "terminated" => 1]],
            ["check", ["denied" => false, "calls" => 1]],
            ["expired", ["denied" => true, "calls" => 0]],
            ["malformed", ["denied" => true, "calls" => 0]],
            ["revoked", ["denied" => true, "calls" => 1]],
            ["outage", ["denied" => true, "calls" => 1]],
            ["tampered", ["denied" => true, "calls" => 1]],
            ["oversize", ["denied" => true, "calls" => 1]],
            ["wrong_user", ["denied" => true, "calls" => 1]],
            ["wrong_grant", ["denied" => true, "calls" => 1]],
            ["extended", ["denied" => true, "calls" => 1]],
            ["hook_expired", ["denied" => true, "terminated" => 1]],
            ["hook_unmarked", ["denied" => false, "terminated" => 0]],
        ];
    }
}
