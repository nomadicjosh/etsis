<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use Tests\Support\NewsletterFixture;

final class WebsiteNewsletterTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/NewsletterFixture.php';
    }

    public function testConsentIsOptionalAndOnlyExplicitConsentQueuesOneReferenceOnlyJob(): void
    {
        $f = new NewsletterFixture();
        $s = $f->forms->sis;
        $before = $s->sql->rows('sis_history_events');
        $noConsent = $f->submit(false);
        self::assertNull($f->jobs->job($noConsent));
        self::assertCount(0, $f->gateway->calls);
        $proof = $f->forms->submissions->prepare('1', 'contact', 'en', 'fictional-browser-csrf');
        $id = $f->submit(true, $proof);
        $f->forms->submit($proof, $f->forms->answers + ['newsletter_opt_in' => true]);
        self::assertCount(1, $s->sql->rows('sis_website_subscriptions'));
        self::assertStringNotContainsString(
            'alex@example.invalid',
            json_encode($s->sql->rows('sis_website_subscriptions'))
        );
        self::assertStringNotContainsString('fictional-secret', json_encode($f->connections->directory()));
        self::assertSame(1, $f->worker->run()['completed']);
        self::assertCount(1, $f->gateway->calls);
        self::assertSame($f->forms->answers['email'], $f->gateway->calls[0]['contact']['email']);
        self::assertArrayNotHasKey('message', $f->gateway->calls[0]['contact']);
        self::assertSame($id->value, $f->gateway->calls[0]['id']);
        self::assertSame(0, $f->worker->run()['processed']);
        self::assertSame($before, $s->sql->rows('sis_history_events'));
    }

    public function testDeletionCancelsPendingDeliveryAndAnOriginalIntakeRetryCannotRecreateIt(): void
    {
        $f = new NewsletterFixture();
        $proof = $f->forms->submissions->prepare('1', 'contact', 'en', 'fictional-browser-csrf');
        $id = $f->submit(true, $proof);
        $f->forms->inbox->change($id, 0, 'delete');
        self::assertNull($f->jobs->job($id));
        self::assertSame(0, $f->worker->run()['processed']);
        $f->forms->submit($proof, $f->forms->answers + ['newsletter_opt_in' => true]);
        self::assertSame([], $f->forms->sis->sql->rows('sis_website_form_inbox'));
        self::assertSame([], $f->forms->sis->sql->rows('sis_website_subscriptions'));
        self::assertCount(0, $f->gateway->calls);
    }

    public function testQueueFailureRollsBackTheInquiryAndRetryReceipt(): void
    {
        $f = new NewsletterFixture();
        $s = $f->forms->sis;
        $s->connection->pdo->exec("CREATE TRIGGER fictional_job_fail BEFORE INSERT ON sis_website_subscriptions "
            . "BEGIN SELECT RAISE(ABORT, 'Fictional queue failure'); END");
        try {
            $f->submit();
            self::fail('A queue failure partially accepted an inquiry.');
        } catch (\PDOException) {
            foreach (['sis_website_subscriptions', 'sis_website_form_inbox', 'sis_website_form_receipts'] as $table) {
                self::assertSame([], $s->sql->rows($table));
            }
        }
    }

    public function testTransientRetriesAreBoundedAndUncertainDeliveryNeedsExplicitReviewedRetry(): void
    {
        $f = new NewsletterFixture();
        $id = $f->submit();
        $f->gateway->results = ['retry'];
        for ($i = 0; $i < 3; $i++) {
            self::assertSame(1, $f->worker->run()['processed']);
            $f->forms->sis->clock->time = $f->forms->sis->clock->time->modify('+6 minutes');
        }
        self::assertSame('review', $f->jobs->job($id)['state']);
        self::assertSame(0, $f->worker->run()['processed']);
        $job = $f->jobs->job($id);
        $f->forms->inbox->retryNewsletter($id, (int) $job['version']);
        $f->gateway->results = ['uncertain'];
        $f->worker->run();
        self::assertSame('review', $f->jobs->job($id)['state']);
        self::assertSame('uncertain', $f->jobs->job($id)['issue']);
        self::assertSame(0, $f->worker->run()['processed']);
        $this->expectException(ConcurrentModificationException::class);
        $f->forms->inbox->retryNewsletter($id, (int) $job['version']);
    }

    public function testGlobalAndConnectionPausesPreventOutboundCallsWithoutLosingTheInquiry(): void
    {
        $f = new NewsletterFixture();
        $id = $f->submit();
        $f->policy->active = false;
        self::assertTrue($f->worker->run()['paused']);
        self::assertSame('queued', $f->jobs->job($id)['state']);
        $f->policy->active = true;
        $f->connections->save(new EntityId($f->connection['id']), 'Paused fictional list', false, [], 0);
        self::assertSame(1, $f->worker->run()['attention']);
        self::assertSame('disabled', $f->jobs->job($id)['issue']);
        self::assertCount(0, $f->gateway->calls);
        self::assertSame($f->forms->answers['email'], $f->forms->inbox->show($id)['answers']['email']);
    }

    public function testDestinationIsImmutableWhileSecretRotationPreservesThePublishedBinding(): void
    {
        $f = new NewsletterFixture();
        $saved = $f->connections->save(
            new EntityId($f->connection['id']),
            'Rotated fictional list',
            true,
            ['secret' => 'replacement-fictional-secret'],
            0
        );
        self::assertSame($f->connection['target_hash'], $saved['target_hash']);
        self::assertStringNotContainsString(
            'replacement-fictional-secret',
            json_encode($f->connections->show(new EntityId($saved['id'])))
        );
        $f->submit();
        self::assertSame(1, $f->worker->run()['completed']);
        $this->expectException(ValidationException::class);
        $f->connections->save(new EntityId($saved['id']), 'Wrong destination', true, ['list' => 'another_list'], 1);
    }

    public function testExpiredDeliveryLeaseIsNeverAutomaticallyResent(): void
    {
        $f = new NewsletterFixture();
        $id = $f->submit();
        $f->jobs->claim($id, 0, $f->forms->sis->clock->time->getTimestamp());
        $f->forms->sis->clock->time = $f->forms->sis->clock->time->modify('+2 minutes');
        self::assertSame(1, $f->worker->run()['attention']);
        self::assertSame('interrupted', $f->jobs->job($id)['issue']);
        self::assertCount(0, $f->gateway->calls);
    }
    public function testFailedRequiredDeleteAuditPreservesBothTheInquiryAndItsPendingJob(): void
    {
        $f = new NewsletterFixture();
        $id = $f->submit();
        $s = $f->forms->sis;
        $before = [$s->sql->rows('sis_website_form_inbox'), $s->sql->rows('sis_website_subscriptions')];
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willThrowException(new \RuntimeException('Fictional audit unavailable.'));
        try {
            $f->forms->inbox($audit)->change($id, 0, 'delete');
            self::fail('Failed audit committed a partial inquiry/job deletion.');
        } catch (\RuntimeException) {
            self::assertSame($before, [$s->sql->rows('sis_website_form_inbox'),
                $s->sql->rows('sis_website_subscriptions')]);
        }
    }

    public function testIntegrationAuthorityAndCurrentReplyScopeAreIndependentRequirements(): void
    {
        $f = new NewsletterFixture();
        $id = $f->submit();
        $f->gateway->results = ['uncertain'];
        $f->worker->run();
        $s = $f->forms->sis;
        $permission = $s->sql->one('sis_access_permissions', ['name' => 'sis:website:forms:integrations']);
        (new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql))->assign(
            \Domain\Access\Enum\GrantKind::UserPermission,
            $s->access->actor,
            new EntityId($permission['id']),
            false
        );
        try {
            $f->connections->directory();
            self::fail('An ordinary inquiry duty exposed newsletter configuration.');
        } catch (\Domain\Shared\Exception\AccessDeniedException) {
            self::assertSame('review', $f->forms->inbox->show($id)['newsletter']['state']);
        }
        (new \Infrastructure\Persistence\Repository\SqlWebsiteFormRoutingRepository($s->sql))->save(
            'form',
            '1',
            'contact',
            $s->access->actor,
            false
        );
        $job = $f->jobs->job($id);
        try {
            $f->forms->inbox->retryNewsletter($id, (int) $job['version']);
            self::fail('A stale or unrelated scope queued newsletter work.');
        } catch (\Domain\Shared\Exception\RecordNotFoundException) {
            self::assertSame($job, $f->jobs->job($id));
        }
    }
}
