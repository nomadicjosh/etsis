<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Lms\Service\MoodleAdministrationService;
use Domain\Lms\Service\MoodleConnectionProbe;
use Domain\Lms\ValueObject\MoodleCapabilities;
use Domain\Shared\Exception\AccessDeniedException;
use PHPUnit\Framework\TestCase;

final class MoodleAdministrationTest extends TestCase
{
    private SisFixture $f;
    private MoodleConnectionProbe $probe;
    private MoodleAdministrationService $service;
    protected function setUp(): void
    {
        $this->f = new SisFixture();
        $this->probe = new class implements MoodleConnectionProbe {
            public bool $active = true;
            public int $calls = 0;
            public ?Closure $duringCall = null;
            public function enabled(): bool
            {
                return $this->active;
            }
            public function check(): MoodleCapabilities
            {
                $this->calls++;
                if ($this->duringCall) {
                    ($this->duringCall)();
                }
                return new MoodleCapabilities(["core_webservice_get_site_info"]);
            }
        };
        $this->service = new MoodleAdministrationService($this->probe, $this->f->access, $this->f->audit);
    }
    public function testStatusDoesNotContactProviderAndCheckReturnsOnlyFixedFlagsOutsideSqlTransactions(): void
    {
        self::assertSame(["enabled" => true], $this->service->status());
        self::assertSame(0, $this->probe->calls);
        $history = $this->f->sql->rows("sis_history_events");
        $this->probe->duringCall = function (): void {
            self::assertFalse($this->f->connection->pdo->inTransaction());
        };
        $result = $this->service->check();
        self::assertSame(["enabled", "capabilities"], array_keys($result));
        self::assertSame(MoodleCapabilities::FUNCTIONS, array_keys($result["capabilities"]));
        self::assertCount(1, $this->f->audit->forSubject($this->f->access->actor));
        self::assertSame($history, $this->f->sql->rows("sis_history_events"));
    }
    public function testDisabledStatusAndCheckDoNotContactProvider(): void
    {
        $this->probe->active = false;
        self::assertSame(["enabled" => false], $this->service->status());
        self::assertSame(["enabled" => false, "capabilities" => null], $this->service->check());
        self::assertSame(0, $this->probe->calls);
    }
    public function testOtherAdministrativeGrantsDoNotGrantMoodleAdministration(): void
    {
        $this->f->access->staff = false;
        $this->f->access->grants = ["admin:dashboard", "sis:catalog:manage", "admin:access:manage"];
        $this->expectException(AccessDeniedException::class);
        $this->service->status();
    }
    public function testPermissionRevocationDuringCallDiscardsResults(): void
    {
        $this->probe->duringCall = function (): void {
            $this->f->access->staff = false;
        };
        $this->expectException(AccessDeniedException::class);
        $this->service->check();
    }
    public function testChangedAdministratorDuringCallDiscardsResults(): void
    {
        $this->probe->duringCall = function (): void {
            $this->f->access->actor = $this->f->user();
        };
        $this->expectException(AccessDeniedException::class);
        $this->service->check();
    }
    public function testUnexpectedProviderFailureCannotExposeDetails(): void
    {
        $this->probe->duringCall = static function (): never {
            throw new RuntimeException("fictional-secret upstream profile");
        };
        try {
            $this->service->check();
            self::fail();
        } catch (MoodleConnectionUnavailableException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString("fictional-secret", $error->getMessage());
        }
    }
    public function testAuditFailurePreventsExternalRead(): void
    {
        $audit = new class implements \Domain\Audit\Repository\AuditTrail {
            public function record(
                \Domain\Shared\ValueObject\EntityId $actor,
                string $action,
                \Domain\Shared\ValueObject\EntityId $subject,
            ): void {
                throw new RuntimeException("Fictional audit outage.");
            }
            public function forSubject(\Domain\Shared\ValueObject\EntityId $subject): array
            {
                return [];
            }
        };
        $service = new MoodleAdministrationService($this->probe, $this->f->access, $audit);
        try {
            $service->check();
            self::fail();
        } catch (RuntimeException) {
            self::assertSame(0, $this->probe->calls);
        }
    }
    public function testPermissionMigrationIsIndependentProtectedAndIdempotent(): void
    {
        $migration = new CreateSisMoodleAdministrationPermission("moodle-permission");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $this->f->connection,
                    "test_migrations",
                ),
            ]),
        );
        $before = $this->f->sql->rows("sis_access_permissions");
        $migration->up();
        self::assertSame($before, $this->f->sql->rows("sis_access_permissions"));
        $permission = $this->f->sql->rows(
            "sis_access_permissions",
            ["name" => MoodleAdministrationService::MANAGE],
            1,
        )[0];
        self::assertSame(1, (int) $permission["is_system"]);
        $implications = $this->f->sql->rows("sis_access_implications", [
            "descendant_id" => $permission["id"],
        ]);
        self::assertCount(1, $implications);
        self::assertSame($permission["id"], $implications[0]["ancestor_id"]);
        $this->f->sql->update("sis_access_permissions", ["is_system" => 0], ["id" => $permission["id"]]);
        $this->expectException(LogicException::class);
        $migration->up();
    }
}
