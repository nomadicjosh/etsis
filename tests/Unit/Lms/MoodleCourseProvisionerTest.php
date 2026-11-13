<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
final class MoodleCourseProvisionerTest extends TestCase
{
    #[DataProvider("cases")]
    public function testOriginalCreationIsAtomicAndRetrySafe(string $case, array $expected): void
    {
        $p = new Process([
            PHP_BINARY,
            "-d",
            "xdebug.mode=off",
            dirname(__DIR__, 2) . "/Support/MoodleProvisioningSandbox.php",
            $case,
        ]);
        $p->setTimeout(8);
        $p->mustRun();
        $report = json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        foreach ($expected as $key => $value) {
            self::assertSame($value, $report[$key], $case . " " . $key);
        }
    }
    public static function cases(): array
    {
        return [
            [
                "course_retry",
                [
                    "same" => true,
                    "refused" => false,
                    "courses" => 1,
                    "receipts" => 1,
                    "manual_instances" => 1,
                ],
            ],
            ["course_disabled", ["refused" => true, "courses" => 0]],
            ["course_denied", ["refused" => true, "courses" => 0]],
            ["course_category", ["refused" => true, "courses" => 0]],
            [
                "course_rollback",
                ["refused" => true, "courses" => 0, "receipts" => 0, "manual_instances" => 0],
            ],
            ["course_conflict", ["refused" => true, "courses" => 1, "receipts" => 0]],
            ["course_changed", ["refused" => true, "courses" => 1, "receipts" => 1]],
            ["course_moved", ["refused" => true, "courses" => 1, "receipts" => 1]],
            ["course_deleted", ["refused" => true, "courses" => 0, "receipts" => 1]],
        ];
    }
}
