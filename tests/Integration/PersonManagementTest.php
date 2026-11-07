<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/PersonManagementFixture.php';

use Codefy\Framework\Support\Password;
use Domain\Access\Enum\GrantKind;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\User\Command\UpdateUserPasswordCommand;
use Domain\User\Command\UpdateUserPasswordCommandHandler;
use Domain\User\ValueObject\UserId;
use Domain\User\ValueObject\UserRole;
use Domain\User\ValueObject\UserToken;
use PHPUnit\Framework\TestCase;
use Qubus\ValueObjects\StringLiteral\StringLiteral;

final class PersonManagementTest extends TestCase
{
    public function testRoleNamesIncludeCustomDefinitionsWithoutRequiringAnEnumChange(): void
    {
        foreach (['super', 'faculty', 'staff', 'student', 'parent', 'registrar-office', 'custom:advisor'] as $role) {
            self::assertSame($role, new UserRole($role)->toNative());
        }
        $this->expectException(InvalidArgumentException::class);
        new UserRole("super\n");
    }

    public function testProfileEditUpdatesTheSamePersonAndAccountWithNoDuplicateIdentity(): void
    {
        $fixture = new PersonManagementFixture();
        $person = $fixture->person();
        $record = $fixture->profiles->view($person->id);
        $fixture->profiles->save($person->id, 'A', 'Updated', false, $record['revision']);
        $current = $fixture->sis->admissions->person($person->id);
        self::assertSame('A', $current->givenName);
        self::assertFalse($current->directoryRestricted);
        self::assertSame($person->institutionalId->value, $current->institutionalId->value);
        self::assertSame($person->ownerUserId->value, $current->ownerUserId->value);
        self::assertCount(1, $fixture->sis->sql->rows('sis_persons'));
        $account = $fixture->sis->sql->one('users', ['user_id' => $person->ownerUserId->value]);
        self::assertSame('A', $account['first_name']);
        self::assertSame('Updated', $account['last_name']);
        self::assertStringNotContainsString('Updated', json_encode($fixture->sis->audit->forSubject($person->id)));
        $this->expectException(ConcurrentModificationException::class);
        $fixture->profiles->save($person->id, 'Stale', 'Example', true, $record['revision']);
    }

    public function testStaffCanEditAnUnlinkedProfileButOwnershipAloneCannotEditLegalNames(): void
    {
        $fixture = new PersonManagementFixture();
        $person = $fixture->person(false);
        $record = $fixture->profiles->view($person->id);
        $fixture->profiles->save($person->id, 'Jamie', 'Example', true, $record['revision']);
        self::assertSame('Jamie', $fixture->sis->admissions->person($person->id)->givenName);
        $fixture->sis->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $fixture->profiles->save($person->id, 'Unauthorized', 'Example', false,
            $fixture->profiles->view($person->id)['revision']);
    }

    public function testOnlySuperAdministratorsCanChangeSuperAccountCredentialsOrNames(): void
    {
        $fixture = new PersonManagementFixture();
        $person = $fixture->person();
        $fixture->access->assign(GrantKind::UserRole, $person->ownerUserId,
            $fixture->access->roleNamed('super')->id, true);
        $record = $fixture->profiles->view($person->id);
        try {
            $fixture->profiles->save($person->id, 'Takeover', 'Attempt', true, $record['revision']);
            self::fail('A non-super editor changed a super profile.');
        } catch (AccessDeniedException) {
            self::assertSame('Alex', $fixture->sis->admissions->person($person->id)->givenName);
        }
        $this->expectException(AccessDeniedException::class);
        $fixture->accounts->view($person->id);
    }

    public function testAccountEmailEditRejectsStaleVersionsAndDoesNotAssignRoles(): void
    {
        $fixture = new PersonManagementFixture();
        $person = $fixture->person();
        $record = $fixture->accounts->view($person->id);
        self::assertSame(['username', 'email', 'version', 'minimum_password_length', 'is_self'], array_keys($record));
        $roles = $fixture->access->assignments(GrantKind::UserRole, $person->ownerUserId, null, 100);
        $fixture->accounts->email($person->id, 'updated@example.invalid', $record['version']);
        self::assertSame('updated@example.invalid', $fixture->accounts->view($person->id)['email']);
        self::assertSame($roles, $fixture->access->assignments(GrantKind::UserRole, $person->ownerUserId, null, 100));
        $this->expectException(ConcurrentModificationException::class);
        $fixture->accounts->email($person->id, 'stale@example.invalid', $record['version']);
    }

    public function testPasswordResetHashesOnceRotatesSignInTokenAndKeepsSecretsOutOfAudit(): void
    {
        $fixture = new PersonManagementFixture();
        $person = $fixture->person();
        $record = $fixture->accounts->view($person->id);
        $before = $fixture->sis->sql->one('users', ['user_id' => $person->ownerUserId->value]);
        $password = 'fictional-replacement-password-for-tests';
        $fixture->accounts->password($person->id, $password, $record['version']);
        $after = $fixture->sis->sql->one('users', ['user_id' => $person->ownerUserId->value]);
        self::assertNotSame($before['token'], $after['token']);
        self::assertTrue(Password::verify($password, $after['password']));
        self::assertFalse(Password::verify(PersonManagementFixture::PASSWORD, $after['password']));
        self::assertStringNotContainsString($password, json_encode($fixture->sis->audit->forSubject($person->id)));
        self::assertStringNotContainsString($after['token'], json_encode($fixture->accounts->view($person->id)));
    }

    public function testSelfPasswordChangeRequiresCurrentPassword(): void
    {
        $fixture = new PersonManagementFixture();
        $person = $fixture->person();
        $fixture->sis->access->actor = $person->ownerUserId;
        $record = $fixture->accounts->view($person->id);
        self::assertTrue($record['is_self']);
        try {
            $fixture->accounts->password($person->id, 'fictional-replacement-password-for-tests', $record['version']);
            self::fail('Current password was bypassed.');
        } catch (AccessDeniedException) {
            self::assertSame($record['version'], $fixture->accounts->view($person->id)['version']);
        }
        $fixture->accounts->password($person->id, 'fictional-replacement-password-for-tests',
            $record['version'], PersonManagementFixture::PASSWORD);
        self::assertGreaterThan($record['version'], $fixture->accounts->view($person->id)['version']);
    }

    public function testPasswordCommandCannotBypassAuthorization(): void
    {
        $fixture = new PersonManagementFixture();
        $person = $fixture->person();
        $fixture->sis->access->staff = false;
        $fixture->sis->access->grants = ['admin:profile'];
        $command = new UpdateUserPasswordCommand(['userId' => new UserId($person->ownerUserId->value),
            'password' => new StringLiteral('fictional-prehashed-value'), 'token' => new UserToken()]);
        $handler = new UpdateUserPasswordCommandHandler($fixture->users, $fixture->guard);
        $this->expectException(AccessDeniedException::class);
        $handler->handle($command);
    }
    public function testFailedEmailProjectionRollsBackAndDoesNotPoisonSubsequentAccountReads(): void
    {
        $fixture = new PersonManagementFixture();
        $first = $fixture->person();
        $second = $fixture->person();
        $record = $fixture->accounts->view($first->id);
        $other = $fixture->accounts->view($second->id);
        try {
            $fixture->accounts->email($first->id, $other['email'], $record['version']);
            self::fail('A duplicate account email was accepted.');
        } catch (PDOException) {
            self::assertSame($record['email'], $fixture->sis->sql->one('users',
                ['user_id' => $first->ownerUserId->value])['email']);
            self::assertSame($record['email'], $fixture->accounts->view($first->id)['email']);
            self::assertSame($record['version'], $fixture->accounts->view($first->id)['version']);
        }
    }

    public function testLinkedProfileNeedsAccountEditPermissionAndAuditFailureRollsBackBothProfiles(): void
    {
        $fixture = new PersonManagementFixture();
        $person = $fixture->person();
        $record = $fixture->profiles->view($person->id);
        $fixture->sis->access->staff = false;
        $fixture->sis->access->grants = ['sis:person:manage'];
        try {
            $fixture->profiles->save($person->id, 'Unauthorized', 'Example', false, $record['revision']);
            self::fail('Person directory access implied account editing.');
        } catch (AccessDeniedException) {
            self::assertSame('Alex', $fixture->sis->admissions->person($person->id)->givenName);
        }
        $fixture->sis->access->staff = true;
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willThrowException(new RuntimeException('Fictional audit failure.'));
        $editor = new \Domain\Person\Service\PersonProfileEditor($fixture->sis->admissions,
            new \Infrastructure\Persistence\Repository\SqlPersonProfileRepository($fixture->sis->sql),
            $fixture->users, $fixture->guard, $fixture->sis->access, $fixture->sis->transactions, $audit);
        try {
            $editor->save($person->id, 'RolledBack', 'Example', false, $record['revision']);
            self::fail('An unaudited edit committed.');
        } catch (RuntimeException) {
            self::assertSame('Alex', $fixture->sis->admissions->person($person->id)->givenName);
            self::assertSame('Alex', $fixture->sis->sql->one('users', ['user_id' => $person->ownerUserId->value])['first_name']);
            $user = $fixture->users->loadAggregateRoot(new UserId($person->ownerUserId->value));
            self::assertSame('Alex', $user->name()->getFirstName()->toNative());
        }
    }

    public function testHistoricalRoleEventsAcceptCustomNamesWithoutGrantingEffectiveAccess(): void
    {
        $fixture = new PersonManagementFixture();
        $person = $fixture->person();
        foreach (['super', 'custom:admissions-office'] as $name) {
            $user = $fixture->users->loadAggregateRoot(new UserId($person->ownerUserId->value));
            $user->changeRole(new UserRole($name));
            $fixture->users->saveAggregateRoot($user);
            $replayed = $fixture->users->loadAggregateRoot(new UserId($person->ownerUserId->value));
            self::assertSame($name, $replayed->role()->toNative());
            self::assertFalse($fixture->access->allows($person->ownerUserId->value, 'admin:access:manage'));
            self::assertFalse($fixture->access->isSuper($person->ownerUserId->value));
        }
    }

}
