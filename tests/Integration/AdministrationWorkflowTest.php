<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/SisFixture.php';

use Defuse\Crypto\Key;
use Domain\Communications\Service\CampaignService;
use Domain\Communications\Service\MailTransport;
use Domain\Import\Service\CatalogImportService;
use Domain\Portal\Service\PortalService;
use Domain\Reporting\Service\ReportService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Communications\DefuseMessageCipher;
use Infrastructure\Persistence\Repository\SqlCampaignRepository;
use Infrastructure\Persistence\Repository\SqlCatalogImportRepository;
use Infrastructure\Persistence\Repository\SqlPortalRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use PHPUnit\Framework\TestCase;
use Qubus\Config\ConfigContainer;

final class FictionalTransport implements MailTransport
{
    public int $calls = 0;
    public bool $fail = false;

    public function assertReady(): void
    {
    }

    public function send(
        EntityId $delivery,
        #[SensitiveParameter] string $recipient,
        #[SensitiveParameter] string $subject,
        #[SensitiveParameter] string $body
    ): void {
        ++$this->calls;
        if ($this->fail) {
            throw new RuntimeException('fictional-upstream-secret');
        }
    }
}

final class AdministrationWorkflowTest extends TestCase
{
    private function campaigns(SisFixture $f, FictionalTransport $transport): CampaignService
    {
        $config = $this->createStub(ConfigContainer::class);
        $config->method('getConfigKey')->willReturn(Key::createNewRandomKey()->saveToAsciiSafeString());

        return new CampaignService(
            new SqlCampaignRepository($f->sql),
            new DefuseMessageCipher($config),
            $transport,
            $f->access,
            $f->transactions,
            $f->audit
        );
    }

    public function testCampaignEncryptsContentQueuesOnlyReferencesAndSendsOnce(): void
    {
        $f = new SisFixture();
        $f->sql->update('users', ['email' => 'reader@example.invalid'], ['user_id' => $f->access->actor->value]);
        $transport = new FictionalTransport();
        $service = $this->campaigns($f, $transport);
        $campaign = $service->create('Example notice', 'Fictional confidential message.');
        $stored = $f->sql->one('sis_campaigns', ['id' => $campaign->id->value]);
        self::assertStringNotContainsString('Fictional', $stored['encrypted_content']);
        $deliveries = $service->queue($campaign->id, [$f->access->actor]);
        $queued = $f->sql->one('sis_deliveries', ['id' => $deliveries[0]->value]);
        self::assertSame(['id', 'campaign_id', 'recipient_user_id', 'status', 'version'], array_keys($queued));
        $service->pause($campaign->id, true);
        try {
            $service->dispatch($deliveries[0]);
            self::fail('Paused campaign sent a message.');
        } catch (DomainException) {
            self::assertSame(0, $transport->calls);
        }
        $service->pause($campaign->id, false);
        $service->dispatch($deliveries[0]);
        $service->dispatch($deliveries[0]);
        self::assertSame(1, $transport->calls);
        self::assertSame('completed', $f->sql->one('sis_campaigns', ['id' => $campaign->id->value])['status']);
        self::assertStringNotContainsString('confidential', json_encode($f->sql->rows('sis_audit'), JSON_THROW_ON_ERROR));
    }

    public function testUncertainDeliveryIsHeldWithoutLeakingUpstreamErrorsOrRetrying(): void
    {
        $f = new SisFixture();
        $f->sql->update('users', ['email' => 'reader@example.invalid'], ['user_id' => $f->access->actor->value]);
        $transport = new FictionalTransport();
        $transport->fail = true;
        $service = $this->campaigns($f, $transport);
        $campaign = $service->create('Example notice', 'Fictional body.');
        $id = $service->queue($campaign->id, [$f->access->actor])[0];
        try {
            $service->dispatch($id);
            self::fail('Transport failure was not reported.');
        } catch (DomainException $e) {
            self::assertStringNotContainsString('upstream', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
        self::assertSame('needs_review', $f->sql->one('sis_deliveries', ['id' => $id->value])['status']);
        $service->dispatch($id);
        self::assertSame(1, $transport->calls);
    }

    public function testReportsRequireExecutionPermissionAndExplicitOwnershipOrSharing(): void
    {
        $f = new SisFixture();
        $f->student();
        $service = new ReportService(new SqlReportRepository($f->sql), $f->access, $f->transactions, $f->audit);
        $report = $service->create('Active students', $f->program, null, true);
        $owner = $f->access->actor;
        $other = $f->user();
        $f->access->actor = $other;
        $f->access->staff = false;
        $f->access->grants = ['sis:reporting:run'];
        try {
            $service->run($report->id);
            self::fail('An unshared report was exposed.');
        } catch (AccessDeniedException) {
            self::assertCount(1, $f->sql->rows('sis_reports'));
        }
        $f->access->actor = $owner;
        $f->access->staff = true;
        $service->share($report->id, $other);
        $f->access->actor = $other;
        $f->access->staff = false;
        $rows = $service->run($report->id)['items'];
        self::assertCount(1, $rows);
        self::assertSame(['student_id', 'institutional_id', 'program_id', 'entry_term_id', 'active', 'program_code', 'entry_term_code'], array_keys($rows[0]));
    }

    public function testCatalogCsvImportIsRepeatableAndBatchFailureRollsBackEverything(): void
    {
        $f = new SisFixture();
        $service = new CatalogImportService(new SqlCatalogImportRepository($f->sql), $f->access, $f->transactions, $f->audit);
        $header = "external_id,code,title,credits_milli\n";
        $csv = $header . "a1,CS101,Introduction,3000\n";
        self::assertSame(1, $service->courses('old-system', $csv));
        self::assertSame(0, $service->courses('old-system', $csv));
        try {
            $service->courses('old-system', $header . "a2,CS102,Algorithms,3000\na3,CS101,Duplicate,3000\n");
            self::fail('Duplicate catalog code did not roll back the batch.');
        } catch (PDOException) {
            self::assertCount(1, $f->sql->rows('sis_courses'));
            self::assertCount(1, $f->sql->rows('sis_import_mappings'));
        }
    }

    public function testPortalDraftCannotBeReadAndPublishingSharesOnlyThePublishedPost(): void
    {
        $f = new SisFixture();
        $service = new PortalService(new SqlPortalRepository($f->sql), $f->access, $f->transactions, $f->audit);
        $post = $service->create('news', 'welcome', 'Welcome', 'Institution news.');
        try {
            $service->published('welcome');
            self::fail('Draft content was exposed.');
        } catch (RecordNotFoundException) {
            self::assertSame('draft', $f->sql->one('sis_portal_posts', ['id' => $post->id->value])['status']);
        }
        $service->publish($post->id, true, 0);
        $f->access->actor = $f->user();
        $f->access->staff = false;
        self::assertSame('Institution news.', $service->published('welcome')->content);
        $this->expectException(AccessDeniedException::class);
        $service->publish($post->id, false, 1);
    }

    public function testLiveTransportStaysDisabledWithoutExplicitConfiguration(): void
    {
        $config = $this->createStub(ConfigContainer::class);
        $config->method('getConfigKey')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $default);
        $transport = new \Infrastructure\Communications\ConfiguredMailTransport($config);
        $this->expectException(DomainException::class);
        $transport->send(EntityId::generate(), 'reader@example.invalid', 'Example', 'Fictional body.');
    }
}
