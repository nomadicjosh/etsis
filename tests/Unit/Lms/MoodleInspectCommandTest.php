<?php

declare(strict_types=1);

use Application\Console\Commands\MoodleInspectCommand;
use Codefy\Framework\Application;
use Domain\Lms\Service\MoodleRecordReader;
use Domain\Lms\ValueObject\MoodleExternalId;
use Domain\Lms\ValueObject\MoodleRecordCheck;
use Domain\Lms\ValueObject\MoodleResourceKind;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class MoodleInspectCommandTest extends TestCase
{
    private function command(MoodleRecordReader $reader): CommandTester
    {
        return new CommandTester(
            new MoodleInspectCommand(new Application(["basePath" => dirname(__DIR__, 3)]), $reader),
        );
    }
    public function testDisabledIntegrationDoesNotRequestAnyRecord(): void
    {
        $reader = new class implements MoodleRecordReader {
            public function enabled(): bool
            {
                return false;
            }
            public function inspect(MoodleResourceKind $kind, MoodleExternalId $id): MoodleRecordCheck
            {
                throw new LogicException("Unexpected network call.");
            }
        };
        $tester = $this->command($reader);
        self::assertSame(0, $tester->execute(["resource" => "user", "remote_id" => "123"]));
        self::assertStringContainsString("disabled", $tester->getDisplay());
    }
    public function testOutputContainsOnlyKnownFlagsWithoutEchoingIdOrClaimingAccess(): void
    {
        $reader = new class implements MoodleRecordReader {
            public function enabled(): bool
            {
                return true;
            }
            public function inspect(MoodleResourceKind $kind, MoodleExternalId $id): MoodleRecordCheck
            {
                return new MoodleRecordCheck($kind, true);
            }
        };
        $tester = $this->command($reader);
        self::assertSame(0, $tester->execute(["resource" => "user", "remote_id" => "123"]));
        self::assertStringContainsString("suspended: unknown", $tester->getDisplay());
        self::assertStringContainsString(
            "No identity link, access grant or synchronization was created.",
            $tester->getDisplay(),
        );
        self::assertStringNotContainsString("123", $tester->getDisplay());
    }
    public function testMissingResultDoesNotDeclareAccountAbsent(): void
    {
        $reader = new class implements MoodleRecordReader {
            public function enabled(): bool
            {
                return true;
            }
            public function inspect(MoodleResourceKind $kind, MoodleExternalId $id): MoodleRecordCheck
            {
                return new MoodleRecordCheck($kind, false);
            }
        };
        $tester = $this->command($reader);
        self::assertSame(0, $tester->execute(["resource" => "course", "remote_id" => "123"]));
        self::assertStringContainsString("outside the service account scope", $tester->getDisplay());
    }
    public function testInvalidArgumentsAndProviderFailuresHaveSafeExitCodesAndMessages(): void
    {
        $reader = new class implements MoodleRecordReader {
            public int $calls = 0;
            public function enabled(): bool
            {
                return true;
            }
            public function inspect(MoodleResourceKind $kind, MoodleExternalId $id): MoodleRecordCheck
            {
                $this->calls++;
                throw new RuntimeException("fictional-secret private upstream profile");
            }
        };
        $tester = $this->command($reader);
        self::assertSame(2, $tester->execute(["resource" => "email", "remote_id" => "123"]));
        self::assertSame(2, $tester->execute(["resource" => "user", "remote_id" => "0"]));
        self::assertSame(0, $reader->calls);
        self::assertSame(1, $tester->execute(["resource" => "user", "remote_id" => "123"]));
        self::assertStringNotContainsString("fictional-secret", $tester->getDisplay());
    }
}
