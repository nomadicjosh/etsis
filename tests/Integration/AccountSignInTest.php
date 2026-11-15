<?php

declare(strict_types=1);

namespace Tests\Integration;

use PersonManagementFixture;
use EventStoreFixture;
use RuntimeException;
use DomainException;
use Domain\Access\Enum\GrantKind;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Service\LoginSessionAuthority;
use Domain\User\Service\PersonAccountEditor;
use Domain\User\ValueObject\UserId;
use Domain\User\ValueObject\VerifiedLoginCookie;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\Persistence\Repository\PdoAuthUserRespository;
use Infrastructure\Security\SqlLoginAccountStateReader;
use Infrastructure\Security\SqlLoginSessionRepository;
use PHPUnit\Framework\TestCase;
use Qubus\Config\ConfigContainer;

final class AccountSignInTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/PersonManagementFixture.php';
    }

    private function history(PersonManagementFixture $f): SqlHistoricalEventStore
    {
        return new SqlHistoricalEventStore(
            $f->sis->sql,
            $f->sis->transactions,
            $f->sis->clock,
            new ProtectedEventCodec(EventStoreFixture::config($f->sis->connection->queryBuilder())),
            new HistoricalSchemaRegistry()
        );
    }
    private function auth(PersonManagementFixture $f): PdoAuthUserRespository
    {
        $config = $this->createStub(ConfigContainer::class);
        $config->method('getConfigKey')->willReturnMap([
            ['auth.pdo.fields', null, ['identity' => 'email', 'password' => 'password',
                'token' => 'token', 'role' => 'role']],
            ['auth.pdo.table', null, 'users'],
        ]);
        $config->method('string')->willReturn('token');
        return new PdoAuthUserRespository($f->sis->connection, $config);
    }
    public function testDisableAndEnableRetainIdentityAndHistoryAndNeverReviveEarlierSessions(): void
    {
        $f = new PersonManagementFixture();
        $p = $f->person();
        $s = $f->sis;
        $person = $s->sql->rows('sis_persons');
        $grants = $s->sql->rows('sis_access_user_roles');
        $events = $s->sql->rows('sis_history_events');
        $before = $s->sql->one('users', ['user_id' => $p->ownerUserId->value]);
        $auth = $this->auth($f);
        self::assertNotNull($auth->authenticate($before['email'], PersonManagementFixture::PASSWORD));
        self::assertNotFalse($auth->find($before['token']));
        $authority = new LoginSessionAuthority(
            new SqlLoginSessionRepository($s->sql),
            new SqlLoginAccountStateReader($s->sql),
            $s->access,
            $s->transactions,
            $s->clock
        );
        $admin = $s->access->actor;
        $s->access->actor = $p->ownerUserId;
        $proof = new VerifiedLoginCookie(
            hash('sha256', 'fictional-cookie'),
            hash('sha256', $before['token']),
            $s->clock->now()->getTimestamp() + 3600
        );
        $session = $authority->current($proof);
        $s->access->actor = $admin;
        $version = $f->accounts->view($p->id)['version'];
        $op = EntityId::generate();
        $f->accounts->signIn($p->id, false, $version, $op, 'Fictional access review');
        $disabled = $s->sql->one('users', ['user_id' => $p->ownerUserId->value]);
        self::assertSame(0, (int) $disabled['sign_in_enabled']);
        self::assertNotSame($before['token'], $disabled['token']);
        self::assertSame($before['password'], $disabled['password']);
        self::assertNull($auth->authenticate($before['email'], PersonManagementFixture::PASSWORD));
        self::assertFalse($auth->find($before['token']));
        self::assertFalse($auth->find($disabled['token']));
        self::assertFalse($authority->permits($proof));
        self::assertFalse($authority->activeReference($session->id, $p->ownerUserId));
        self::assertFalse($f->access->allows($p->ownerUserId->value, 'admin:profile'));
        self::assertFalse($f->accounts->view($p->id)['sign_in_enabled']);
        $stored = $this->history($f)->page(new StreamReference(new UserId($p->ownerUserId->value), 'user'));
        $last = $stored->events[array_key_last($stored->events)];
        self::assertSame($admin->value, $last->context->actor->value);
        self::assertSame('Fictional access review', $last->context->reason);
        self::assertSame($op->value, $last->context->correlation->value);
        self::assertNotNull($last->context->effectiveAt);
        self::assertStringNotContainsString(
            'Fictional access review',
            json_encode($s->sql->rows('sis_history_events'))
        );
        self::assertStringNotContainsString('Fictional access review', json_encode($s->sql->rows('sis_audit')));
        $f->accounts->signIn($p->id, true, $version + 1, EntityId::generate(), 'Fictional access restored');
        $enabled = $s->sql->one('users', ['user_id' => $p->ownerUserId->value]);
        self::assertNotSame($disabled['token'], $enabled['token']);
        self::assertNotNull($auth->authenticate($before['email'], PersonManagementFixture::PASSWORD));
        self::assertFalse($auth->find($before['token']));
        self::assertFalse($auth->find($disabled['token']));
        self::assertNotFalse($auth->find($enabled['token']));
        self::assertFalse($authority->activeReference($session->id, $p->ownerUserId));
        self::assertTrue($f->access->allows($p->ownerUserId->value, 'admin:profile'));
        self::assertSame($person, $s->sql->rows('sis_persons'));
        self::assertSame($grants, $s->sql->rows('sis_access_user_roles'));
        $after = $s->sql->rows('sis_history_events');
        self::assertCount(count($events) + 2, $after);
        foreach ($events as $old) {
            self::assertContains($old, $after);
        }
        // A delayed retry must not disable the subsequently restored account.
        $f->accounts->signIn($p->id, false, $version, $op, 'Fictional access review');
        self::assertSame($after, $s->sql->rows('sis_history_events'));
        self::assertTrue($f->accounts->view($p->id)['sign_in_enabled']);
    }
    public function testExpectedVersionAndOperationIntentCannotBeReusedForAnotherDecision(): void
    {
        $f = new PersonManagementFixture();
        $p = $f->person();
        $v = $f->accounts->view($p->id)['version'];
        $op = EntityId::generate();
        $f->accounts->signIn($p->id, false, $v, $op, 'Fictional access review');
        try {
            $f->accounts->signIn($p->id, true, $v, EntityId::generate(), 'Fictional restore');
            self::fail('A stale decision was accepted.');
        } catch (ConcurrentModificationException) {

        }
        $this->expectException(AccessDeniedException::class);
        $f->accounts->signIn($p->id, true, $v, $op, 'Fictional access review');
    }
    public function testOnlyAnotherAuthorizedAdministratorCanChangeSignIn(): void
    {
        $f = new PersonManagementFixture();
        $p = $f->person();
        $v = $f->accounts->view($p->id)['version'];
        $admin = $f->sis->access->actor;
        $f->sis->access->actor = $p->ownerUserId;
        try {
            $f->accounts->signIn($p->id, false, $v, EntityId::generate(), 'Fictional self change');
            self::fail('Self disabling was accepted.');
        } catch (AccessDeniedException) {

        }
        $f->sis->access->actor = $admin;
        $f->access->assign(GrantKind::UserRole, $p->ownerUserId, $f->access->roleNamed('super')->id, true);
        try {
            $f->accounts->signIn($p->id, false, $v, EntityId::generate(), 'Fictional super change');
            self::fail('A non-super administrator changed a super account.');
        } catch (AccessDeniedException) {

        }
        $f->access->assign(GrantKind::UserRole, $admin, $f->access->roleNamed('super')->id, true);
        $f->accounts->signIn($p->id, false, $v, EntityId::generate(), 'Fictional super review');
        self::assertTrue($f->access->isSuper($p->ownerUserId->value));
        self::assertFalse($f->access->allows($p->ownerUserId->value, 'anything'));
        self::assertTrue($f->access->anySuper());
        $f->access->assign(GrantKind::UserRole, $admin, $f->access->roleNamed('super')->id, false);
        self::assertFalse($f->access->anySuper());
        self::assertFalse($f->access->anyoneCanManage());
    }
    public function testDisablingTheLastAccessManagerRollsBackTheWholeDecision(): void
    {
        $f = new PersonManagementFixture();
        $p = $f->person();
        $f->access->assign(
            GrantKind::UserPermission,
            $p->ownerUserId,
            new EntityId($f->sis->sql->one('sis_access_permissions', ['name' => 'admin:access:manage'])['id']),
            true
        );
        self::assertTrue($f->access->anyoneCanManage());
        $before = $f->sis->sql->rows('sis_history_events');
        try {
            $f->accounts->signIn(
                $p->id,
                false,
                $f->accounts->view($p->id)['version'],
                EntityId::generate(),
                'Fictional review'
            );
            self::fail('The last active access manager was disabled.');
        } catch (DomainException) {

        }
        self::assertSame($before, $f->sis->sql->rows('sis_history_events'));
        self::assertTrue($f->accounts->view($p->id)['sign_in_enabled']);
    }
    public function testAnotherActorCannotReuseTheOriginalOperation(): void
    {
        $f = new PersonManagementFixture();
        $p = $f->person();
        $version = $f->accounts->view($p->id)['version'];
        $op = EntityId::generate();
        $f->accounts->signIn($p->id, false, $version, $op, 'Fictional review');
        $f->sis->access->actor = $f->sis->user();
        $this->expectException(AccessDeniedException::class);
        $f->accounts->signIn($p->id, false, $version, $op, 'Fictional review');
    }
    public function testRequiredAuditFailureRollsBackStatusTokenAndHistoryAndAllowsRetry(): void
    {
        $f = new PersonManagementFixture();
        $p = $f->person();
        $v = $f->accounts->view($p->id)['version'];
        $op = EntityId::generate();
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willThrowException(new RuntimeException('Fictional audit failure'));
        $editor = new PersonAccountEditor(
            $f->sis->admissions,
            $f->users,
            $f->guard,
            $f->sis->access,
            $audit,
            $f->sis->profileLedger(),
            $this->history($f)
        );
        $tables = ['users', 'sis_history_events', 'sis_history_streams', 'sis_history_operations'];
        $before = array_map(fn($t) => $f->sis->sql->rows($t), $tables);
        try {
            $editor->signIn($p->id, false, $v, $op, 'Fictional review');
            self::fail('Required audit failure was ignored.');
        } catch (RuntimeException $e) {
            self::assertSame('Fictional audit failure', $e->getMessage());
        }
        self::assertSame($before, array_map(fn($t) => $f->sis->sql->rows($t), $tables));
        self::assertTrue($f->accounts->view($p->id)['sign_in_enabled']);
        $f->accounts->signIn($p->id, false, $v, $op, 'Fictional review');
        self::assertFalse($f->accounts->view($p->id)['sign_in_enabled']);
    }
    public function testAccountMaintenanceStopsBeforeMaterializingAnOversizedStream(): void
    {
        $f = new PersonManagementFixture();
        $history = $this->createMock(\Domain\Shared\EventSourcing\HistoricalEventStore::class);
        $history->expects(self::once())->method('page')->willReturn(
            new \Domain\Shared\EventSourcing\EventPage([], null, 2001)
        );
        $repo = new \Infrastructure\Persistence\Repository\EventSourcedUserRepository(
            $this->createStub(\Codefy\Domain\EventSourcing\TransactionalEventStore::class),
            $this->createStub(\Domain\User\Service\UserProjection::class),
            $f->sis->connection->queryBuilder(),
            $history
        );
        $this->expectException(\Domain\Shared\EventSourcing\HistoryReadLimitException::class);
        $repo->loadAggregateRoot(new UserId());
    }
    public function testReasonIsRequiredAndProjectionDriftIsNotAcceptedAsHistory(): void
    {
        $f = new PersonManagementFixture();
        $p = $f->person();
        try {
            $f->accounts->signIn($p->id, false, $f->accounts->view($p->id)['version'], EntityId::generate(), '   ');
            self::fail('A blank reason was accepted.');
        } catch (ValidationException) {

        }
        $f->sis->sql->update('users', ['sign_in_enabled' => 0], ['user_id' => $p->ownerUserId->value]);
        $this->expectException(CorruptHistoryException::class);
        $f->accounts->view($p->id);
    }
}
