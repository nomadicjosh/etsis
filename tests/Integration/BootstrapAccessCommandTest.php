<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Application\Console\Commands\BootstrapAccessCommand;
use Codefy\Framework\Application;
use Domain\Access\Service\BootstrapAccess;
use Domain\User\Repository\AccountIdentityLookup;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Security\SqlAccountIdentityLookup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

final class BootstrapAccessCommandTest extends TestCase
{
    private function command(SisFixture $fixture): CommandTester
    {
        $service = new BootstrapAccess(
            new SqlAccessRepository($fixture->sql),
            $fixture->transactions,
            $fixture->audit,
        );
        $app = new Application(["basePath" => dirname(__DIR__, 2)]);
        $app->delegate(BootstrapAccess::class, static fn() => $service);
        $app->delegate(
            AccountIdentityLookup::class,
            static fn() => new SqlAccountIdentityLookup($fixture->sql),
        );
        $command = new BootstrapAccessCommand($app);
        new ConsoleApplication()->add($command);
        return new CommandTester($command);
    }

    private function email(SisFixture $f): string
    {
        $email = "alpha.tester@example.invalid";
        $f->sql->update("users", ["email" => $email], ["user_id" => $f->access->actor->value]);
        return $email;
    }

    public function testFrameworkHandleAssignsFirstAdministratorAndRejectsLaterBootstrap(): void
    {
        $fixture = new SisFixture();
        $tester = $this->command($fixture);
        $repository = new SqlAccessRepository($fixture->sql);
        self::assertSame(0, $tester->execute(["user_id" => $fixture->access->actor->value]));
        self::assertTrue($repository->isSuper($fixture->access->actor->value));
        self::assertStringContainsString("Initial super administrator assigned.", $tester->getDisplay());
        $other = $fixture->user();
        self::assertSame(1, $tester->execute(["user_id" => $other->value]));
        self::assertFalse($repository->isSuper($other->value));
        self::assertStringContainsString("Bootstrap refused.", $tester->getDisplay());
    }

    public function testInteractiveBootstrapSelectsExistingAccountWithoutPrintingIdentity(): void
    {
        $f = new SisFixture();
        $email = $this->email($f);
        $tester = $this->command($f);
        $tester->setInputs([$email, "yes"]);
        self::assertSame(0, $tester->execute([]));
        self::assertTrue(new SqlAccessRepository($f->sql)->isSuper($f->access->actor->value));
        self::assertStringNotContainsString($email, $tester->getDisplay());
        self::assertStringNotContainsString($f->access->actor->value, $tester->getDisplay());
        self::assertStringNotContainsString("password", strtolower($tester->getDisplay()));
        $other = $f->user();
        $f->sql->update("users", ["email" => "other@example.invalid"], ["user_id" => $other->value]);
        $tester->setInputs(["other@example.invalid", "yes"]);
        self::assertSame(1, $tester->execute([]));
        self::assertFalse(new SqlAccessRepository($f->sql)->isSuper($other->value));
    }

    public function testCancellationDoesNotAssignARole(): void
    {
        $f = new SisFixture();
        $tester = $this->command($f);
        $tester->setInputs([$this->email($f), "no"]);
        self::assertSame(2, $tester->execute([]));
        self::assertFalse(new SqlAccessRepository($f->sql)->anySuper());
        self::assertStringContainsString("Bootstrap cancelled.", $tester->getDisplay());
    }

    public function testUnknownAmbiguousAndInvalidEmailsDoNotAssignARole(): void
    {
        $f = new SisFixture();
        $email = $this->email($f);
        $duplicate = $f->user();
        $f->sql->update("users", ["email" => $email], ["user_id" => $duplicate->value]);
        $tester = $this->command($f);
        foreach ([$email, "missing@example.invalid", "not-an-email"] as $input) {
            $tester->setInputs([$input]);
            self::assertSame(2, $tester->execute([]));
            self::assertFalse(new SqlAccessRepository($f->sql)->anySuper());
            self::assertStringNotContainsString($input, $tester->getDisplay());
        }
    }

    public function testInvalidAndMissingNoninteractiveArgumentsCannotBootstrapAccess(): void
    {
        $fixture = new SisFixture();
        $tester = $this->command($fixture);
        self::assertSame(1, $tester->execute(["user_id" => "not-a-user-ulid"]));
        self::assertFalse(new SqlAccessRepository($fixture->sql)->anySuper());
        self::assertSame(2, $tester->execute([], ["interactive" => false]));
        self::assertFalse(new SqlAccessRepository($fixture->sql)->anySuper());
    }

    public function testLookupIsBoundedAndUsesPreparedIdentityParameters(): void
    {
        $f = new SisFixture();
        $lookup = new SqlAccountIdentityLookup($f->sql);
        self::assertSame($f->access->actor->value, $lookup->forEmail($this->email($f))?->value);
        self::assertNull($lookup->forEmail("alpha.tester@example.invalid' OR 1=1 --"));
    }
}
