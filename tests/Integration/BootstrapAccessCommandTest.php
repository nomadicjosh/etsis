<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/SisFixture.php';

use Application\Console\Commands\BootstrapAccessCommand;
use Codefy\Framework\Application;
use Domain\Access\Service\BootstrapAccess;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class BootstrapAccessCommandTest extends TestCase
{
    private function command(SisFixture $fixture): CommandTester
    {
        $service = new BootstrapAccess(new SqlAccessRepository($fixture->sql), $fixture->transactions, $fixture->audit);
        return new CommandTester(new BootstrapAccessCommand(
            new Application(['basePath' => dirname(__DIR__, 2)]), $service,
        ));
    }
    public function testFrameworkHandleAssignsFirstAdministratorAndRejectsLaterBootstrap(): void
    {
        $fixture = new SisFixture();
        $tester = $this->command($fixture);
        $repository = new SqlAccessRepository($fixture->sql);
        self::assertSame(0, $tester->execute(['user_id' => $fixture->access->actor->value]));
        self::assertTrue($repository->isSuper($fixture->access->actor->value));
        self::assertStringContainsString('Initial super administrator assigned.', $tester->getDisplay());
        $other = $fixture->user();
        self::assertSame(1, $tester->execute(['user_id' => $other->value]));
        self::assertFalse($repository->isSuper($other->value));
        self::assertStringContainsString('Bootstrap refused.', $tester->getDisplay());
    }
    public function testInvalidAndMissingUserArgumentsCannotBootstrapAccess(): void
    {
        $fixture = new SisFixture();
        $tester = $this->command($fixture);
        self::assertSame(1, $tester->execute(['user_id' => 'not-a-user-ulid']));
        self::assertFalse(new SqlAccessRepository($fixture->sql)->anySuper());
        $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
        $tester->execute([]);
    }
}
