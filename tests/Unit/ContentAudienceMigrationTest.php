<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\CreateTable;

final class ContentAudienceMigrationTest extends TestCase
{
    public function testRoleTargetsCompileForFiveDialectsWithoutChangingEarlierPublicationsOrRoleGrants(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261127000001_CreateSisPortalAudienceRoles.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $table = new CreateTable('sis_portal_audience_roles');
            \CreateSisPortalAudienceRoles::define($table);
            $connection = $this->createStub(Connection::class);
            $connection->method('quoteIdentifier')->willReturnCallback(
                static fn(string $name): string => '"' . $name . '"',
            );
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
            foreach (['post_id', 'role_id', 'role_name', 'spar_post_role', 'spar_post_fk'] as $field) {
                self::assertStringContainsString($field, $sql);
            }
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|UPDATE |INSERT INTO|AUTO_INCREMENT/i', $sql);
            self::assertStringNotContainsString('sis_history_events', $sql);
            self::assertStringNotContainsString('sis_access_user_roles', $sql);
        }
    }
}
