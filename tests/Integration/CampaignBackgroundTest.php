<?php

declare(strict_types=1);

namespace Tests\Integration;

use Closure;
use Domain\Access\Enum\GrantKind;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Enum\DeliveryReviewDecision;
use Domain\Communications\Service\CampaignBackgroundService;
use Domain\Communications\Service\CampaignService;
use Domain\Communications\Service\MailTransport;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use Infrastructure\Communications\AuthorizedCampaignExecutor;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlCampaignDispatchRepository;
use Infrastructure\Persistence\Repository\SqlCampaignRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SisFixture;

final class CampaignBackgroundTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function setupCampaign(int $people = 3, ?AuditTrail $audit = null): array
    {
        $f = new SisFixture();
        $mail = new class implements MailTransport {
            public int $calls = 0;
            public bool $fail = false;
            public bool $enabled = true;
            public ?Closure $ready = null;
            public ?Closure $afterSend = null;

            public function assertReady(): void
            {
                if ($this->ready !== null) {
                    ($this->ready)();
                }
                if (!$this->enabled) {
                    throw new DomainException('Fictional transport disabled.');
                }
            }

            public function send(
                EntityId $delivery,
                #[\SensitiveParameter] string $recipient,
                #[\SensitiveParameter] string $subject,
                #[\SensitiveParameter] string $body
            ): void {
                ++$this->calls;
                if ($this->fail) {
                    throw new RuntimeException('fictional-provider-secret');
                }
                if ($this->afterSend !== null) {
                    ($this->afterSend)();
                }
            }
        };
        $messages = new SqlCampaignRepository($f->sql);
        $jobs = new SqlCampaignDispatchRepository($f->sql);
        $permissions = new SqlAccessRepository($f->sql);
        $permissions->assign(GrantKind::UserRole, $f->access->actor, $permissions->roleNamed('super')->id, true);
        $campaigns = new CampaignService($messages, $f->dropCipher, $mail, $f->access, $f->transactions, $f->audit);
        $executor = new AuthorizedCampaignExecutor(
            $messages,
            $jobs,
            $permissions,
            $f->dropCipher,
            $mail,
            $f->transactions,
            $f->audit,
            $f->clock
        );
        $background = new CampaignBackgroundService(
            $campaigns,
            $messages,
            $jobs,
            $executor,
            $f->dropCipher,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $f->clock
        );
        $recipients = [];
        for ($i = 0; $i < $people; ++$i) {
            $user = $f->user();
            $f->sql->update('users', ['email' => 'fictional' . $i . '@example.invalid'], ['user_id' => $user->value]);
            $f->legacyPerson($user, 'Fictional', 'Recipient ' . $i);
            $recipients[] = $user;
        }
        $id = $campaigns->create('Fictional notice', 'Private fictional wording.')->id;
        $deliveries = $campaigns->queue($id, $recipients, 0);
        return [$f, $campaigns, $background, $mail, $id, $deliveries, $permissions];
    }

    private function uncertainCampaign(int $people = 1, ?AuditTrail $audit = null): array
    {
        $context = $this->setupCampaign($people, $audit);
        [, , $background, $mail, $id] = $context;
        $background->confirm($id, $background->review($id)['review_token']);
        $mail->fail = true;
        self::assertSame(1, $background->run()['held']);
        $mail->fail = false;
        return $context;
    }

    public function testEvidenceBasedConfirmationRetainsTransportFactsAndEncryptedDecisionWithoutResending(): void
    {
        [$f, , $background, $mail, $id, $deliveries] = $this->uncertainCampaign();
        $delivery = $deliveries[0];
        $before = $f->sql->one('sis_deliveries', ['id' => $delivery->value]);
        $events = $f->sql->rows('sis_history_events');
        $review = $background->reviewDeliveryDecision(
            $id,
            $delivery,
            DeliveryReviewDecision::ConfirmSent,
            'Fictional evidence was reviewed.',
            'INTERNAL-CASE-ALPHA'
        );
        self::assertNull($f->sql->one('sis_deliveries', ['id' => $delivery->value])['resolution_id']);
        $result = $background->confirmDeliveryDecision($id, $delivery, $review['review_token']);
        self::assertSame('confirmed_sent', $result['decision']['decision']);
        self::assertSame('INTERNAL-CASE-ALPHA', $result['decision']['evidence']);
        self::assertSame('needs_review', $result['transport_status']);
        $row = $f->sql->one('sis_deliveries', ['id' => $delivery->value]);
        self::assertSame($before['version'], $row['version']);
        self::assertSame($before['status'], $row['status']);
        self::assertStringNotContainsString('INTERNAL-CASE-ALPHA', $row['encrypted_resolution']);
        self::assertStringNotContainsString('Fictional evidence', $row['encrypted_resolution']);
        self::assertSame(1, $background->status($id)['counts']['sent']);
        self::assertSame('completed', $background->status($id)['status']);
        $f->clock->time = $f->clock->time->modify('+1 day');
        self::assertSame($result, $background->confirmDeliveryDecision($id, $delivery, $review['review_token']));
        self::assertCount(1, $f->sql->rows('sis_audit', ['action' => 'campaign.delivery_confirmed_sent']));
        self::assertStringNotContainsString(
            'INTERNAL-CASE-ALPHA',
            json_encode($f->sql->rows('sis_audit'), JSON_THROW_ON_ERROR)
        );
        self::assertArrayNotHasKey('encrypted_resolution', new SqlCampaignRepository($f->sql)->delivery($delivery));
        self::assertSame(0, $background->run()['sent']);
        self::assertSame(1, $mail->calls);
        self::assertSame($events, $f->sql->rows('sis_history_events'));
    }

    public function testClosingAnUncertainDeliveryAllowsOnlyRemainingQueuedRecipientsToBeReviewedAndSent(): void
    {
        [$f, , $background, $mail, $id] = $this->uncertainCampaign(3);
        $uncertain = $f->sql->one('sis_deliveries', ['campaign_id' => $id->value, 'status' => 'needs_review']);
        $delivery = new EntityId($uncertain['id']);
        $review = $background->reviewDeliveryDecision($id, $delivery, DeliveryReviewDecision::Close, 'Fictional.');
        $background->confirmDeliveryDecision($id, $delivery, $review['review_token']);
        self::assertSame(1, $background->status($id)['counts']['closed']);
        self::assertSame(0, $background->status($id)['counts']['sent']);
        self::assertSame(0, $background->status($id)['counts']['needs_review']);
        $remaining = $background->review($id);
        self::assertCount(2, $remaining['recipients']);
        $background->confirm($id, $remaining['review_token']);
        self::assertSame(2, $background->run()['sent']);
        self::assertSame(3, $mail->calls);
        self::assertSame('completed', $background->status($id)['status']);
        self::assertSame(2, $background->status($id)['counts']['sent']);
        self::assertSame(1, $background->status($id)['counts']['closed']);
        self::assertSame('needs_review', $f->sql->one('sis_deliveries', ['id' => $delivery->value])['status']);
    }

    public function testClosingAnInFlightReviewNeverPreventsTheActualSendReceiptFromBeingRecorded(): void
    {
        [$f, , $background, $mail, $id, $deliveries] = $this->setupCampaign(1);
        $delivery = $deliveries[0];
        $job = $background->confirm($id, $background->review($id)['review_token']);
        $mail->afterSend = static function () use ($background, $id, $delivery, $job): void {
            $background->cancel($id, $job['version']);
            $review = $background->reviewDeliveryDecision($id, $delivery, DeliveryReviewDecision::Close, 'Fictional.');
            $background->confirmDeliveryDecision($id, $delivery, $review['review_token']);
        };
        self::assertSame(1, $background->run()['sent']);
        $row = $f->sql->one('sis_deliveries', ['id' => $delivery->value]);
        self::assertSame('sent', $row['status']);
        self::assertSame('closed', $row['disposition']);
        self::assertSame(2, $row['version']);
        self::assertSame('sending', $background->deliveryReviewState($id, $delivery)['decision']['transport_status']);
        self::assertSame(1, $background->status($id)['counts']['sent']);
        self::assertSame(0, $background->status($id)['counts']['closed']);
        self::assertCount(1, $f->sql->rows('sis_audit', ['action' => 'message.sent']));
        self::assertSame(1, $mail->calls);
    }

    public function testADeliveryReceiptArrivingBeforeConfirmationMakesAnOlderDecisionStale(): void
    {
        [$f, , $background, $mail, $id, $deliveries] = $this->setupCampaign(1);
        $delivery = $deliveries[0];
        $job = $background->confirm($id, $background->review($id)['review_token']);
        $token = null;
        $mail->afterSend = static function () use ($background, $id, $delivery, $job, &$token): void {
            $background->cancel($id, $job['version']);
            $token = $background->reviewDeliveryDecision(
                $id,
                $delivery,
                DeliveryReviewDecision::Close,
                'Fictional review pending.'
            )['review_token'];
        };
        $background->run();
        try {
            $background->confirmDeliveryDecision($id, $delivery, $token);
            self::fail('A stale review replaced a real send receipt.');
        } catch (ConcurrentModificationException) {
            $row = $f->sql->one('sis_deliveries', ['id' => $delivery->value]);
            self::assertSame('sent', $row['status']);
            self::assertNull($row['resolution_id']);
        }
    }

    public function testActiveAndNewerAuthorizationsPreventDeliveryDecisions(): void
    {
        [$f, , $background, $mail, $id] = $this->uncertainCampaign(2);
        $row = $f->sql->one('sis_deliveries', ['campaign_id' => $id->value, 'status' => 'needs_review']);
        $delivery = new EntityId($row['id']);
        $old = $background->reviewDeliveryDecision($id, $delivery, DeliveryReviewDecision::Close, 'Fictional reason.');
        $background->confirm($id, $background->review($id)['review_token']);
        self::assertTrue($background->deliveryReviewState($id, $delivery)['worker_active']);
        try {
            $background->reviewDeliveryDecision($id, $delivery, DeliveryReviewDecision::Close, 'Fictional reason.');
            self::fail('An active authorization allowed a new decision review.');
        } catch (DomainException) {
            self::assertSame(1, $mail->calls);
        }
        $this->expectException(ConcurrentModificationException::class);
        $background->confirmDeliveryDecision($id, $delivery, $old['review_token']);
    }

    public function testOwnerPermissionDoesNotGrantDeliveryAdministrationAndTokensAreActorAndDeliveryBound(): void
    {
        [$f, $campaigns, $background, , $id, $deliveries] = $this->uncertainCampaign();
        $delivery = $deliveries[0];
        $review = $background->reviewDeliveryDecision($id, $delivery, DeliveryReviewDecision::Close, 'Fictional.');
        $token = $review['review_token'];
        $f->access->staff = false;
        $f->access->grants = ['sis:communications:manage', 'sis:person:manage'];
        foreach (['read', 'review', 'confirm'] as $operation) {
            try {
                match ($operation) {
                    'read' => $background->deliveryReviewState($id, $delivery),
                    'review' => $background->reviewDeliveryDecision(
                        $id,
                        $delivery,
                        DeliveryReviewDecision::Close,
                        'Fictional reason.'
                    ),
                    'confirm' => $background->confirmDeliveryDecision($id, $delivery, $token),
                };
                self::fail('Ownership granted delivery administration.');
            } catch (AccessDeniedException) {
                self::assertNull($f->sql->one('sis_deliveries', ['id' => $delivery->value])['resolution_id']);
            }
        }
        $f->access->staff = true;
        $f->access->actor = $f->user();
        try {
            $background->confirmDeliveryDecision($id, $delivery, $token);
            self::fail('Another actor used a delivery review.');
        } catch (AccessDeniedException) {
            $other = $campaigns->create('Other fictional composition', 'Fictional message.')->id;
        }
        $this->expectException(AccessDeniedException::class);
        $background->confirmDeliveryDecision($other, $delivery, $token);
    }

    public function testFailedAuditOrRemovedAdministrationRollsBackDecisionAndCampaignCompletion(): void
    {
        foreach (['audit', 'permission'] as $failure) {
            $f = null;
            $audit = $this->createStub(AuditTrail::class);
            $callback = function ($actor, $action, $subject) use (&$f, $failure): void {
                $f->audit->record($actor, $action, $subject);
                if (!str_starts_with($action, 'campaign.delivery_')) {
                    return;
                }
                if ($failure === 'audit') {
                    throw new RuntimeException('Fictional audit failure.');
                }
                $f->access->staff = false;
                $f->access->grants = ['sis:communications:manage', 'sis:person:manage'];
            };
            $audit->method('record')->willReturnCallback($callback);
            [$f, , $background, $mail, $id, $deliveries] = $this->setupCampaign(1, $audit);
            $background->confirm($id, $background->review($id)['review_token']);
            $mail->fail = true;
            $background->run();
            $mail->fail = false;
            $review = $background->reviewDeliveryDecision(
                $id,
                $deliveries[0],
                DeliveryReviewDecision::Close,
                'Fictional.'
            );
            $token = $review['review_token'];
            $before = $f->sql->rows('sis_deliveries');
            $campaign = $f->sql->one('sis_campaigns', ['id' => $id->value]);
            $audits = $f->sql->rows('sis_audit');
            try {
                $background->confirmDeliveryDecision($id, $deliveries[0], $token);
                self::fail('A decision survived lost authority or auditing.');
            } catch (RuntimeException) {
                self::assertSame($before, $f->sql->rows('sis_deliveries'));
                self::assertSame($campaign, $f->sql->one('sis_campaigns', ['id' => $id->value]));
                self::assertSame($audits, $f->sql->rows('sis_audit'));
            }
        }
    }

    public function testEvidenceIsRequiredAndAnInFlightSendCannotBeManuallyConfirmed(): void
    {
        [$f, , $background, , $id, $deliveries] = $this->uncertainCampaign();
        $delivery = $deliveries[0];
        try {
            $background->reviewDeliveryDecision($id, $delivery, DeliveryReviewDecision::ConfirmSent, 'Fictional.');
            self::fail('Staff confirmed sending without an evidence reference.');
        } catch (\Domain\Shared\Exception\ValidationException $error) {
            self::assertSame('evidence', $error->errors[0]['field']);
        }
        $f->sql->update('sis_deliveries', ['status' => 'sending'], ['id' => $delivery->value]);
        $this->expectException(DomainException::class);
        $background->reviewDeliveryDecision(
            $id,
            $delivery,
            DeliveryReviewDecision::ConfirmSent,
            'Fictional.',
            'CASE-1'
        );
    }

    public function testAnExpiredReviewAndACompetingDecisionCannotReplaceRetainedDecisions(): void
    {
        [$f, , $background, , $id, $deliveries] = $this->uncertainCampaign();
        $delivery = $deliveries[0];
        $old = $background->reviewDeliveryDecision($id, $delivery, DeliveryReviewDecision::Close, 'Fictional.');
        $f->clock->time = $f->clock->time->modify('+21 minutes');
        try {
            $background->confirmDeliveryDecision($id, $delivery, $old['review_token']);
            self::fail('An expired delivery review was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertNull($f->sql->one('sis_deliveries', ['id' => $delivery->value])['resolution_id']);
        }
        $one = $background->reviewDeliveryDecision($id, $delivery, DeliveryReviewDecision::Close, 'Fictional close.');
        $two = $background->reviewDeliveryDecision(
            $id,
            $delivery,
            DeliveryReviewDecision::ConfirmSent,
            'Fictional confirmed.',
            'CASE-2'
        );
        $background->confirmDeliveryDecision($id, $delivery, $one['review_token']);
        $this->expectException(ConcurrentModificationException::class);
        $background->confirmDeliveryDecision($id, $delivery, $two['review_token']);
    }

    public function testConfirmationReturnsWithoutSendingAndWorkerContinuesWithoutBrowserIdentity(): void
    {
        [$f, , $background, $mail, $id] = $this->setupCampaign();
        $events = $f->sql->rows('sis_history_events');
        self::assertSame(0, $background->run()['examined']);
        $review = $background->review($id);
        self::assertCount(3, $review['recipients']);
        self::assertStringNotContainsString('@', json_encode($review['recipients'], JSON_THROW_ON_ERROR));
        self::assertSame([], $f->sql->rows('sis_campaign_dispatches'));
        self::assertSame('active', $background->confirm($id, $review['review_token'])['status']);
        self::assertSame(0, $mail->calls);
        $job = $f->sql->rows('sis_campaign_dispatches')[0];
        self::assertStringNotContainsString('fictional', json_encode($job, JSON_THROW_ON_ERROR));
        $f->access->actor = $f->user();
        $f->access->staff = false;
        self::assertSame(2, $background->run(2)['sent']);
        self::assertSame('active', $f->sql->rows('sis_campaign_dispatches')[0]['status']);
        self::assertSame(1, $background->run(2)['sent']);
        self::assertSame(3, $mail->calls);
        self::assertSame('completed', $f->sql->rows('sis_campaign_dispatches')[0]['status']);
        self::assertSame(0, $background->run()['examined']);
        self::assertSame($events, $f->sql->rows('sis_history_events'));
    }

    public function testProgressReflectsPartialSendsAndRepeatedConfirmationCannotRestartCancelledWork(): void
    {
        [, , $background, $mail, $id] = $this->setupCampaign();
        $review = $background->review($id);
        $background->confirm($id, $review['review_token']);
        $background->run(1);
        $progress = $background->status($id);
        self::assertSame(3, $progress['total']);
        self::assertSame(
            ['queued' => 2, 'sending' => 0, 'sent' => 1, 'needs_review' => 0, 'closed' => 0],
            $progress['counts']
        );
        $background->cancel($id, $progress['version']);
        self::assertSame('cancelled', $background->confirm($id, $review['review_token'])['status']);
        self::assertSame(0, $background->run()['examined']);
        self::assertSame(1, $mail->calls);
        $replacement = $background->review($id);
        self::assertCount(2, $replacement['recipients']);
        $background->confirm($id, $replacement['review_token']);
        try {
            $background->confirm($id, $review['review_token']);
            self::fail('An older confirmation replaced a newer authorization.');
        } catch (ConcurrentModificationException) {
            self::assertSame('active', $background->status($id)['status']);
        }
        self::assertSame(2, $background->run()['sent']);
        self::assertSame(3, $mail->calls);
    }

    public function testPauseSkipsTheJobAndResumePreservesTheReviewedMessage(): void
    {
        [, $campaigns, $background, $mail, $id] = $this->setupCampaign();
        $background->confirm($id, $background->review($id)['review_token']);
        $campaigns->pause($id, true);
        self::assertSame(0, $background->run()['examined']);
        self::assertSame('paused', $background->status($id)['campaign_status']);
        $campaigns->pause($id, false);
        self::assertSame(3, $background->run()['sent']);
        self::assertSame(3, $mail->calls);
    }

    public function testUnknownTransportOutcomeIsHeldWithoutAutomaticRetryOrProviderLeak(): void
    {
        [$f, , $background, $mail, $id] = $this->setupCampaign();
        $background->confirm($id, $background->review($id)['review_token']);
        $mail->fail = true;
        self::assertSame(1, $background->run()['held']);
        $progress = $background->status($id);
        self::assertSame('held', $progress['status']);
        self::assertSame(1, $progress['counts']['needs_review']);
        self::assertSame(2, $progress['counts']['queued']);
        self::assertSame(0, $background->run()['examined']);
        self::assertSame(1, $mail->calls);
        $mail->fail = false;
        $background->confirm($id, $background->review($id)['review_token']);
        self::assertSame(2, $background->run()['sent']);
        self::assertSame(3, $mail->calls);
        self::assertSame(1, $background->status($id)['counts']['needs_review']);
        self::assertStringNotContainsString(
            'provider-secret',
            json_encode($f->sql->rows('sis_audit'), JSON_THROW_ON_ERROR)
        );
    }

    public function testDisabledAccountAndRevokedAuthorityStopBeforeClaiming(): void
    {
        foreach (['actor', 'recipient', 'permission', 'transport'] as $reason) {
            [$f, , $background, $mail, $id, $deliveries, $permissions] = $this->setupCampaign(1);
            $background->confirm($id, $background->review($id)['review_token']);
            if ($reason === 'actor' || $reason === 'recipient') {
                $target = $reason === 'actor' ? $f->access->actor->value
                : $f->sql->one('sis_deliveries', ['id' => $deliveries[0]->value])['recipient_user_id'];
                $f->sql->update('users', ['sign_in_enabled' => 0], ['user_id' => $target]);
            } elseif ($reason === 'permission') {
                $permissions->assign(
                    GrantKind::UserRole,
                    $f->access->actor,
                    $permissions->roleNamed('super')->id,
                    false
                );
            } else {
                $mail->enabled = false;
            }
            self::assertSame(1, $background->run()['blocked'], $reason);
            self::assertSame(0, $mail->calls);
            self::assertSame('queued', $f->sql->one('sis_deliveries', ['id' => $deliveries[0]->value])['status']);
        }
    }

    public function testCancellationBetweenReadinessAndClaimPreventsSending(): void
    {
        [, , $background, $mail, $id] = $this->setupCampaign(1);
        $state = $background->confirm($id, $background->review($id)['review_token']);
        $mail->ready = static function () use ($background, $id, $state): void {
            $background->cancel($id, $state['version']);
        };
        self::assertSame(0, $background->run()['sent']);
        self::assertSame('cancelled', $background->status($id)['status']);
        self::assertSame(1, $background->status($id)['counts']['queued']);
        self::assertSame(0, $mail->calls);
    }

    public function testReceiptSurvivesPermissionLossDuringAnAlreadyAttemptedSend(): void
    {
        [$f, , $background, $mail, $id] = $this->setupCampaign(1);
        $background->confirm($id, $background->review($id)['review_token']);
        $mail->afterSend = static function () use ($f): void {
            $f->sql->update('users', ['sign_in_enabled' => 0], ['user_id' => $f->access->actor->value]);
        };
        self::assertSame(1, $background->run()['sent']);
        self::assertSame('completed', $background->status($id)['status']);
        self::assertSame(1, $mail->calls);
    }

    public function testExpiredForeignStaleAndTamperedReviewsCannotAuthorizeSending(): void
    {
        [$f, $campaigns, $background, $mail, $id] = $this->setupCampaign(1);
        $token = $background->review($id)['review_token'];
        $f->clock->time = $f->clock->time->modify('+21 minutes');
        try {
            $background->confirm($id, $token);
            self::fail('Expired review accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame('unconfirmed', $background->status($id)['status']);
        }
        $fresh = $background->review($id)['review_token'];
        $campaigns->pause($id, true);
        $campaigns->pause($id, false);
        try {
            $background->confirm($id, $fresh);
            self::fail('Stale parent revision accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame(0, $mail->calls);
        }
        $f->access->actor = $f->user();
        try {
            $background->confirm($id, $fresh);
            self::fail('Another actor accepted the review.');
        } catch (AccessDeniedException) {
            self::assertSame([], $f->sql->rows('sis_campaign_dispatches'));
        }
        $this->expectException(DomainException::class);
        $background->confirm($id, 'tampered-review');
    }

    public function testAnInterruptedClaimIsHeldAndNeverSentAgain(): void
    {
        [$f, , $background, $mail, $id, $deliveries] = $this->setupCampaign(1);
        $background->confirm($id, $background->review($id)['review_token']);
        $f->sql->update('sis_deliveries', ['status' => 'sending', 'version' => 1], ['id' => $deliveries[0]->value]);
        self::assertSame(1, $background->run()['held']);
        self::assertSame(0, $background->run()['examined']);
        self::assertSame(0, $mail->calls);
    }

    public function testFullAudienceIsReviewedButWorkerAttemptsStayBounded(): void
    {
        [, , $background, $mail, $id] = $this->setupCampaign(500);
        $review = $background->review($id);
        self::assertCount(500, $review['recipients']);
        self::assertLessThan(262144, strlen($review['review_token']));
        $background->confirm($id, $review['review_token']);
        self::assertSame(20, $background->run(20)['sent']);
        self::assertSame(20, $mail->calls);
        self::assertSame(480, $background->status($id)['counts']['queued']);
        $this->expectException(\InvalidArgumentException::class);
        $background->run(21);
    }

    public function testAuthorizationRollsBackWhenItsRequiredAuditFails(): void
    {
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willThrowException(new RuntimeException('Fictional audit unavailable.'));
        [$f, , $background, $mail, $id] = $this->setupCampaign(1, $audit);
        try {
            $background->confirm($id, $background->review($id)['review_token']);
            self::fail('Authorization survived failed auditing.');
        } catch (RuntimeException) {
            self::assertSame([], $f->sql->rows('sis_campaign_dispatches'));
            self::assertSame(0, $mail->calls);
        }
    }

    public function testScheduledDeliveryWaitsForTheReviewedInstantAcrossInstitutionTimeZoneChanges(): void
    {
        [$f, , $background, $mail, $id] = $this->setupCampaign();
        $f->clock->time = new \DateTimeImmutable('2026-08-10T10:00:00', new \DateTimeZone('America/Los_Angeles'));
        self::assertSame('America/Los_Angeles', $background->setup($id)['timezone']);
        $review = $background->review($id, '2026-08-11T09:00', 'America/Los_Angeles');
        self::assertSame('2026-08-11T16:00:00.000000Z', $review['send_after']['utc']);
        self::assertStringContainsString('9:00 am PDT', $review['send_after']['display']);
        $state = $background->confirm($id, $review['review_token']);
        self::assertTrue($state['waiting']);
        self::assertSame(0, $background->run()['examined']);
        self::assertSame(0, $mail->calls);
        // Changing the institution's presentation zone never moves the approved UTC instant.
        $f->clock->time = new \DateTimeImmutable('2026-08-11T11:59:59', new \DateTimeZone('America/New_York'));
        self::assertSame(0, $background->run()['examined']);
        self::assertTrue($background->status($id)['waiting']);
        $f->clock->time = $f->clock->time->modify('+1 second');
        self::assertFalse($background->status($id)['waiting']);
        self::assertSame(3, $background->run()['sent']);
        self::assertSame('completed', $background->status($id)['status']);
    }

    public function testFutureJobsCannotHideDueWorkBehindTheWorkerPageLimit(): void
    {
        [$f, $campaigns, $background, $mail, $id, $deliveries] = $this->setupCampaign(1);
        $user = new EntityId($f->sql->one('sis_deliveries', ['id' => $deliveries[0]->value])['recipient_user_id']);
        for ($i = 0; $i < 12; ++$i) {
            $future = $campaigns->create('Fictional later notice', 'Fictional later message.')->id;
            $campaigns->queue($future, [$user], 0);
            $background->confirm($future, $background->review($future, '2026-08-11T09:00', '-07:00')['review_token']);
            $f->clock->time = $f->clock->time->modify('+1 second');
        }
        $background->confirm($id, $background->review($id)['review_token']);
        self::assertSame(1, $background->run()['sent']);
        self::assertSame(1, $mail->calls);
        self::assertSame('completed', $background->status($id)['status']);
        self::assertCount(12, $f->sql->rows('sis_campaign_dispatches', ['status' => 'active']));
    }

    public function testMissingRepeatedPastAndInvalidLocalTimesCannotAuthorizeDelivery(): void
    {
        [$f, , $background, $mail, $id] = $this->setupCampaign(1);
        $f->clock->time = new \DateTimeImmutable('2026-08-10T10:00:00', new \DateTimeZone('America/Los_Angeles'));
        foreach (
            ['2027-03-14T02:30', '2026-11-01T01:30', '2026-02-30T09:00',
            '2026-08-11T25:00', '2026-08-10T09:00'] as $local
        ) {
            try {
                $background->review($id, $local, 'America/Los_Angeles');
                self::fail('An unavailable or invalid local time was accepted.');
            } catch (\Domain\Shared\Exception\ValidationException $error) {
                self::assertSame('send_at', $error->errors[0]['field']);
            }
        }
        foreach ([null, 'UTC'] as $zone) {
            try {
                $background->review($id, '2026-08-11T09:00', $zone);
                self::fail('The institution time zone was replaced.');
            } catch (\Domain\Shared\Exception\ValidationException $error) {
                self::assertSame('send_at', $error->errors[0]['field']);
            }
        }
        self::assertSame([], $f->sql->rows('sis_campaign_dispatches'));
        self::assertSame(0, $mail->calls);
    }

    public function testSchedulePassingOrTimeZoneChangingBeforeConfirmationRequiresAFreshReview(): void
    {
        foreach (['elapsed', 'timezone'] as $change) {
            [$f, , $background, $mail, $id] = $this->setupCampaign(1);
            $review = $background->review($id, '2026-08-10T10:05', '-07:00');
            $f->clock->time = $change === 'elapsed' ? $f->clock->time->modify('+6 minutes')
            : $f->clock->time->setTimezone(new \DateTimeZone('America/New_York'));
            try {
                $background->confirm($id, $review['review_token']);
                self::fail('A changed schedule was silently authorized.');
            } catch (ConcurrentModificationException) {
                self::assertSame([], $f->sql->rows('sis_campaign_dispatches'));
                self::assertSame(0, $mail->calls);
            }
        }
    }

    public function testScheduledPauseCancellationAndCurrentPermissionsRemainEffectiveWhenDue(): void
    {
        foreach (['pause', 'cancel', 'permission'] as $change) {
            [$f, $campaigns, $background, $mail, $id, , $permissions] = $this->setupCampaign(1);
            $token = $background->review($id, '2026-08-11T09:00', '-07:00')['review_token'];
            $state = $background->confirm($id, $token);
            match ($change) {
                'pause' => $campaigns->pause($id, true),
                'cancel' => $background->cancel($id, $state['version']),
                'permission' => $permissions->assign(
                    GrantKind::UserRole,
                    $f->access->actor,
                    $permissions->roleNamed('super')->id,
                    false
                ),
            };
            $f->clock->time = $f->clock->time->modify('+1 day');
            self::assertSame(0, $background->run()['sent']);
            self::assertSame(0, $mail->calls);
            if ($change === 'pause') {
                $campaigns->pause($id, false);
                self::assertSame(1, $background->run()['sent']);
            } elseif ($change === 'cancel') {
                self::assertSame('cancelled', $background->confirm($id, $token)['status']);
            } else {
                self::assertSame('blocked', $background->status($id)['status']);
            }
        }
    }

    public function testChangedDueIndexAndClockRewindCannotCauseAnEarlySend(): void
    {
        foreach (['index', 'clock'] as $change) {
            [$f, , $background, $mail, $id] = $this->setupCampaign(1);
            $background->confirm($id, $background->review($id, '2026-08-11T09:00', '-07:00')['review_token']);
            if ($change === 'index') {
                $f->sql->update('sis_campaign_dispatches', ['not_before_at' => null], ['id' => $id->value]);
            } else {
                $f->clock->time = $f->clock->time->modify('+1 day');
                $mail->ready = static function () use ($f): void {
                    $f->clock->time = $f->clock->time->modify('-1 day');
                };
            }
            self::assertSame(1, $background->run()['blocked']);
            self::assertSame(0, $mail->calls);
            self::assertSame(1, $background->status($id)['counts']['queued']);
        }
    }

    public function testScheduleMigrationPreservesExistingAuthorizationsWithoutRewritingTheirScope(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261115000016_CreateSisCampaignDispatches.php';
        require_once dirname(__DIR__, 2) . '/database/migrations/20261115000017_AddSisCampaignSchedules.php';
        $connection = new \Qubus\Expressive\Connection\Pdo\Sqlite([
            'driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:',
        ]);
        $connection->pdo->exec('CREATE TABLE users (user_id VARCHAR(36) PRIMARY KEY)');
        $connection->pdo->exec('CREATE TABLE sis_campaigns (id VARCHAR(26) PRIMARY KEY)');
        $map = new \ArrayObject(['phpmig.adapter' =>
            new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($connection, 'migration')]);
        $base = new \CreateSisCampaignDispatches('20261115000016');
        $base->setObjectMap($map);
        $base->up();
        $sql = new \Infrastructure\Persistence\SqlRecords($connection->queryBuilder());
        $id = EntityId::generate();
        $sql->insert('users', ['user_id' => $id->value]);
        $sql->insert('sis_campaigns', ['id' => $id->value]);
        $before = ['id' => $id->value, 'actor_id' => $id->value, 'operation_id' => EntityId::generate()->value,
            'version' => 4, 'status' => 'active', 'encrypted_scope' => 'fictional-opaque-protected-references',
            'updated_at' => '2026-08-10T10:00:00.000000-07:00'];
        $sql->insert('sis_campaign_dispatches', $before);
        $schedule = new \AddSisCampaignSchedules('20261115000017');
        $schedule->setObjectMap($map);
        $schedule->up();
        self::assertSame(
            $before + ['not_before_at' => null],
            $sql->one('sis_campaign_dispatches', ['id' => $id->value])
        );
        $indexes = $connection->pdo->query('PRAGMA index_list(sis_campaign_dispatches)')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertContains('scd_pending', array_column($indexes, 'name'));
        self::assertContains('scd_due', array_column($indexes, 'name'));
    }

    public function testDeliveryReviewMigrationPreservesRecordedTransportStatesAndVersions(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261115000018_AddSisDeliveryReviews.php';
        $connection = new \Qubus\Expressive\Connection\Pdo\Sqlite([
            'driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:',
        ]);
        $connection->pdo->exec('CREATE TABLE sis_deliveries (id VARCHAR(26) PRIMARY KEY, '
            . 'campaign_id VARCHAR(26), recipient_user_id VARCHAR(36), status VARCHAR(16), version INT)');
        $sql = new \Infrastructure\Persistence\SqlRecords($connection->queryBuilder());
        $before = [];
        foreach (['queued', 'sending', 'needs_review', 'sent'] as $version => $status) {
            $row = ['id' => EntityId::generate()->value, 'campaign_id' => EntityId::generate()->value,
                'recipient_user_id' => EntityId::generate()->value, 'status' => $status, 'version' => $version];
            $before[] = $row;
            $sql->insert('sis_deliveries', $row);
        }
        $migration = new \AddSisDeliveryReviews('20261115000018');
        $migration->setObjectMap(new \ArrayObject(['phpmig.adapter' =>
            new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($connection, 'migration')]));
        $migration->up();
        foreach ($before as $row) {
            self::assertSame(
                $row + ['disposition' => null, 'resolution_id' => null, 'encrypted_resolution' => null],
                $sql->one('sis_deliveries', ['id' => $row['id']])
            );
        }
    }

    public function testActualMigrationCompilesItsProtectedQueueAndIndexesForAllFrameworkDialects(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261115000016_CreateSisCampaignDispatches.php';
        require_once dirname(__DIR__, 2) . '/database/migrations/20261115000017_AddSisCampaignSchedules.php';
        require_once dirname(__DIR__, 2) . '/database/migrations/20261115000018_AddSisDeliveryReviews.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $driver) {
            $connection = $this->createStub(\Qubus\Expressive\Connection::class);
            $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $name): string =>
                $driver === 'MySQL' ? '`' . $name . '`' : '"' . $name . '"');
            $compilerClass = 'Qubus\\Expressive\\Schema\\Compiler\\' . $driver;
            $connection->method('schemaCompiler')->willReturn(new $compilerClass($connection));
            $connection->method('getSchema')->willReturn(new \Qubus\Expressive\Schema($connection));
            $statements = [];
            $connection->method('command')->willReturnCallback(static function (string $sql) use (&$statements): bool {
                $statements[] = $sql;
                return true;
            });
            $migration = new \CreateSisCampaignDispatches('20261115000016');
            $migration->setObjectMap(new \ArrayObject([
                'phpmig.adapter' => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $connection,
                    'migration'
                ),
            ]));
            $migration->up();
            $schedule = new \AddSisCampaignSchedules('20261115000017');
            $schedule->setObjectMap($migration->getObjectMap());
            $schedule->up();
            $reviews = new \AddSisDeliveryReviews('20261115000018');
            $reviews->setObjectMap($migration->getObjectMap());
            $reviews->up();
            $compiled = implode("\n", $statements);
            self::assertStringContainsString('sis_campaign_dispatches', $compiled, $driver);
            self::assertStringContainsString('encrypted_scope', $compiled, $driver);
            self::assertStringContainsString('scd_pending', $compiled, $driver);
            self::assertStringContainsString('scd_actor_fk', $compiled, $driver);
            self::assertStringContainsString('scd_due', $compiled, $driver);
            self::assertStringContainsString('not_before_at', $compiled, $driver);
            self::assertStringContainsString('encrypted_resolution', $compiled, $driver);
            self::assertStringContainsString('disposition', $compiled, $driver);
            self::assertStringContainsString('resolution_id', $compiled, $driver);
            self::assertStringNotContainsString('AUTO_INCREMENT', $compiled, $driver);
        }
    }
}
