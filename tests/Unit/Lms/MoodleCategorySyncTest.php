<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
final class MoodleCategorySyncTest extends TestCase
{
    #[DataProvider("cases")]
    public function testNativeReferenceIdentityOwnershipAndFences(string $case, array $expected): void
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
        $r = json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        foreach ($expected as $key => $value) {
            self::assertSame($value, $r[$key], $case . " " . $key);
        }
    }
    public static function cases(): array
    {
        return [
            [
                "category_retry",
                [
                    "refused" => false,
                    "owned" => true,
                    "categories" => 1,
                    "receipts" => 1,
                    "status" => "unchanged",
                ],
            ],
            [
                "category_rename",
                ["refused" => false, "categories" => 1, "name" => "MATH · Mathematical Sciences"],
            ],
            [
                "category_linked",
                ["refused" => false, "owned" => false, "name" => "Existing institutional category"],
            ],
            ["category_stale", ["status" => "superseded", "name" => "MATH · Mathematical Sciences"]],
            ["category_changed", ["refused" => true, "categories" => 1]],
            ["category_relink", ["refused" => true, "categories" => 1]],
            ["category_deleted", ["refused" => true, "categories" => 0, "receipts" => 1]],
            ["category_disabled", ["refused" => true, "categories" => 0]],
            ["category_denied", ["refused" => true, "categories" => 0]],
            ["category_wrong_instance", ["refused" => true, "categories" => 0]],
            ["category_rollback", ["refused" => true, "categories" => 0, "receipts" => 0]],
        ];
    }
}
