<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Infrastructure\Lms\ConfiguredMoodleAccountRequestPolicy;
use Infrastructure\Lms\SqlMoodleAccountDefaults;
use PHPUnit\Framework\TestCase;
use Qubus\Config\Collection;

final class MoodleAccountRequestAdaptersTest extends TestCase
{
    public function testBothInstitutionalFlagsMustBeExplicitBooleansAndAreReread(): void
    {
        foreach (
            [
                [false, true, false],
                [true, false, false],
                [true, true, true],
                ["true", true, false],
                [true, "true", false],
            ]
            as [$moodle, $intake, $expected]
        ) {
            $config = Collection::factory([]);
            $config->setConfigKey("moodle", ["enabled" => $moodle, "account_requests_enabled" => $intake]);
            $policy = new ConfiguredMoodleAccountRequestPolicy($config);
            self::assertSame($expected, $policy->enabled());
            self::assertFalse($policy->processingEnabled());
            $config->setConfigKey("moodle", ["provisioning_enabled" => true]);
            self::assertSame($expected, $policy->processingEnabled());
            $config->setConfigKey("moodle", ["provisioning_enabled" => "true"]);
            self::assertFalse($policy->processingEnabled());
            $config->setConfigKey("moodle", ["account_requests_enabled" => false]);
            self::assertFalse($policy->enabled());
        }
    }

    public function testDefaultsContainOnlyAccountUsernameAndEmailAndPermitAnExplicitlyUnownedPerson(): void
    {
        $f = new SisFixture();
        $f->connection->pdo->exec("ALTER TABLE users ADD COLUMN username VARCHAR(191)");
        $f->sql->update(
            "users",
            ["username" => "alex_example", "email" => "fictional@example.invalid"],
            ["user_id" => $f->access->actor->value],
        );
        $defaults = new SqlMoodleAccountDefaults($f->sql);
        self::assertSame(["username" => "", "email" => ""], $defaults->forOwner(null));
        self::assertSame(
            ["username" => "alex_example", "email" => "fictional@example.invalid"],
            $defaults->forOwner($f->access->actor),
        );
    }
}
