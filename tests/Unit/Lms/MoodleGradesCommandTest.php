<?php

declare(strict_types=1);

namespace Tests\Unit\Lms;

use Application\Console\Commands\MoodleGradesCommand;
use Codefy\Framework\Application;
use Domain\Lms\Service\MoodleGradeImportWorker;
use Domain\Shared\ValueObject\EntityId;
use EventStoreFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\MoodleGradeImportFixture;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MoodleGradesCommandTest extends TestCase
{
    public static function invalidLimits(): iterable
    {
        yield 'zero' => ['0'];
        yield 'too many' => ['21'];
        yield 'not an integer' => ['1.5'];
    }

    #[DataProvider('invalidLimits')]
    public function testInvalidLimitsDoNotResolveAWorker(string $limit): void
    {
        $app = new Application(['basePath' => dirname(__DIR__, 3)]);
        $app->delegate(MoodleGradeImportWorker::class, static fn() => throw new \LogicException('Worker resolved.'));
        $tester = new CommandTester(new MoodleGradesCommand($app));
        self::assertSame(2, $tester->execute(['--limit' => $limit]));
        self::assertStringContainsString('1 through 20', $tester->getDisplay());
    }

    public function testDisabledModeDoesNotReadMoodle(): void
    {
        require_once dirname(__DIR__, 2) . '/Support/MoodleGradeImportFixture.php';
        $f = new MoodleGradeImportFixture();
        $f->policy->on = false;
        $app = new Application(['basePath' => dirname(__DIR__, 3)]);
        $app->delegate(MoodleGradeImportWorker::class, static fn() => $f->worker());
        $tester = new CommandTester(new MoodleGradesCommand($app));
        self::assertSame(0, $tester->execute(['--limit' => '1']));
        self::assertStringContainsString('disabled: 1', $tester->getDisplay());
        self::assertSame(0, $f->remote->calls);
    }

    public function testOneStepCollectsWithoutPublishingAndTheNextInvocationFinishes(): void
    {
        require_once dirname(__DIR__, 2) . '/Support/MoodleGradeImportFixture.php';
        $f = new MoodleGradeImportFixture();
        $section = $f->offering->section->id;
        $p = $f->service()->page($section);
        $f->service()->request($section, new EntityId($p['request_id']), $p['revision'], 'Fictional command intake.');
        $before = EventStoreFixture::historyDigest($f->sis->sql);
        $app = new Application(['basePath' => dirname(__DIR__, 3)]);
        $app->delegate(MoodleGradeImportWorker::class, static fn() => $f->worker());
        $tester = new CommandTester(new MoodleGradesCommand($app));
        self::assertSame(0, $tester->execute(['--limit' => '1']));
        self::assertStringContainsString('grade_observed: 1', $tester->getDisplay());
        self::assertSame('running', $f->records->job($section)['state']);
        self::assertSame($before, EventStoreFixture::historyDigest($f->sis->sql));
        self::assertSame(0, $tester->execute(['--limit' => '1']));
        self::assertStringContainsString('intake_complete: 1', $tester->getDisplay());
    }
}
