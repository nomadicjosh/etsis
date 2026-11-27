<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use Tests\Support\EmailVerificationFixture;

final class EmailVerificationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/EmailVerificationFixture.php';
    }

    public function testOwnerRequestsConfirmsAndRepeatsWithoutChangingAccountsProfilesOrHistory(): void
    {
        $f = new EmailVerificationFixture();
        $s = $f->sis;
        $before = array_map($s->sql->rows(...), ['users', 'sis_persons', 'sis_history_events', 'sis_applications']);
        $service = $f->service();
        self::assertFalse($service->status()['verified']);
        $request = $service->request(0);
        self::assertSame('queued', $request['state']);
        self::assertSame($request, $service->request(0));
        self::assertStringNotContainsString(
            'proof@example.invalid',
            json_encode($s->sql->rows('sis_email_verifications'))
        );
        $token = $f->token();
        self::assertStringNotContainsString($token, json_encode($request));
        $confirmed = $service->confirm($token);
        self::assertTrue($confirmed['verified']);
        $audits = $s->sql->rows('sis_audit');
        self::assertSame($confirmed, $service->confirm($token));
        self::assertSame($confirmed, $service->request(0));
        self::assertSame($audits, $s->sql->rows('sis_audit'));
        $service->requireForApplication($f->owner, $f->person);
        self::assertSame($before, array_map($s->sql->rows(...), [
            'users', 'sis_persons', 'sis_history_events', 'sis_applications',
        ]));
        self::assertNull($f->records->get($f->owner)['sealed_notice']);
        self::assertSame([], $f->sent);
    }

    public function testRequiredGateIsOptionalAndAnUnverifiedOwnerIsRejected(): void
    {
        $f = new EmailVerificationFixture();
        $f->required = false;
        $f->service()->requireForApplication($f->owner, $f->person);
        $f->required = true;
        $this->expectException(ValidationException::class);
        $f->service()->requireForApplication($f->owner, $f->person);
    }

    public function testExplicitReissueInvalidatesEarlierTokensAndStaleGenerations(): void
    {
        $f = new EmailVerificationFixture();
        $service = $f->service();
        $service->request(0);
        $first = $f->token();
        $f->sis->clock->time = $f->sis->clock->time->modify('+1 minute');
        $service->request(1);
        $latest = $f->token();
        try {
            $service->confirm($first);
            self::fail('An older nonce was accepted.');
        } catch (ValidationException) {
            self::assertFalse($service->status()['verified']);
        }
        try {
            $service->request(0);
            self::fail('An older request rewound the current challenge.');
        } catch (ConcurrentModificationException) {
            self::assertSame(2, $service->status()['version']);
        }
        self::assertTrue($service->confirm($latest)['verified']);
    }

    public function testWorkerSendsOnlyOnceAndClearsNoticeWhileLeavingConfirmationAvailable(): void
    {
        $f = new EmailVerificationFixture();
        $f->service()->request(0);
        $token = $f->token();
        self::assertSame(['examined' => 1, 'sent' => 1, 'uncertain' => 0, 'expired' => 0], $f->service()->dispatch());
        self::assertCount(1, $f->sent);
        self::assertNull($f->records->get($f->owner)['sealed_notice']);
        self::assertSame(0, $f->service()->dispatch()['examined']);
        self::assertTrue($f->service()->confirm($token)['verified']);
    }

    public function testExpiredCleanupClearsNonceMaterialAndPreservesAConfirmedCurrentProof(): void
    {
        $f = new EmailVerificationFixture();
        $f->service()->request(0);
        $f->service()->confirm($f->token());
        $before = $f->records->get($f->owner)['sealed_verification'];
        $f->sis->clock->time = $f->sis->clock->time->modify('+30 minutes');
        self::assertSame(1, $f->retention()->run());
        self::assertSame(0, $f->retention()->run());
        $row = $f->records->get($f->owner);
        self::assertNull($row['token_hash']);
        self::assertNull($row['sealed_challenge']);
        self::assertSame($before, $row['sealed_verification']);
        self::assertTrue($f->service()->status()['verified']);
    }
    public function testChangingEmailAndChangingItBackRequiresNewProofButPasswordChangesDoNot(): void
    {
        $f = new EmailVerificationFixture();
        $f->service()->request(0);
        $f->service()->confirm($f->token());
        $f->changeUser(static fn ($user) => $user->changePassword(
            new \Qubus\ValueObjects\StringLiteral\StringLiteral('fictional changed password hash'),
            new \Domain\User\ValueObject\UserToken()
        ));
        self::assertTrue($f->service()->status()['verified']);
        $f->changeEmail('changed@example.invalid');
        self::assertFalse($f->service()->status()['verified']);
        $f->changeEmail('proof@example.invalid');
        self::assertFalse($f->service()->status()['verified']);
    }

    public function testAnotherAccountCannotConsumeTheOwnersLinkOrChooseItsRecipient(): void
    {
        $f = new EmailVerificationFixture();
        $s = $f->sis;
        $f->service()->request(0);
        $token = $f->token();
        $s->access->staff = true;
        $other = $s->registrationService()->staff(EntityId::generate(), 'Fictional', 'Other', 'other@example.invalid');
        $s->access->actor = $other->ownerUserId;
        try {
            $f->service()->confirm($token);
            self::fail('A different account consumed the link.');
        } catch (ValidationException) {
            self::assertNull($f->records->get($other->ownerUserId));
        }
        $s->access->actor = $f->owner;
        $s->access->staff = false;
        self::assertFalse($f->service()->status()['verified']);
        self::assertTrue($f->service()->confirm($token)['verified']);
    }

    public function testUncertainAndInterruptedDeliveriesAreNeverAutomaticallyRetried(): void
    {
        $f = new EmailVerificationFixture();
        $f->service()->request(0);
        $token = $f->token();
        $f->uncertain = true;
        self::assertSame(1, $f->service()->dispatch()['uncertain']);
        self::assertSame(0, $f->service()->dispatch()['examined']);
        self::assertCount(1, $f->sent);
        self::assertTrue($f->service()->confirm($token)['verified']);
        $g = new EmailVerificationFixture();
        $g->service()->request(0);
        $row = $g->records->get($g->owner);
        $g->records->claim($g->owner, $row['version'], $row['token_hash']);
        self::assertSame(0, $g->service()->dispatch()['examined']);
        $g->sis->clock->time = $g->sis->clock->time->modify('+30 minutes');
        self::assertSame(1, $g->retention()->run());
        self::assertNull($g->records->get($g->owner)['sealed_notice']);
        self::assertSame([], $g->sent);
    }

    public function testSourceChangesBeforeWorkerDeliveryClearTheNoticeWithoutSendingIt(): void
    {
        $f = new EmailVerificationFixture();
        $f->service()->request(0);
        $f->changeEmail('changed@example.invalid');
        self::assertSame(1, $f->service()->dispatch()['expired']);
        self::assertSame([], $f->sent);
        self::assertNull($f->records->get($f->owner)['sealed_notice']);
    }

    public function testWorkerReceiptCannotClearANewerExplicitRequest(): void
    {
        $f = new EmailVerificationFixture();
        $f->service()->request(0);
        $f->duringSend = function () use ($f): void {
            $f->sis->clock->time = $f->sis->clock->time->modify('+1 minute');
            $f->service()->request(1);
        };
        self::assertSame(1, $f->service()->dispatch()['sent']);
        $row = $f->records->get($f->owner);
        self::assertSame(2, $row['version']);
        self::assertSame('queued', $row['state']);
        self::assertNotNull($row['sealed_notice']);
        $f->duringSend = null;
        self::assertSame(1, $f->service()->dispatch()['sent']);
    }

    public function testDisabledDeliveryAndExpiryBoundariesNeverCreateVerification(): void
    {
        $f = new EmailVerificationFixture();
        $f->enabled = false;
        try {
            $f->service()->request(0);
            self::fail('Delivery-disabled request accepted.');
        } catch (ValidationException) {
            self::assertNull($f->records->get($f->owner));
        }
        $f->enabled = true;
        $f->service()->request(0);
        $token = $f->token();
        try {
            $f->service()->request(1);
            self::fail('Cooldown bypassed.');
        } catch (ValidationException) {
            self::assertSame(1, $f->records->get($f->owner)['version']);
        }
        $f->sis->clock->time = $f->sis->clock->time->modify('+30 minutes');
        self::assertSame(0, $f->service()->dispatch()['examined']);
        $this->expectException(ValidationException::class);
        $f->service()->confirm($token);
    }

    public function testTamperedProofStateAndOversizedCiphertextNeverSatisfyTheGate(): void
    {
        $f = new EmailVerificationFixture();
        $f->service()->request(0);
        $f->sis->sql->update('sis_email_verifications', [
            'state' => 'verified', 'sealed_verification' => str_repeat('a', 20000),
        ], ['id' => $f->owner->value]);
        self::assertFalse($f->service()->status()['verified']);
        self::assertNotSame('verified', $f->service()->status()['state']);
        $this->expectException(ValidationException::class);
        $f->service()->requireForApplication($f->owner, $f->person);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('drifts')]
    public function testFinalIdentityTimeStorageAndAuditChecksRollBackTheConfirmation(string $drift): void
    {
        $f = new EmailVerificationFixture();
        $f->service()->request(0);
        $token = $f->token();
        $s = $f->sis;
        $tables = ['sis_email_verifications', 'users', 'sis_persons', 'sis_audit', 'sis_history_events'];
        $before = array_map($s->sql->rows(...), $tables);
        $audit = $f->audit(function () use ($f, $s, $drift): void {
            match ($drift) {
                'email' => $f->changeEmail('changed@example.invalid'),
                'person' => $s->sql->update(
                    'sis_persons',
                    ['owner_user_id' => $s->user()->value], ['id' => $f->person->value]
                ),
                'storage' => $s->sql->update(
                    'sis_email_verifications',
                    ['state' => 'expired'], ['id' => $f->owner->value]
                ),
                'expiry' => $s->clock->time = $s->clock->time->modify('+30 minutes'),
                'audit' => throw new \RuntimeException('Fictional audit failure.'),
            };
        });
        try {
            $f->service($audit)->confirm($token);
            self::fail('A changed source was confirmed.');
        } catch (\Throwable $error) {
            self::assertTrue($error instanceof ConcurrentModificationException
            || $error instanceof \Domain\Shared\Exception\AccessDeniedException || $error instanceof \RuntimeException);
        }
        self::assertSame($before, array_map($s->sql->rows(...), $tables));
    }

    public static function drifts(): array
    {
        return [['email'], ['person'], ['storage'], ['expiry'], ['audit']];
    }

    public function testOptionalPolicyBlocksNewOwnerSubmissionsAndRechecksAfterAuditButPreservesAcceptedRetries(): void
    {
        $f = new EmailVerificationFixture();
        $s = $f->sis;
        $form = $f->form();
        $submission = EntityId::generate();
        $service = $s->submissionService(verification: $f->service());
        $submit = fn () => $service->submit(
            $submission,
            $form,
            $f->person,
            $s->term,
            ['statement' => 'Fictional application.']
        );
        $history = \EventStoreFixture::historyDigest($s->sql);
        try {
            $submit();
            self::fail('Unverified owner submitted an application.');
        } catch (ValidationException) {
            self::assertSame($history, \EventStoreFixture::historyDigest($s->sql));
        }
        $f->service()->request(0);
        $f->service()->confirm($f->token());
        $accepted = $submit();
        $f->changeEmail('changed@example.invalid');
        $after = \EventStoreFixture::historyDigest($s->sql);
        self::assertSame($accepted->value, $submit()->value);
        self::assertSame($after, \EventStoreFixture::historyDigest($s->sql));
        $f->required = false;
        $audit = $f->audit(function (string $action) use ($f): void {
            if ($action === 'admissions.form_submitted') {
                $f->required = true;
            }
        });
        $before = array_map($s->sql->rows(...), ['sis_applications', 'sis_form_submissions', 'sis_history_events']);
        try {
            $s->submissionService($audit, verification: $f->service())->submit(
                EntityId::generate(),
                $form,
                $f->person,
                $s->term,
                ['statement' => 'Another fictional application.']
            );
            self::fail('Final policy/source check was omitted.');
        } catch (ValidationException) {
            self::assertSame($before, array_map($s->sql->rows(...), [
                'sis_applications', 'sis_form_submissions', 'sis_history_events',
            ]));
        }
    }
    public function testThirtyMinuteExpiryUsesElapsedUtcTimeAcrossTheAutumnClockChange(): void
    {
        $f = new EmailVerificationFixture();
        $f->sis->clock->time = new \DateTimeImmutable('2026-11-01T01:45:00-07:00');
        $f->service()->request(0);
        $token = $f->token();
        self::assertSame('2026-11-01T09:15:00Z', $f->records->get($f->owner)['expires_at']);
        $f->sis->clock->time = new \DateTimeImmutable('2026-11-01T09:15:00Z');
        $this->expectException(ValidationException::class);
        $f->service()->confirm($token);
    }

    public function testAnotherSignedInAccountCannotUseThePrivateTokenEvenWithOfficePrivileges(): void
    {
        $f = new EmailVerificationFixture();
        $f->service()->request(0);
        $token = $f->token();
        $f->sis->access->staff = true;
        $other = $f->sis->registrationService()->staff(
            EntityId::generate(),
            'Fictional',
            'Other',
            'other@example.invalid'
        );
        $f->sis->access->actor = $other->ownerUserId;
        try {
            $f->service()->confirm($token);
            self::fail('A token was adopted by another account.');
        } catch (ValidationException) {
            self::assertFalse($f->service()->status()['verified']);
        }
        $f->sis->access->actor = $f->owner;
        self::assertTrue($f->service()->confirm($token)['verified']);
    }

    public function testResubmissionUsesTheSameOptionalGateAndKeepsItsAcceptedRetry(): void
    {
        $f = new EmailVerificationFixture();
        $s = $f->sis;
        $form = $f->form();
        $f->required = false;
        $app = $s->submissionService(verification: $f->service())->submit(
            EntityId::generate(),
            $form,
            $f->person,
            $s->term,
            ['statement' => 'Fictional initial statement.']
        );
        $revisions = $s->applicationRevisionService(verification: $f->service());
        $s->access->staff = true;
        $request = $revisions->review($app, 'request', ['instructions' => 'Fictional clarification.'], false);
        $revisions->commit($app, $request['review_token'], false);
        $s->access->staff = false;
        $f->required = true;
        $review = $revisions->review($app, 'submit', ['answers' => ['statement' => 'Fictional revised statement.'],
            'reason' => 'Fictional clarification.'], true);
        try {
            $revisions->commit($app, $review['review_token'], true);
            self::fail('Unverified resubmission accepted.');
        } catch (ValidationException) {
            self::assertSame('submitted', $s->admissions->application($app)->status->value);
        }
        $f->service()->request(0);
        $f->service()->confirm($f->token());
        $receipt = $revisions->commit($app, $review['review_token'], true);
        $f->changeEmail('changed@example.invalid');
        self::assertSame($receipt, $revisions->commit($app, $review['review_token'], true));
    }

    public function testRequestAuditFailureAndFinalDeliveryOptOutLeaveNoQueuedCredential(): void
    {
        foreach (['audit', 'disabled', 'expiry'] as $mode) {
            $f = new EmailVerificationFixture();
            $before = array_map($f->sis->sql->rows(...), [
                'sis_email_verifications', 'sis_audit', 'sis_history_events',
            ]);
            $audit = $f->audit(function () use ($f, $mode): void {
                match ($mode) {
                    'audit' => throw new \RuntimeException('Fictional audit failure.'),
                    'disabled' => $f->enabled = false,
                    'expiry' => $f->sis->clock->time = $f->sis->clock->time->modify('+31 minutes'),
                };
            });
            try {
                $f->service($audit)->request(0);
                self::fail('A failed final check queued a notice.');
            } catch (\RuntimeException | ConcurrentModificationException) {
                self::assertSame($before, array_map($f->sis->sql->rows(...), [
                    'sis_email_verifications', 'sis_audit', 'sis_history_events',
                ]));
            }
        }
    }
    public function testDisagreementWithLoginRoutingFailsClosedWithoutIssuingAChallenge(): void
    {
        $f = new EmailVerificationFixture();
        $f->sis->sql->update('users', ['email' => 'unrecorded@example.invalid'], ['user_id' => $f->owner->value]);
        try {
            $f->service()->request(0);
            self::fail('Unrecorded routing drift was accepted.');
        } catch (\Domain\Shared\Exception\AccessDeniedException) {
            self::assertNull($f->records->get($f->owner));
            self::assertSame([], $f->sent);
        }
    }

    public function testDisabledAccountsCannotConsumeAChallengeAndUncertainSendsAreNeverRetriedAutomatically(): void
    {
        $f = new EmailVerificationFixture();
        $f->service()->request(0);
        $token = $f->token();
        $f->uncertain = true;
        self::assertSame(1, $f->service()->dispatch()['uncertain']);
        self::assertSame(0, $f->service()->dispatch()['examined']);
        self::assertCount(1, $f->sent);
        self::assertNull($f->records->get($f->owner)['sealed_notice']);
        $f->changeUser(static fn (\Domain\User\User $user) => $user->changeSignIn(
            false,
            EntityId::generate(),
            hash('sha256', 'Fictional account suspension.')
        ));
        $before = $f->records->get($f->owner);
        $this->expectException(\Domain\Shared\Exception\AccessDeniedException::class);
        try {
            $f->service()->confirm($token);
        } finally {
            self::assertSame($before, $f->records->get($f->owner));
        }
    }
}
