<?php

declare(strict_types=1);

use Application\Console\Commands\MoodleInstanceCommand;
use Codefy\Framework\Application;
use Domain\Lms\Service\MoodleInstanceReader;
use Domain\Lms\ValueObject\MoodleInstanceIdentity;
use Domain\Lms\ValueObject\MoodleInstallation;
use Domain\Lms\ValueObject\MoodleSiteUrl;
use Domain\Lms\ValueObject\MoodleSetupIssue;
use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class MoodleInstanceCommandTest extends TestCase
{
    public function testDisabledIntegrationDoesNotReadIdentity(): void
    {
        $reader = $this->createMock(MoodleInstanceReader::class);
        $reader->method("enabled")->willReturn(false);
        $reader->expects(self::never())->method("identify");
        $tester = $this->command($reader);
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString("disabled", $tester->getDisplay());
    }

    public function testEnabledResultPrintsFingerprintAndDoesNotClaimBinding(): void
    {
        $id = "0123456789abcdef0123456789abcdef";
        $reader = $this->createStub(MoodleInstanceReader::class);
        $reader->method("enabled")->willReturn(true);
        $reader
            ->method("identify")
            ->willReturn(
                new MoodleInstallation(
                    new MoodleSiteUrl("https://moodle.example.invalid"),
                    new MoodleInstanceIdentity($id),
                ),
            );
        $tester = $this->command($reader);
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString(hash("sha256", $id), $tester->getDisplay());
        self::assertStringNotContainsString($id, $tester->getDisplay());
        self::assertStringContainsString("No instance binding", $tester->getDisplay());
    }

    public function testProviderExceptionIsNotPrintedEvenInVerboseMode(): void
    {
        $reader = $this->createStub(MoodleInstanceReader::class);
        $reader->method("enabled")->willReturn(true);
        $reader
            ->method("identify")
            ->willThrowException(new RuntimeException("fictional-secret private response"));
        $tester = $this->command($reader);
        self::assertSame(1, $tester->execute([], ["verbosity" => 256]));
        self::assertStringNotContainsString("fictional-secret", $tester->getDisplay());
        self::assertStringContainsString("verification failed", $tester->getDisplay());
    }

    public function testKnownDisabledConnectorGetsActionableLocalGuidance(): void
    {
        $reader = $this->createStub(MoodleInstanceReader::class);
        $reader->method("enabled")->willReturn(true);
        $reader
            ->method("identify")
            ->willThrowException(
                new MoodleConnectionUnavailableException(MoodleSetupIssue::VerificationDisabled),
            );
        $tester = $this->command($reader);
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString("Enable instance verification", $tester->getDisplay());
        self::assertStringNotContainsString("TLS trust", $tester->getDisplay());
    }

    private function command(MoodleInstanceReader $reader): CommandTester
    {
        return new CommandTester(
            new MoodleInstanceCommand(new Application(["basePath" => dirname(__DIR__, 3)]), $reader),
        );
    }
}
