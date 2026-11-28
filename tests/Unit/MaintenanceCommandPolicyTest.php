<?php

declare(strict_types=1);

namespace Tests\Unit;

use Application\Console\MaintenanceCommandPolicy;
use Application\Http\Security\MaintenanceRoutes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\StringInput;

final class MaintenanceCommandPolicyTest extends TestCase
{
    public function testOnlyNamedRecoveryCommandsAndRealHelpBypassThePause(): void
    {
        foreach (
            ['', 'list', 'help sis:moodle:sync', 'sis:maintenance up', 'sis:history:verify --json',
            'sis:history:anchor --action=compare', 'sis:backup --action=capture --quiesced',
            'sis:database:postgres --switch',
            'sis:projections:academic-dates --apply',
            'sis:accounts:prune-setup --apply --limit=50',
            'sis:accounts:email-verification --prune --apply --limit=50',
            'migrate:status', 'migrate:up', 'migrate:rollback', 'schedule:list', 'route:list', 'sis:access:bootstrap',
            'sis:communications:send --help'] as $command
        ) {
            self::assertTrue(MaintenanceCommandPolicy::permits(new StringInput($command)), $command);
        }
        foreach (
            ['sis:communications:send --apply', 'sis:accounts:send-setup --apply',
            'sis:accounts:email-verification --send --apply',
            'sis:accounts:email-verification --prune --send --apply',
            'sis:admissions:documents --apply', 'sis:reports:export --apply', 'sis:reports:prune --apply',
            'sis:moodle:sync', 'sis:moodle:provision', 'queue:run', 'schedule:run', 'migrate:fresh',
            'sis:comm:send --apply', 'sis:accounts:prune --apply', 'sis:communications:send -- --help'] as $command
        ) {
            self::assertFalse(MaintenanceCommandPolicy::permits(new StringInput($command)), $command);
        }
    }

    public function testAuthenticationExceptionsUseTheConfiguredRouteAndExactMethods(): void
    {
        foreach (
            [['GET', '/staff-access/'], ['HEAD', '/staff-access/'], ['POST', '/auth/'],
            ['GET', '/logout/'], ['POST', '/logout/']] as [$method, $path]
        ) {
            self::assertTrue(MaintenanceRoutes::authentication($method, $path, 'staff-access'));
        }
        foreach (
            [['POST', '/staff-access/'], ['GET', '/auth/'], ['POST', '/register/'],
            ['GET', '/login/'], ['GET', '/admin/'], ['POST', '/portal/payments/webhook/']] as [$method, $path]
        ) {
            self::assertFalse(MaintenanceRoutes::authentication($method, $path, 'staff-access'));
        }
        self::assertFalse(MaintenanceRoutes::browserCandidate('/portal/payments/webhook/', 'fictional-cookie'));
        self::assertFalse(MaintenanceRoutes::browserCandidate('/sis/moodle/session-channel/', 'fictional-cookie'));
        self::assertFalse(MaintenanceRoutes::browserCandidate('/admin/', str_repeat('x', 16385)));
        self::assertFalse(MaintenanceRoutes::browserCandidate('/admin/', ['fictional']));
        self::assertTrue(MaintenanceRoutes::browserCandidate('/admin/', 'untrusted-candidate-needs-verification'));
    }
}
