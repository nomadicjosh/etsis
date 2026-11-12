<?php
declare(strict_types=1);
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
final class MoodleConnectorProvisioningTest extends TestCase
{
    #[DataProvider("scenarios")]
    public function testConnectorReceiptsAndDeliveryBoundaries(string $scenario, array $expected): void
    {
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2) . "/Support/MoodleProvisioningSandbox.php",
            $scenario,
        ]);
        $process->setTimeout(15);
        $process->mustRun();
        $data = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        foreach ($expected as $key => $value) {
            self::assertSame($value, $data[$key], $scenario . " " . $key);
        }
        self::assertSame(0, $data["external_password_updates"]);
        self::assertTrue($data["receipt_read_unchanged"]);
    }
    public static function scenarios(): array
    {
        return [
            [
                "receipt_recorded",
                [
                    "users" => 1,
                    "receipts" => 1,
                    "sent" => 1,
                    "status" => "recorded",
                    "receipt_account" => "1",
                ],
            ],
            [
                "receipt_missing",
                [
                    "users" => 0,
                    "receipts" => 0,
                    "sent" => 0,
                    "status" => "not_observed",
                    "receipt_account" => "0",
                ],
            ],
            [
                "receipt_pending",
                [
                    "users" => 1,
                    "receipts" => 1,
                    "sent" => 1,
                    "status" => "recorded",
                    "receipt_sign_in" => "email_review_required",
                ],
            ],
            [
                "receipt_attempting",
                [
                    "users" => 1,
                    "receipts" => 1,
                    "sent" => 1,
                    "status" => "recorded",
                    "receipt_sign_in" => "email_review_required",
                ],
            ],
            [
                "receipt_restricted",
                ["status" => "restricted", "receipt_account" => "0", "receipt_sign_in" => "unknown"],
            ],
            [
                "receipt_siteadmin",
                ["status" => "restricted", "receipt_account" => "0", "receipt_sign_in" => "unknown"],
            ],
            ["receipt_erased", ["refused" => true]],
            ["receipt_denied", ["refused" => true]],
            ["receipt_disabled", ["refused" => true]],
            ["receipt_hash_changed", ["refused" => true]],
            ["receipt_instance_changed", ["refused" => true]],
            ["retry", ["users" => 1, "receipts" => 1, "sent" => 1, "same" => true, "status" => "email_sent"]],
            [
                "sso",
                [
                    "users" => 1,
                    "receipts" => 1,
                    "sent" => 0,
                    "same" => true,
                    "status" => "sso_ready",
                    "sso_password_uncached" => true,
                ],
            ],
            ["rollback", ["users" => 0, "receipts" => 0, "sent" => 0, "refused" => true]],
            ["denied", ["users" => 0, "receipts" => 0, "sent" => 0, "refused" => true]],
            ["changed", ["users" => 1, "receipts" => 1, "sent" => 1, "refused" => true]],
            ["policy", ["users" => 1, "receipts" => 1, "sent" => 1, "refused" => true]],
            [
                "uncertain_mail",
                ["users" => 1, "receipts" => 1, "sent" => 1, "status" => "email_review_required"],
            ],
            [
                "mail_failure",
                ["users" => 1, "receipts" => 1, "sent" => 1, "status" => "email_review_required"],
            ],
            ["erased", ["users" => 1, "receipts" => 1, "sent" => 1, "refused" => true]],
        ];
    }
}
