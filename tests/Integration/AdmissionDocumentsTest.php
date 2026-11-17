<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Admissions\Service\AdmissionDocumentPolicy;
use Domain\Admissions\Service\AdmissionDocumentScanner;
use Domain\Admissions\Service\AdmissionDocumentService;
use Domain\Admissions\Service\AdmissionDocumentWorker;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use Infrastructure\Admissions\PrivateAdmissionDocumentStorage;
use Infrastructure\Persistence\Repository\SqlAdmissionDocumentRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdmissionCaseworkFixture;

final class AdmissionDocumentsTest extends TestCase
{
    private array $roots = [];
    private const string PDF = "%PDF-1.4\nFictional admissions document.\n%%EOF";

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CampusFixture.php';
        require_once dirname(__DIR__) . '/Support/AdmissionCaseworkFixture.php';
    }
    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            foreach (glob($root . '/*/*') ?: [] as $file) {
                unlink($file);
            }
            foreach (glob($root . '/*') ?: [] as $directory) {
                rmdir($directory);
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }
    private function fixture(?AdmissionDocumentPolicy $policy = null): array
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $root = '/tmp/etsis-document-tests-' . bin2hex(random_bytes(8));
        $this->roots[] = $root;
        $storage = new PrivateAdmissionDocumentStorage($f->campus->cipher, $root);
        $repo = new SqlAdmissionDocumentRepository($s->sql);
        $policy ??= new AdmissionDocumentPolicy(true, 365, true);
        $service = new AdmissionDocumentService(
            $repo,
            $s->admissions,
            $storage,
            $policy,
            $f->campus->cipher,
            $s->access,
            $s->transactions,
            $s->audit,
            $s->clock,
            new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql)
        );
        $scanner = new class implements AdmissionDocumentScanner {
            public string $verdict = 'clean';
            public ?\Closure $callback = null;
            public function scan(#[\SensitiveParameter] string $bytes): string
            {
                if ($this->callback !== null) {
                    ($this->callback)();
                }
                return $this->verdict;
            }
        };
        $worker = new AdmissionDocumentWorker(
            $repo,
            $s->admissions,
            $storage,
            $scanner,
            $policy,
            $f->campus->cipher,
            $s->transactions,
            $s->clock
        );
        return [$f, $service, $worker, $repo, $storage, $scanner, $root];
    }
    public function testUploadIsEncryptedQuarantinedAndIdempotentUntilABackgroundScanAllowsDownload(): void
    {
        [$f, $service, $worker, $repo, $storage, $scanner, $root] = $this->fixture();
        $id = EntityId::generate();
        $before = $f->campus->sis->sql->rows('sis_history_events');
        $service->upload($f->application, $id, 'fictional.pdf', self::PDF, false);
        self::assertSame('quarantined', $repo->find($id)['status']);
        $audit = $f->campus->sis->sql->rows('sis_audit');
        $service->upload($f->application, $id, 'fictional.pdf', self::PDF, false);
        self::assertSame($audit, $f->campus->sis->sql->rows('sis_audit'));
        $path = $root . '/' . $f->application->value . '/' . $id->value . '.protected';
        self::assertStringNotContainsString('Fictional', file_get_contents($path));
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertSame(0700, fileperms(dirname($path)) & 0777);
        self::assertStringNotContainsString('fictional.pdf', $repo->find($id)['protected_metadata']);
        try {
            $service->download($f->application, $id, false);
            self::fail('An unscanned file was downloadable.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
        $scanner->callback = static function () use ($f): void {
            self::assertFalse($f->campus->sis->connection->pdo->inTransaction());
        };
        self::assertSame(1, $worker->run()['ready']);
        self::assertSame(self::PDF, $service->download($f->application, $id, false)['bytes']);
        self::assertTrue($service->listing($f->application, false)['items'][0]['can_download']);
        self::assertSame($before, $f->campus->sis->sql->rows('sis_history_events'));
        $service->upload($f->application, $id, 'fictional.pdf', self::PDF, false);
        self::assertSame('ready', $repo->find($id)['status']);
    }
    public function testUnavailableAndInfectedResultsNeverPermitDownload(): void
    {
        foreach (['unavailable' => 'quarantined', 'infected' => 'rejected'] as $verdict => $status) {
            [$f, $service, $worker, $repo, , $scanner] = $this->fixture();
            $scanner->verdict = $verdict;
            $id = EntityId::generate();
            $service->upload($f->application, $id, 'fictional.pdf', self::PDF, false);
            $worker->run();
            self::assertSame($status, $repo->find($id)['status']);
            self::assertFalse($service->listing($f->application, false)['items'][0]['can_download']);
            try {
                $service->download($f->application, $id, false);
                self::fail('Unsafe file download.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
            if ($verdict === 'unavailable') {
                self::assertSame(0, $worker->run()['examined']);
                $scanner->verdict = 'clean';
                $service->rescan($f->application, $id, (int) $repo->find($id)['version']);
                self::assertSame(1, $worker->run()['ready']);
            }
        }
    }
    public function testOwnersCannotReadAnotherApplicationOrOverrideOwnershipWithStaffPrivileges(): void
    {
        [$f, $service, $worker] = $this->fixture();
        $id = EntityId::generate();
        $service->upload($f->application, $id, 'fictional.pdf', self::PDF, false);
        $worker->run();
        $s = $f->campus->sis;
        $s->access->actor = $s->user();
        try {
            $service->download($f->application, $id, false);
            self::fail('Staff bypassed owner scope.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        self::assertSame(self::PDF, $service->download($f->application, $id, true)['bytes']);
    }
    public function testChangedProjectionOrBlobCannotBypassTheProtectedScanVerdict(): void
    {
        foreach (['status', 'held', 'expires_at', 'blob'] as $field) {
            [$f, $service, $worker, $repo, , , $root] = $this->fixture();
            $id = EntityId::generate();
            $service->upload($f->application, $id, 'fictional.pdf', self::PDF, false);
            if ($field !== 'status') {
                $worker->run();
            }
            if ($field === 'blob') {
                file_put_contents($root . '/' . $f->application->value . '/' . $id->value . '.protected', 'tampered');
            } else {
                $f->campus->sis->sql->update(
                    'sis_admission_documents',
                    [$field => match ($field) {
                    'status' => 'ready', 'held' => 1, default => '2099-01-01T00:00:00.000000Z'
                    }],
                    ['id' => $id->value]
                );
            }
            try {
                $service->download($f->application, $id, false);
                self::fail('Changed protected content was accepted.');
            } catch (\Throwable $error) {
                self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $error);
            }
        }
    }
    public function testRetentionHoldsStaleReviewsAndDisposalUseOneExplicitReviewedFlow(): void
    {
        [$f, $service, $worker, $repo, $storage, , $root] = $this->fixture(new AdmissionDocumentPolicy(true, 1, true));
        $id = EntityId::generate();
        $service->upload($f->application, $id, 'fictional.pdf', self::PDF, false);
        $worker->run();
        $f->campus->sis->clock->time = $f->campus->sis->clock->now()->modify('+2 days');
        $version = (int) $repo->find($id)['version'];
        $token = $service->reviewPurge($f->application, $id, $version, 'Fictional retention period completed.');
        $service->retain($f->application, $id, $version, true);
        try {
            $service->purge($f->application, $id, $token['review_token']);
            self::fail('A hold was bypassed.');
        } catch (ConcurrentModificationException) {
            self::assertSame('ready', $repo->find($id)['status']);
        }
        $version = (int) $repo->find($id)['version'];
        $service->retain($f->application, $id, $version, false);
        $token = $service->reviewPurge($f->application, $id, $version + 1, 'Fictional approved disposal.');
        $service->purge($f->application, $id, $token['review_token']);
        self::assertSame('purging', $repo->find($id)['status']);
        $tombstone = json_decode($f->campus->cipher->open($repo->find($id)['protected_metadata']), true);
        self::assertArrayNotHasKey('name', $tombstone);
        self::assertSame(1, $worker->run()['removed']);
        self::assertSame('purged', $repo->find($id)['status']);
        $service->purge($f->application, $id, $token['review_token']);
        self::assertSame([], $service->listing($f->application, false)['items']);
        self::assertSame(['id' => $id->value, 'status' => 'purged'], $service->upload(
            $f->application,
            $id,
            'fictional.pdf',
            self::PDF,
            false
        ));
        self::assertFileDoesNotExist($root . '/' . $f->application->value . '/' . $id->value . '.protected');
    }
    public function testDisabledOrUnreviewedSetupDoesNotExposeAnUploadWorkflow(): void
    {
        foreach (
            [new AdmissionDocumentPolicy(false, 365, true), new AdmissionDocumentPolicy(true, 0, true),
            new AdmissionDocumentPolicy(true, 365, false)] as $policy
        ) {
            [$f, $service] = $this->fixture($policy);
            self::assertFalse($service->listing($f->application, false)['enabled']);
            try {
                $service->upload($f->application, EntityId::generate(), 'fictional.pdf', self::PDF, false);
                self::fail('Incomplete setup accepted an upload.');
            } catch (DomainException) {
                self::assertSame([], $f->campus->sis->sql->rows('sis_admission_documents'));
            }
        }
    }
    public function testInvalidFormatsNamesAndOversizedInputsNeverReachPrivateStorage(): void
    {
        [$f, $service, , , , , $root] = $this->fixture();
        foreach (
            [['fictional.html', '<script>fictional</script>'], ['../fictional.pdf', self::PDF],
            ['fictional.pdf', str_repeat('x', AdmissionDocumentPolicy::MAX_BYTES + 1)]] as [$name, $bytes]
        ) {
            try {
                $service->upload($f->application, EntityId::generate(), $name, $bytes, false);
                self::fail('Unsupported file input was stored.');
            } catch (\DomainException | \Domain\Shared\Exception\ValidationException) {
                self::assertSame([], $f->campus->sis->sql->rows('sis_admission_documents'));
                self::assertSame([], glob($root . '/*/*') ?: []);
            }
        }
    }
    public function testIndependentRetentionAuthorityCannotBeReplacedWithAdmissionsOrOwnerAccess(): void
    {
        [$f, $service, $worker, $repo] = $this->fixture();
        $id = EntityId::generate();
        $service->upload($f->application, $id, 'fictional.pdf', self::PDF, false);
        $worker->run();
        $s = $f->campus->sis;
        $s->access->staff = false;
        foreach ([['sis:admissions:manage'], ['sis:admissions:documents:retain'], []] as $grants) {
            $s->access->grants = $grants;
            try {
                $service->retain($f->application, $id, (int) $repo->find($id)['version'], true);
                self::fail('Incomplete retention authority was accepted.');
            } catch (AccessDeniedException) {
                self::assertSame(0, (int) $repo->find($id)['held']);
            }
        }
    }
    public function testRequiredUploadAuditFailureRollsBackTheRowAndRemovesItsBlob(): void
    {
        [$f, , , $repo, $storage, , $root] = $this->fixture();
        $s = $f->campus->sis;
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit->expects(self::once())->method('record')->willThrowException(
            new \RuntimeException('Fictional audit failure.')
        );
        $service = new AdmissionDocumentService(
            $repo,
            $s->admissions,
            $storage,
            new AdmissionDocumentPolicy(true, 365, true),
            $f->campus->cipher,
            $s->access,
            $s->transactions,
            $audit,
            $s->clock,
            new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql)
        );
        try {
            $service->upload($f->application, EntityId::generate(), 'fictional.pdf', self::PDF, false);
            self::fail('An unaudited upload was committed.');
        } catch (\RuntimeException $e) {
            self::assertSame('Fictional audit failure.', $e->getMessage());
        }
        self::assertSame([], $s->sql->rows('sis_admission_documents'));
        self::assertSame([], glob($root . '/*/*') ?: []);
    }
    public function testLateScanCannotRestoreAnApprovedDisposedDocument(): void
    {
        [$f, $service, $worker, $repo, , $scanner] = $this->fixture(new AdmissionDocumentPolicy(true, 1, true));
        $id = EntityId::generate();
        $service->upload($f->application, $id, 'fictional.pdf', self::PDF, false);
        $scanner->callback = static function () use ($f, $service, $repo, $id): void {
            $f->campus->sis->clock->time = $f->campus->sis->clock->now()->modify('+2 days');
            $review = $service->reviewPurge(
                $f->application,
                $id,
                (int) $repo->find($id)['version'],
                'Fictional approved expiry while scan was delayed.'
            );
            $service->purge($f->application, $id, $review['review_token']);
        };
        self::assertSame(1, $worker->run()['pending']);
        self::assertSame('purging', $repo->find($id)['status']);
        self::assertSame(1, $worker->run()['removed']);
        self::assertSame('purged', $repo->find($id)['status']);
    }
    public function testPerApplicationQuotaAndCrossApplicationIdCollisionPreserveExistingFiles(): void
    {
        [$f, $service, , $repo, $storage] = $this->fixture();
        $first = EntityId::generate();
        $service->upload($f->application, $first, 'fictional.pdf', self::PDF, false);
        $s = $f->campus->sis;
        $person = $s->admissions->application($f->application)->personId;
        $other = $s->legacyApplication($person, $s->program, $s->term);
        try {
            $service->upload($other->id, $first, 'fictional.pdf', self::PDF, false);
            self::fail('A document reference crossed applications.');
        } catch (ConcurrentModificationException) {
            self::assertSame(self::PDF, $storage->read($f->application, $first));
        }
        for ($i = 1; $i < 20; $i++) {
            $service->upload($f->application, EntityId::generate(), 'fictional.pdf', self::PDF, false);
        }
        try {
            $service->upload($f->application, EntityId::generate(), 'fictional.pdf', self::PDF, false);
            self::fail('The application quota was exceeded.');
        } catch (\Domain\Shared\Exception\ValidationException) {
            self::assertCount(20, $repo->forApplication($f->application));
        }
    }
    public function testRetentionCapabilityIsProtectedAndNotGrantedThroughTheAdminSisRole(): void
    {
        [$f] = $this->fixture();
        $s = $f->campus->sis;
        $roles = new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql);
        $user = $s->user();
        $roles->assign(\Domain\Access\Enum\GrantKind::UserRole, $user, $roles->roleNamed('admin')->id, true);
        self::assertFalse($roles->allows($user->value, 'sis:admissions:documents:retain'));
        $permission = $s->sql->one('sis_access_permissions', ['name' => 'sis:admissions:documents:retain']);
        self::assertSame(1, (int) $permission['is_system']);
        $roles->assign(\Domain\Access\Enum\GrantKind::UserPermission, $user, new EntityId($permission['id']), true);
        self::assertTrue($roles->allows($user->value, 'sis:admissions:documents:retain'));
    }

    public function testAbandonedScanLeaseCanBeReclaimedWithoutExternalSideEffects(): void
    {
        [$f, $service, $worker, $repo, $storage] = $this->fixture();
        $id = EntityId::generate();
        $service->upload($f->application, $id, 'fictional.pdf', self::PDF, false);
        $row = $repo->find($id);
        $content = new \Domain\Admissions\Service\AdmissionDocumentContent($f->campus->cipher, $storage);
        $past = $f->campus->sis->clock->now()->modify('-6 minutes')->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
        $repo->change($id, 1, $content->changes($row, ['status' => 'scanning',
            'scan_token' => EntityId::generate()->value, 'scan_after' => $past]));
        self::assertSame(1, $worker->run()['ready']);
        self::assertSame('ready', $repo->find($id)['status']);
        self::assertNull($repo->find($id)['scan_token']);
    }
    public function testApprovedCleanupContinuesWhenNewUploadsAreDisabled(): void
    {
        [$f, $service, , $repo, $storage, $scanner] = $this->fixture(new AdmissionDocumentPolicy(true, 1, true));
        $id = EntityId::generate();
        $service->upload($f->application, $id, 'fictional.pdf', self::PDF, false);
        $s = $f->campus->sis;
        $s->clock->time = $s->clock->now()->modify('+2 days');
        $review = $service->reviewPurge($f->application, $id, 1, 'Fictional expired-file disposal.');
        $service->purge($f->application, $id, $review['review_token']);
        $worker = new AdmissionDocumentWorker(
            $repo,
            $s->admissions,
            $storage,
            $scanner,
            new AdmissionDocumentPolicy(false, 0),
            $f->campus->cipher,
            $s->transactions,
            $s->clock
        );
        self::assertSame(1, $worker->run()['removed']);
        self::assertSame('purged', $repo->find($id)['status']);
    }

    public function testStaffCanBlockAnAvailableFileForAFreshScanWithoutReplacingItOrItsHold(): void
    {
        [$f, $service, $worker, $repo] = $this->fixture();
        $id = EntityId::generate();
        $service->upload($f->application, $id, 'fictional.pdf', self::PDF, false);
        $worker->run();
        $version = (int) $repo->find($id)['version'];
        $service->retain($f->application, $id, $version, true);
        $service->rescan($f->application, $id, $version + 1);
        self::assertSame('quarantined', $repo->find($id)['status']);
        self::assertSame(1, (int) $repo->find($id)['held']);
        try {
            $service->download($f->application, $id, true);
            self::fail('A quarantined file was returned.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
        $service->rescan($f->application, $id, $version + 1);
        self::assertSame(1, $worker->run()['ready']);
        self::assertSame(self::PDF, $service->download($f->application, $id, false)['bytes']);
    }
    public function testStaffFilesAreHiddenFromOwnersUntilRecordsStaffExplicitlyShareThem(): void
    {
        [$f, $service, $worker, $repo] = $this->fixture();
        $id = EntityId::generate();
        $service->upload($f->application, $id, 'fictional-private.pdf', self::PDF, true);
        $worker->run();
        self::assertSame([], $service->listing($f->application, false)['items']);
        try {
            $service->download($f->application, $id, false);
            self::fail('An internal file reached its applicant.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        self::assertSame(self::PDF, $service->download($f->application, $id, true)['bytes']);
        $service->retain($f->application, $id, (int) $repo->find($id)['version'], false, visible: true);
        self::assertCount(1, $service->listing($f->application, false)['items']);
        self::assertSame(self::PDF, $service->download($f->application, $id, false)['bytes']);
        $service->upload($f->application, $id, 'fictional-private.pdf', self::PDF, true);
        self::assertSame('ready', $repo->find($id)['status']);
        $service->retain($f->application, $id, (int) $repo->find($id)['version'], false, visible: false);
        self::assertSame([], $service->listing($f->application, false)['items']);
    }
    public function testOrdinaryAdmissionsAuthorityCanUploadInternallyButCannotShareFiles(): void
    {
        [$f, $service] = $this->fixture();
        $f->campus->sis->access->staff = false;
        $f->campus->sis->access->grants = ['sis:admissions:manage'];
        try {
            $service->upload($f->application, EntityId::generate(), 'fictional.pdf', self::PDF, true, true);
            self::fail('Sharing bypassed the independent records permission.');
        } catch (AccessDeniedException) {
            self::assertSame([], $f->campus->sis->sql->rows('sis_admission_documents'));
        }
        $service->upload($f->application, EntityId::generate(), 'fictional.pdf', self::PDF, true);
        self::assertCount(1, $service->listing($f->application, true)['items']);
        self::assertSame([], $service->listing($f->application, false)['items']);
    }
    public function testScanCompletionPreservesAnAccessChangeMadeDuringScanning(): void
    {
        [$f, $service, $worker, $repo, , $scanner] = $this->fixture();
        $id = EntityId::generate();
        $service->upload($f->application, $id, 'fictional-private.pdf', self::PDF, true);
        $scanner->callback = static function () use ($f, $service, $repo, $id): void {
            $service->retain($f->application, $id, (int) $repo->find($id)['version'], false, visible: true);
        };
        self::assertSame(1, $worker->run()['ready']);
        self::assertCount(1, $service->listing($f->application, false)['items']);
    }
}
