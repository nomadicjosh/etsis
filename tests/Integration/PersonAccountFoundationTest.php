<?php

declare(strict_types=1);

namespace Tests\Integration;

use Codefy\Framework\Support\Password;
use Domain\Communications\Service\MailTransport;
use Domain\Person\Service\AccountSetupService;
use Domain\Person\Service\PersonRegistrationService;
use Domain\Person\ValueObject\PersonProfileId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\ValueObject\UserId;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\Repository\EventSourcedUserRepository;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlAccountSetupRepository;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use Infrastructure\Projection\ExpressiveDbalUserProjection;
use PHPUnit\Framework\TestCase;
use SisFixture;
use PersonManagementFixture;

final class PersonAccountFoundationTest extends TestCase
{
    private const string PASSWORD = 'a-fictional-campus-password-with-enough-length';
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/PersonManagementFixture.php';
    }
    public function testStaffProfileReasonsBindTheCorrectionAndRemainProtectedWithTheOriginalFacts(): void
    {
        $f = new PersonManagementFixture();
        $person = $f->person();
        $before = $f->sis->sql->rows('sis_history_events');
        $record = $f->profiles->view($person->id);
        foreach (['', str_repeat('x', 1001), "Invalid\x00reason"] as $invalid) {
            try {
                $f->profiles->save(
                    $person->id,
                    'Corrected',
                    'Example',
                    true,
                    $record['revision'],
                    $invalid,
                );
                self::fail('An invalid correction reason was accepted.');
            } catch (ValidationException) {
                self::assertSame($before, $f->sis->sql->rows('sis_history_events'));
            }
        }
        $reason = 'Fictional verified name evidence; staff-only correction context.';
        $f->profiles->save(
            $person->id,
            'Corrected',
            'Example',
            true,
            $record['revision'],
            $reason,
        );
        $profile = $this->history($f->sis)->page(new StreamReference(
            new PersonProfileId($person->id->value),
            'person_profile',
        ))->events;
        self::assertSame($reason, $profile[array_key_last($profile)]->context->reason);
        self::assertSame(
            'Current profile observed; earlier personal history is unknown.',
            $profile[0]->context->reason,
        );
        $account = $this->history($f->sis)->page(new StreamReference(
            new UserId($person->ownerUserId->value),
            'user',
        ))->events;
        self::assertSame($reason, $account[array_key_last($account)]->context->reason);
        $accepted = $f->sis->sql->rows('sis_history_events');
        self::assertStringNotContainsString($reason, json_encode($accepted, JSON_THROW_ON_ERROR));
        foreach ($before as $row) {
            self::assertSame($row, $f->sis->sql->one('sis_history_events', ['id' => $row['id']]));
        }
        $f->profiles->save(
            $person->id,
            'Corrected',
            'Example',
            true,
            $record['revision'],
            $reason,
        );
        self::assertSame($accepted, $f->sis->sql->rows('sis_history_events'));
        $this->expectException(ConcurrentModificationException::class);
        $f->profiles->save(
            $person->id,
            'Corrected',
            'Example',
            true,
            $record['revision'],
            'Different fictional evidence.',
        );
    }

    public function testProfileCorrectionRechecksSourceAndAuthorityAfterAuditAndRollsBackBothHistories(): void
    {
        foreach (['source', 'authority'] as $failure) {
            $f = new PersonManagementFixture();
            $s = $f->sis;
            $person = $f->person();
            $record = $f->profiles->view($person->id);
            $tables = ['sis_persons', 'users', 'sis_history_events', 'sis_history_streams',
                'sis_history_operations', 'sis_audit'];
            $before = array_map($s->sql->rows(...), $tables);
            $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($s, $person, $failure): void {
                if ($failure === 'source') {
                    $s->sql->update(
                        'sis_persons',
                        ['family_name' => 'Fictional late change'],
                        ['id' => $person->id->value],
                    );
                } else {
                    $s->access->staff = false;
                    $s->access->grants = ['admin:edit:user'];
                }
            });
            $editor = new \Domain\Person\Service\PersonProfileEditor(
                $s->admissions,
                new \Infrastructure\Persistence\Repository\SqlPersonProfileRepository($s->sql),
                $f->users,
                $f->guard,
                $s->access,
                $s->transactions,
                $audit,
                $s->profileLedger()
            );
            try {
                $editor->save(
                    $person->id,
                    'Fictional corrected',
                    'Example',
                    true,
                    $record['revision'],
                    'Fictional verified institutional evidence.'
                );
                self::fail('An unfinished profile correction committed.');
            } catch (ConcurrentModificationException | \Domain\Shared\Exception\AccessDeniedException) {
                self::assertSame($before, array_map($s->sql->rows(...), $tables));
            }
        }
    }

    private function history(SisFixture $s): SqlHistoricalEventStore
    {
        return new SqlHistoricalEventStore(
            $s->sql,
            $s->transactions,
            $s->clock,
            new ProtectedEventCodec(\EventStoreFixture::config($s->connection->queryBuilder())),
            new HistoricalSchemaRegistry()
        );
    }
    private function users(SisFixture $s): EventSourcedUserRepository
    {
        $s->registrationService();
        $db = $s->connection->queryBuilder();
        return new EventSourcedUserRepository(
            \EventStoreFixture::framework($db),
            new ExpressiveDbalUserProjection(
                $db,
                new SqlAccessRepository($s->sql),
                new SqlPersonAccountRepository($s->sql, $s->admissions, new RandomInstitutionalIdGenerator())
            ),
            $db,
            $this->history($s)
        );
    }
    private function onboarding(SisFixture $s, ?MailTransport $mail = null): AccountSetupService
    {
        return new AccountSetupService(
            new SqlAccountSetupRepository($s->sql),
            $this->users($s),
            $s->transactions,
            $s->clock,
            $s->dropCipher,
            $mail ?? $this->createStub(MailTransport::class),
            $s->profileLedger(),
            $s->admissions,
            $s->access,
            $s->audit,
            new \Domain\User\Service\UserAccountAccess(new SqlAccessRepository($s->sql), $s->access, $s->transactions),
            'https://fictional.example.invalid'
        );
    }
    private function token(SisFixture $s, EntityId $person): string
    {
        $row = $s->sql->one('sis_account_setup', ['person_id' => $person->value]);
        $notice = json_decode($s->dropCipher->open($row['encrypted_notice']), true, flags: JSON_THROW_ON_ERROR);
        preg_match('/#([a-f0-9]{64})/', $notice['body'], $m);
        return $m[1];
    }
    public function testCleanedUpInvitationRemainsExpiredAndCanBeDeliberatelyReissuedAndUsed(): void
    {
        $s = new SisFixture();
        $person = $s->registrationService()->staff(
            EntityId::generate(),
            'Fictional',
            'Person',
            'setup@example.invalid'
        );
        $old = $this->token($s, $person->id);
        $setup = $this->onboarding($s);
        $s->clock->time = $s->clock->time->modify('+73 hours');
        $retention = new \Domain\Person\Service\AccountSetupRetention(
            new SqlAccountSetupRepository($s->sql),
            $s->dropCipher,
            $s->transactions,
            $s->clock
        );
        self::assertSame(1, $retention->run(apply: true)['cleared']);
        self::assertSame(['state' => 'expired', 'pending' => true], $setup->status($person->id));
        try {
            $setup->complete($old, self::PASSWORD);
            self::fail('An expired invitation still worked.');
        } catch (\DomainException) {
        }
        $setup->reissue($person->id);
        $fresh = $this->token($s, $person->id);
        self::assertNotSame($old, $fresh);
        self::assertSame(0, $retention->run(apply: true)['examined']);
        $setup->complete($fresh, self::PASSWORD);
        self::assertFalse($setup->status($person->id)['pending']);
    }

    public function testAReissueBetweenRetentionInspectionAndLockIsKeptIntact(): void
    {
        $s = new SisFixture();
        $person = $s->registrationService()->staff(EntityId::generate(), 'Fictional', 'Person', 'race@example.invalid');
        $setup = $this->onboarding($s);
        $real = new SqlAccountSetupRepository($s->sql);
        $repository = $this->createMock(\Domain\Person\Repository\AccountSetupRepository::class);
        $repository->method('retentionCandidates')->willReturnCallback($real->retentionCandidates(...));
        $repository->method('retentionRecord')->willReturnCallback(
            static function (EntityId $id, bool $lock = false) use ($setup, $person, $real): ?array {
                if ($lock) {
                    $setup->reissue($person->id);
                }
                return $real->retentionRecord($id, $lock);
            }
        );
        $repository->expects(self::never())->method('redactNotice');
        $s->clock->time = $s->clock->time->modify('+73 hours');
        $retention = new \Domain\Person\Service\AccountSetupRetention(
            $repository,
            $s->dropCipher,
            $s->transactions,
            $s->clock
        );
        $result = $retention->run(apply: true);
        self::assertSame([1, 0], [$result['changed'], $result['cleared']]);
        self::assertSame('queued', $setup->status($person->id)['state']);
        $setup->complete($this->token($s, $person->id), self::PASSWORD);
        self::assertFalse($setup->status($person->id)['pending']);
    }

    public function testStaffCreationIsAtomicIdempotentAndCreatesOnlyABasicLinkedAccount(): void
    {
        $s = new SisFixture();
        $creator = $s->registrationService();
        $op = EntityId::generate();
        $p = $creator->staff($op, 'Fictional', 'Applicant', 'Fictional@Example.invalid', '001234');
        self::assertNotNull($p->ownerUserId);
        self::assertSame('001234', $p->institutionalId->value);
        self::assertCount(1, $s->sql->rows('sis_persons'));
        self::assertSame([], $s->sql->rows('sis_students'));
        self::assertSame([], $s->sql->rows('sis_employees'));
        self::assertSame(
            'fictional@example.invalid',
            $s->sql->one('users', ['user_id' => $p->ownerUserId->value])['email'],
        );
        $role = new SqlAccessRepository($s->sql);
        self::assertFalse($role->isSuper($p->ownerUserId->value));
        $before = $s->sql->rows('sis_history_events');
        $again = $creator->staff($op, 'Fictional', 'Applicant', 'fictional@example.invalid', '001234');
        self::assertSame($p->id->value, $again->id->value);
        self::assertSame($before, $s->sql->rows('sis_history_events'));
        $stored = $this->history($s)->page(
            new StreamReference(new PersonProfileId($p->id->value), 'person_profile'),
        )->events[0];
        self::assertSame('created', $stored->event->payload()['provenance']);
        self::assertSame($s->access->actor->value, $stored->context->actor->value);
        self::assertSame($p->id->value, $stored->event->payload()['facts']['id']);
        $token = $this->token($s, $p->id);
        self::assertStringNotContainsString(
            $token,
            json_encode($s->sql->rows('sis_account_setup'), JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString('Fictional', json_encode($s->sql->rows('sis_audit'), JSON_THROW_ON_ERROR));
    }
    public function testSelfRegistrationUsesTheSameParentAndDoesNotHashTheValidatedPasswordTwice(): void
    {
        $s = new SisFixture();
        $p = $s->registrationService()->register(
            EntityId::generate(),
            'Fictional',
            'Student',
            'student@example.invalid',
            'fictional_student',
            Password::hash(self::PASSWORD)
        );
        $u = $s->sql->one('users', ['user_id' => $p->ownerUserId->value]);
        self::assertTrue(Password::verify(self::PASSWORD, $u['password']));
        self::assertSame([], $s->sql->rows('sis_account_setup'));
        self::assertSame([], $s->sql->rows('sis_applications'));
        self::assertSame($p->id->value, $s->admissions->person($p->id)->id->value);
    }
    public function testSetupIsOneUseAndRotatesCredentialsWithoutChangingThePerson(): void
    {
        $s = new SisFixture();
        $p = $s->registrationService()->staff(EntityId::generate(), 'Fictional', 'Staff', 'staff@example.invalid');
        $token = $this->token($s, $p->id);
        $before = $s->sql->one('users', ['user_id' => $p->ownerUserId->value]);
        $this->onboarding($s)->complete($token, self::PASSWORD);
        $after = $s->sql->one('users', ['user_id' => $p->ownerUserId->value]);
        self::assertTrue(Password::verify(self::PASSWORD, $after['password']));
        self::assertNotSame($before['token'], $after['token']);
        self::assertSame($p->id->value, $s->admissions->person($p->id)->id->value);
        self::assertNull($s->sql->one('sis_account_setup', ['person_id' => $p->id->value])['encrypted_notice']);
        $events = $s->sql->rows('sis_history_events');
        try {
            $this->onboarding($s)->complete($token, 'a-different-fictional-campus-password-of-sufficient-length');
            self::fail('Reused a token.');
        } catch (\DomainException) {
            self::assertSame($events, $s->sql->rows('sis_history_events'));
        }
    }
    public function testExpiredAndCredentialChangedInvitationsCannotChangePasswords(): void
    {
        foreach (['expired', 'credentials'] as $case) {
            $s = new SisFixture();
            $p = $s->registrationService()->staff(
                EntityId::generate(),
                'Fictional',
                'Staff',
                $case . '@example.invalid',
            );
            $token = $this->token($s, $p->id);
            if ($case === 'expired') {
                $s->clock->time = $s->clock->time->modify('+49 hours');
            } else {
                $u = $this->users($s)->loadAggregateRoot(new UserId($p->ownerUserId->value));
                $u->changePassword(
                    new \Qubus\ValueObjects\StringLiteral\StringLiteral(Password::hash(self::PASSWORD)),
                    new \Domain\User\ValueObject\UserToken(),
                );
                $this->users($s)->saveAggregateRoot($u);
            }
            $events = $s->sql->rows('sis_history_events');
            try {
                $this->onboarding($s)->complete($token, self::PASSWORD);
                self::fail('Accepted invalid setup.');
            } catch (\DomainException) {
                self::assertSame($events, $s->sql->rows('sis_history_events'));
            }
        }
    }
    public function testTamperedSetupGrantCannotAuthorizeCredentialChanges(): void
    {
        foreach (['token_hash', 'expires_at', 'user_id', 'credential_hash'] as $field) {
            $s = new SisFixture();
            $p = $s->registrationService()->staff(EntityId::generate(), 'Fictional', 'Person', 'first@example.invalid');
            $token = $this->token($s, $p->id);
            $value = match ($field) {
                'token_hash' => hash('sha256', str_repeat('b', 64)),
                'expires_at' => '2099-01-01T00:00:00.000000Z',
                'user_id' => EntityId::generate()->value,
                'credential_hash' => str_repeat('c', 64),
            };
            // Disable only the fictional SQLite FK to prove even a poisoned target cannot authorize a reset.
            if ($field === 'user_id') {
                $s->connection->pdo->exec('PRAGMA foreign_keys = OFF');
            }
            $s->sql->update('sis_account_setup', [$field => $value], ['person_id' => $p->id->value]);
            $events = $s->sql->rows('sis_history_events');
            try {
                $this->onboarding($s)->complete($field === 'token_hash' ? str_repeat('b', 64) : $token, self::PASSWORD);
                self::fail('A modified grant authorized a password change.');
            } catch (\DomainException) {
                self::assertSame($events, $s->sql->rows('sis_history_events'));
            }
        }
    }
    public function testInvitationReissueRequiresAccountAuthorityAndProtectsSuperAccounts(): void
    {
        $s = new SisFixture();
        $p = $s->registrationService()->staff(EntityId::generate(), 'Fictional', 'Person', 'protected@example.invalid');
        $s->clock->time = $s->clock->time->modify('+2 minutes');
        $before = $s->sql->rows('sis_account_setup');
        $s->access->staff = false;
        $s->access->grants = ['sis:person:manage'];
        try {
            $this->onboarding($s)->reissue($p->id);
            self::fail('Person access alone reissued a credential grant.');
        } catch (\Domain\Shared\Exception\AccessDeniedException) {
            self::assertSame($before, $s->sql->rows('sis_account_setup'));
        }
        $s->access->staff = true;
        $roles = new SqlAccessRepository($s->sql);
        $roles->assign(\Domain\Access\Enum\GrantKind::UserRole, $p->ownerUserId, $roles->roleNamed('super')->id, true);
        try {
            $this->onboarding($s)->reissue($p->id);
            self::fail('A non-super administrator reissued a super account grant.');
        } catch (\Domain\Shared\Exception\AccessDeniedException) {
            self::assertSame($before, $s->sql->rows('sis_account_setup'));
        }
    }
    public function testSuccessfulDeliveryClearsTheProtectedNoticeAndKeepsTheOneUseGrant(): void
    {
        $s = new SisFixture();
        $p = $s->registrationService()->staff(EntityId::generate(), 'Fictional', 'Person', 'notice@example.invalid');
        $token = $this->token($s, $p->id);
        $mail = $this->createMock(MailTransport::class);
        $mail->expects(self::once())->method('send')->with(
            self::isInstanceOf(EntityId::class),
            'notice@example.invalid',
            'Set up your campus password',
            self::stringContains('/portal/password-setup/#' . $token)
        );
        $setup = $this->onboarding($s, $mail);
        self::assertSame(1, $setup->dispatch()['sent']);
        self::assertSame(0, $setup->dispatch()['examined']);
        self::assertNull($s->sql->one('sis_account_setup', ['person_id' => $p->id->value])['encrypted_notice']);
        $setup->complete($token, self::PASSWORD);
        self::assertFalse($setup->status($p->id)['pending']);
    }
    public function testQueuedDeliveryIsBoundedAndUncertainDeliveryNeedsAnExplicitFreshLink(): void
    {
        $s = new SisFixture();
        $p = $s->registrationService()->staff(EntityId::generate(), 'Fictional', 'Staff', 'staff@example.invalid');
        $first = $this->token($s, $p->id);
        $mail = $this->createMock(MailTransport::class);
        $mail->expects(self::once())->method('send')->willThrowException(
            new \RuntimeException('Fictional transport uncertainty'),
        );
        $setup = $this->onboarding($s, $mail);
        self::assertSame(1, $setup->dispatch()['uncertain']);
        self::assertSame(0, $setup->dispatch()['examined']);
        $s->clock->time = $s->clock->time->modify('+2 minutes');
        $setup->reissue($p->id);
        $next = $this->token($s, $p->id);
        self::assertNotSame($first, $next);
        try {
            $setup->complete($first, self::PASSWORD);
            self::fail('The replaced link still worked.');
        } catch (\DomainException) {

        }
        $setup->complete($next, self::PASSWORD);
        self::assertFalse($setup->status($p->id)['pending']);
    }
    public function testDisabledSignInCannotCompleteOrReissueOrSendInitialPasswordSetup(): void
    {
        $s = new SisFixture();
        $p = $s->registrationService()->staff(
            EntityId::generate(),
            'Fictional',
            'Applicant',
            'disabled@example.invalid',
        );
        $user = $this->users($s);
        $accounts = new \Domain\User\Service\PersonAccountEditor(
            $s->admissions,
            $user,
            new \Domain\User\Service\UserAccountAccess(new SqlAccessRepository($s->sql), $s->access, $s->transactions),
            $s->access,
            $s->audit,
            $s->profileLedger(),
            $this->history($s)
        );
        $token = $this->token($s, $p->id);
        $accounts->signIn($p->id, false, $accounts->view($p->id)['version'], EntityId::generate(), 'Fictional review');
        $mail = $this->createMock(MailTransport::class);
        $mail->expects(self::never())->method('send');
        $setup = $this->onboarding($s, $mail);
        $s->clock->time = $s->clock->time->modify('+2 minutes');
        try {
            $setup->complete($token, self::PASSWORD);
            self::fail('A disabled account used an initial-password invitation.');
        } catch (\DomainException) {

        }
        try {
            $setup->reissue($p->id);
            self::fail('A disabled account issued an initial-password invitation.');
        } catch (\DomainException) {

        }
        self::assertSame(1, $setup->dispatch()['expired']);
        $accounts->signIn(
            $p->id,
            true,
            $accounts->view($p->id)['version'],
            EntityId::generate(),
            'Fictional restoration',
        );
        try {
            $setup->complete($token, self::PASSWORD);
            self::fail('Re-enabling revived an earlier invitation.');
        } catch (\DomainException) {

        }
        $setup->reissue($p->id);
        $setup->complete($this->token($s, $p->id), self::PASSWORD);
        self::assertFalse($setup->status($p->id)['pending']);
    }
    public function testPersonStaysTheParentWhenAnEmployeeBecomesAStudent(): void
    {
        $s = new SisFixture();
        $p = $s->registrationService()->staff(
            EntityId::generate(),
            'Fictional',
            'Colleague',
            'colleague@example.invalid',
        );
        $hr = $s->timesheetService();
        $employee = $hr->addEmployee($p->id, 'Fictional analyst');
        $app = $s->submissionService()->apply(EntityId::generate(), $p->id, $s->program, $s->term);
        $review = $s->decisionService()->review($app->id, true, 'Fictional acceptance');
        $s->decisionService()->commit($app->id, $review['review_token']);
        $student = $s->matriculate($app->id);
        self::assertSame($p->id->value, $student->personId->value);
        self::assertSame($p->id->value, $hr->employeeProfile($employee)->person->id->value);
        self::assertSame($p->ownerUserId->value, $s->admissions->person($p->id)->ownerUserId->value);
        self::assertCount(1, $s->sql->rows('sis_persons'));
        self::assertTrue($s->admissions->student($student->id)->active);
    }
    public function testProfileRetriesDoNotOverwriteLaterNamesOrIdentitySelections(): void
    {
        $f = new PersonManagementFixture();
        $p = $f->person();
        $old = $f->profiles->view($p->id);
        $f->profiles->save(
            $p->id,
            'First',
            'Change',
            false,
            $old['revision'],
            'Fictional verified profile correction.',
        );
        $next = $f->profiles->view($p->id);
        $f->profiles->save(
            $p->id,
            'Later',
            'Change',
            true,
            $next['revision'],
            'Fictional verified profile correction.',
        );
        $f->sis->admissionsService()->updateInstitutionalId($p->id, 'RETAIN-2', 0);
        $events = $f->sis->sql->rows('sis_history_events');
        $f->profiles->save(
            $p->id,
            'First',
            'Change',
            false,
            $old['revision'],
            'Fictional verified profile correction.',
        );
        $actual = $f->sis->admissions->person($p->id);
        self::assertSame('Later', $actual->givenName);
        self::assertSame('RETAIN-2', $actual->institutionalId->value);
        self::assertSame($events, $f->sis->sql->rows('sis_history_events'));
    }
    public function testProfileDriftAndConflictingEditsFailWithoutAcceptingNewHistory(): void
    {
        $f = new PersonManagementFixture();
        $p = $f->person();
        $old = $f->profiles->view($p->id);
        $f->profiles->save(
            $p->id,
            'First',
            'Change',
            false,
            $old['revision'],
            'Fictional verified profile correction.',
        );
        try {
            $f->profiles->save(
                $p->id,
                'Conflict',
                'Change',
                false,
                $old['revision'],
                'Fictional verified profile correction.',
            );
            self::fail('Accepted a stale edit.');
        } catch (ConcurrentModificationException) {

        }
        $f->sis->sql->update('sis_persons', ['given_name' => 'Tampered'], ['id' => $p->id->value]);
        $this->expectException(CorruptHistoryException::class);
        $f->profiles->view($p->id);
    }
    public function testDuplicateEmailAndAuditFailureDoNotLeavePartialPeopleAccountsOrInvitations(): void
    {
        $s = new SisFixture();
        $service = $s->registrationService();
        $service->staff(EntityId::generate(), 'First', 'Person', 'same@example.invalid');
        $before = $s->sql->rows('sis_history_events');
        try {
            $service->staff(EntityId::generate(), 'Other', 'Person', 'SAME@example.invalid');
            self::fail('Duplicate account created.');
        } catch (ValidationException) {
            self::assertSame($before, $s->sql->rows('sis_history_events'));
        }
        self::assertCount(1, $s->sql->rows('sis_persons'));
        self::assertCount(1, $s->sql->rows('sis_account_setup'));
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willThrowException(new \RuntimeException('Fictional required audit failure'));
        $links = new SqlPersonAccountRepository($s->sql, $s->admissions, new RandomInstitutionalIdGenerator());
        $bad = new PersonRegistrationService(
            $s->admissions,
            $links,
            $this->users($s),
            $s->profileLedger(),
            new SqlAccountSetupRepository($s->sql),
            $s->access,
            $s->transactions,
            $audit,
            $s->clock,
            $s->dropCipher,
            'https://fictional.example.invalid'
        );
        try {
            $bad->staff(EntityId::generate(), 'Failed', 'Creation', 'failed@example.invalid');
            self::fail('Committed without an audit.');
        } catch (\RuntimeException) {
            self::assertSame($before, $s->sql->rows('sis_history_events'));
        }
        self::assertCount(1, $s->sql->rows('sis_persons'));
        self::assertCount(1, $s->sql->rows('sis_account_setup'));
    }
}
