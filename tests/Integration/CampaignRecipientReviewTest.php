<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Communications\Repository\CampaignRepository;
use Domain\Communications\Service\CampaignService;
use Domain\Communications\Service\MailTransport;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use Infrastructure\Persistence\Repository\SqlCampaignRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class CampaignRecipientReviewTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function service(SisFixture $f, ?CampaignRepository $repository = null): CampaignService
    {
        $transport = $this->createMock(MailTransport::class);
        $transport->expects(self::never())->method('send');
        return new CampaignService(
            $repository ?? new SqlCampaignRepository($f->sql),
            $f->dropCipher,
            $transport,
            $f->access,
            $f->transactions,
            $f->audit
        );
    }

    private function recipient(SisFixture $f, string $name = 'Fictional'): EntityId
    {
        $user = $f->user();
        $f->sql->update('users', ['email' => 'reader@example.invalid'], ['user_id' => $user->value]);
        $f->legacyPerson($user, $name, 'Recipient');
        return $user;
    }

    public function testPickerUsesBoundedLiteralSearchAndMinimalPersonLabels(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        self::assertSame(['items' => [], 'next_after' => null], $service->recipientChoices(''));
        for ($i = 0; $i < 55; ++$i) {
            $this->recipient($f);
        }
        $literal = $this->recipient($f, 'Fictional 100%_!');
        $disabled = $this->recipient($f);
        $f->sql->update('users', ['sign_in_enabled' => 0], ['user_id' => $disabled->value]);
        $before = $f->sql->rows('sis_history_events');
        $first = $service->recipientChoices('Fictional');
        self::assertCount(50, $first['items']);
        self::assertNotNull($first['next_after']);
        $second = $service->recipientChoices('Fictional', new EntityId($first['next_after']));
        self::assertCount(6, $second['items']);
        self::assertNull($second['next_after']);
        self::assertCount(56, array_unique(array_column([...$first['items'], ...$second['items']], 'user_id')));
        self::assertNotContains($disabled->value, array_column([...$first['items'], ...$second['items']], 'user_id'));
        $match = $service->recipientChoices('100%_!')['items'];
        self::assertCount(1, $match);
        self::assertSame($literal->value, $match[0]['user_id']);
        self::assertSame(['id', 'user_id', 'given_name', 'family_name', 'institutional_id'], array_keys($match[0]));
        self::assertCount(1, $service->recipientChoices($match[0]['institutional_id'])['items']);
        self::assertStringNotContainsString('reader@example.invalid', json_encode($first, JSON_THROW_ON_ERROR));
        self::assertSame($before, $f->sql->rows('sis_history_events'));
    }

    public function testReviewCreatesNoDeliveriesAndQueueRequiresTheExactReviewedDraftRevision(): void
    {
        $f = new SisFixture();
        $recipient = $this->recipient($f);
        $service = $this->service($f);
        $campaign = $service->create('Fictional invitation', 'Fictional reviewed text.');
        $review = $service->reviewRecipients($campaign->id, [$recipient, $recipient], 0);
        self::assertSame('Fictional invitation', $review['subject']);
        self::assertSame('Fictional reviewed text.', $review['body']);
        self::assertCount(1, $review['recipients']);
        self::assertSame([], $f->sql->rows('sis_deliveries'));
        $service->edit($campaign->id, 'Changed invitation', 'Different text needs review.', 0);
        foreach (['review', 'queue'] as $operation) {
            try {
                if ($operation === 'review') {
                    $service->reviewRecipients($campaign->id, [$recipient], 0);
                } else {
                    $service->queue($campaign->id, [$recipient], 0);
                }
                self::fail('An old message revision was accepted.');
            } catch (ConcurrentModificationException) {
                self::assertSame([], $f->sql->rows('sis_deliveries'));
            }
        }
        $fresh = $service->reviewRecipients($campaign->id, [$recipient], 1);
        self::assertSame('Changed invitation', $fresh['subject']);
        $deliveries = $service->queue($campaign->id, [$recipient, $recipient], $fresh['version']);
        self::assertCount(1, $deliveries);
        self::assertCount(1, $f->sql->rows('sis_deliveries'));
        self::assertSame('queued', $service->show($campaign->id)['status']);
        self::assertStringNotContainsString('Different text', json_encode($f->sql->rows('sis_campaigns')));
        $this->expectException(ConcurrentModificationException::class);
        $service->queue($campaign->id, [$recipient], 1);
    }

    public function testDirectoryAuthorityIsRequiredIndependentlyOfCampaignOwnership(): void
    {
        $f = new SisFixture();
        $recipient = $this->recipient($f);
        $service = $this->service($f);
        $campaign = $service->create('Fictional invitation', 'Fictional text.');
        $f->access->staff = false;
        $f->access->grants = ['sis:communications:manage'];
        foreach (['search', 'review', 'queue'] as $operation) {
            try {
                match ($operation) {
                    'search' => $service->recipientChoices('Fictional'),
                    'review' => $service->reviewRecipients($campaign->id, [$recipient], 0),
                    'queue' => $service->queue($campaign->id, [$recipient], 0),
                };
                self::fail('Campaign ownership bypassed person-directory authority.');
            } catch (AccessDeniedException) {
                self::assertSame([], $f->sql->rows('sis_deliveries'));
            }
        }
        $f->access->grants[] = 'sis:person:manage';
        $f->access->actor = $f->user();
        $this->expectException(AccessDeniedException::class);
        $service->reviewRecipients($campaign->id, [$recipient], 0);
    }

    public function testInvalidDisabledOrUnlinkedRecipientsCannotPartiallyQueueAnAudience(): void
    {
        $f = new SisFixture();
        $valid = $this->recipient($f);
        $disabled = $this->recipient($f);
        $invalid = $this->recipient($f);
        $unlinked = $f->user();
        $f->sql->update('users', ['sign_in_enabled' => 0], ['user_id' => $disabled->value]);
        $f->sql->update('users', ['email' => 'invalid'], ['user_id' => $invalid->value]);
        $service = $this->service($f);
        $campaign = $service->create('Fictional invitation', 'Fictional text.');
        foreach ([$disabled, $invalid, $unlinked, EntityId::generate()] as $unavailable) {
            try {
                $service->queue($campaign->id, [$valid, $unavailable], 0);
                self::fail('An unavailable recipient was queued.');
            } catch (DomainException $e) {
                self::assertStringNotContainsString('invalid@', $e->getMessage());
                self::assertSame([], $f->sql->rows('sis_deliveries'));
                self::assertSame('draft', $service->show($campaign->id)['status']);
            }
        }
        $service->reviewRecipients($campaign->id, [$valid], 0);
        $f->sql->update('users', ['sign_in_enabled' => 0], ['user_id' => $valid->value]);
        $this->expectException(DomainException::class);
        $service->queue($campaign->id, [$valid], 0);
    }

    public function testReviewAndSearchRecheckAuthorityAfterTheirDirectoryReads(): void
    {
        foreach (['search', 'review'] as $operation) {
            $f = new SisFixture();
            $recipient = $this->recipient($f);
            $real = new SqlCampaignRepository($f->sql);
            $campaign = $this->service($f)->create('Fictional invitation', 'Fictional text.');
            $repo = $this->createStub(CampaignRepository::class);
            $repo->method('get')->willReturn($campaign);
            $read = static function () use ($f, $recipient, $real): array {
                $rows = $real->audience([$recipient]);
                $f->access->staff = false;
                $f->access->grants = ['sis:communications:manage'];
                return $rows;
            };
            $repo->method('audience')->willReturnCallback($read);
            $repo->method('recipientChoices')->willReturnCallback($read);
            $service = $this->service($f, $repo);
            try {
                if ($operation === 'search') {
                    $service->recipientChoices('Fictional');
                } else {
                    $service->reviewRecipients($campaign->id, [$recipient], 0);
                }
                self::fail('A private recipient response survived removed directory authority.');
            } catch (AccessDeniedException) {
                self::assertSame([], $f->sql->rows('sis_deliveries'));
            }
        }
    }

    public function testRecipientBoundsAreEnforcedBeforeDeduplication(): void
    {
        $f = new SisFixture();
        $recipient = $this->recipient($f);
        $service = $this->service($f);
        $campaign = $service->create('Fictional invitation', 'Fictional text.');
        $review = $service->reviewRecipients($campaign->id, array_fill(0, 500, $recipient), 0);
        self::assertCount(1, $review['recipients']);
        $this->expectException(InvalidArgumentException::class);
        $service->queue($campaign->id, array_fill(0, 501, $recipient), 0);
    }

    public function testUnavailableRecipientsStopBeforeDispatchWithoutAnUncertainSend(): void
    {
        foreach (['disabled', 'invalid-address'] as $problem) {
            $f = new SisFixture();
            $recipient = $this->recipient($f);
            $service = $this->service($f);
            $campaign = $service->create('Fictional invitation', 'Fictional text.');
            $delivery = $service->queue($campaign->id, [$recipient], 0)[0];
            $f->sql->update(
                'users',
                $problem === 'disabled' ? ['sign_in_enabled' => 0] : ['email' => 'invalid'],
                ['user_id' => $recipient->value]
            );
            try {
                $service->dispatch($delivery);
                self::fail('An unavailable recipient was sent a message.');
            } catch (DomainException) {
                self::assertSame('queued', $f->sql->one('sis_deliveries', ['id' => $delivery->value])['status']);
            }
        }
    }
}
