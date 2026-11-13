<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
final class MoodleRosterTest extends TestCase
{
    #[DataProvider("cases")]
    public function testNativeAccessOwnershipFencesAndLearningHistory(string $case, array $expected): void
    {
        $process = new Process([
            PHP_BINARY,
            "-d",
            "xdebug.mode=off",
            dirname(__DIR__, 2) . "/Support/MoodleProvisioningSandbox.php",
            $case,
        ]);
        $process->setTimeout(8);
        $process->mustRun();
        $data = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        foreach ($expected as $key => $value) {
            self::assertSame($value, $data[$key], $case . " " . $key);
        }
        self::assertSame(1, $data["other_roles"]);
        self::assertSame(1, $data["learning"]);
    }
    public static function cases(): array
    {
        return [
            ["roster_assign_denied", ["refused" => true, "enrollments" => 0]],
            ["roster_retry", ["status" => "unchanged", "enrollments" => 1, "own_roles" => 1]],
            ["roster_stale", ["status" => "superseded", "suspended" => 1]],
            ["roster_suspend", ["enrollments" => 1, "suspended" => 1, "own_roles" => 0]],
            ["roster_both", ["own_roles" => 2]],
            ["roster_external", ["status" => "externally_managed", "own_roles" => 0]],
            ["roster_changed", ["refused" => true]],
            ["roster_erased", ["refused" => true]],
            ["roster_disabled", ["refused" => true, "enrollments" => 0]],
            ["roster_denied", ["refused" => true, "enrollments" => 0]],
            ["roster_admin", ["refused" => true, "enrollments" => 0]],
            ["roster_policy", ["refused" => true, "enrollments" => 0]],
        ];
    }
}
