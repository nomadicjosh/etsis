<?php

declare(strict_types=1);

use Application\Console\Commands\MoodleCheckCommand;
use Codefy\Framework\Application;
use Domain\Lms\Service\MoodleConnectionProbe;
use Domain\Lms\ValueObject\MoodleCapabilities;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class MoodleCheckCommandTest extends TestCase
{
    public function testDisabledIntegrationDoesNotContactMoodle(): void
    {
        $probe = new class implements MoodleConnectionProbe {
            public function enabled(): bool
            {
                return false;
            }
            public function check(): MoodleCapabilities
            {
                throw new LogicException("Disabled integration contacted Moodle.");
            }
        };
        $tester = new CommandTester(new MoodleCheckCommand(
            new Application(['basePath' => dirname(__DIR__, 3)]), $probe,
        ));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString("disabled", $tester->getDisplay());
    }
    public function testSuccessfulCheckDoesNotClaimSynchronizationIsReady(): void
    {
        $probe = new class implements MoodleConnectionProbe {
            public function enabled(): bool
            {
                return true;
            }
            public function check(): MoodleCapabilities
            {
                return new MoodleCapabilities(["core_webservice_get_site_info"]);
            }
        };
        $tester = new CommandTester(new MoodleCheckCommand(
            new Application(['basePath' => dirname(__DIR__, 3)]), $probe,
        ));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString("SSO and synchronization are not enabled", $tester->getDisplay());
        self::assertStringContainsString("core_course_create_courses: not advertised", $tester->getDisplay());
    }
    public function testFailuresNeverExposeProviderDetailsEvenInVerboseMode(): void
    {
        $probe = new class implements MoodleConnectionProbe {
            public function enabled(): bool
            {
                return true;
            }
            public function check(): MoodleCapabilities
            {
                throw new RuntimeException("fictional-private-token");
            }
        };
        $tester = new CommandTester(new MoodleCheckCommand(
            new Application(['basePath' => dirname(__DIR__, 3)]), $probe,
        ));
        self::assertSame(
            1,
            $tester->execute(
                [],
                ["verbosity" => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_DEBUG],
            ),
        );
        self::assertStringNotContainsString("fictional-private-token", $tester->getDisplay());
    }
}
