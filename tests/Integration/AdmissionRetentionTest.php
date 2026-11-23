<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Admissions\Service\AdmissionDocumentContent;
use Domain\Admissions\Service\AdmissionDocumentPolicy;
use Domain\Admissions\Service\AdmissionDocumentStorage;
use Domain\Admissions\ValueObject\AdmissionRetentionDefinition;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use Infrastructure\Persistence\Repository\SqlAdmissionDocumentRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdmissionCaseworkFixture;

final class AdmissionRetentionTest extends TestCase
{
    private const string PDF = "%PDF-1.4\nFictional document.\n%%EOF";

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CampusFixture.php';
        require_once dirname(__DIR__) . '/Support/AdmissionCaseworkFixture.php';
    }

    private function fixture(): array
    {
        $f = new AdmissionCaseworkFixture();
        $storage = new class implements AdmissionDocumentStorage {
            public array $files = [];
            public function put(EntityId $application, EntityId $id, string $bytes): void
            {
                $this->files[$id->value] = $bytes;
            }
            public function read(EntityId $application, EntityId $id): string
            {
                return $this->files[$id->value];
            }
            public function remove(EntityId $application, EntityId $id): void
            {
                unset($this->files[$id->value]);
            }
        };
        return [$f, $f->documents($storage), $f->documentRetention($storage), $storage,
            new SqlAdmissionDocumentRepository($f->campus->sis->sql)];
    }

    private function definition(?EntityId $id = null, int $days = 730): AdmissionRetentionDefinition
    {
        $id ??= EntityId::generate();
        return new AdmissionRetentionDefinition([
            ['id' => $id->value, 'code' => 'WORKING', 'title' => 'Fictional working documents',
                'days' => $days, 'active' => true],
        ], $id->value);
    }

    private function publish($service, AdmissionRetentionDefinition $definition): array
    {
        $proof = $service->reviewPolicy($definition, $service->configuration()['revision'], 'Fictional policy review.');
        $result = $service->publishPolicy(
            new EntityId($proof['operation_id']),
            $definition,
            $proof['revision'],
            $proof['reason'],
            $proof['review_token']
        );
        return [$proof, $result];
    }

    private function confirm($service, EntityId $application, EntityId $document, array $proof): array
    {
        return $service->classify(
            $application,
            $document,
            new EntityId($proof['operation_id']),
            $proof['version'],
            new EntityId($proof['category_id']),
            $proof['reason'],
            $proof['revision'],
            $proof['review_token']
        );
    }

    public function testOwnerUploadsCaptureDefaultDefinitionsAndLaterPolicyChangesDoNotRewriteTheFile(): void
    {
        [$f, $documents, $retention, $storage, $repo] = $this->fixture();
        $definition = $this->definition();
        [$proof, $policy] = $this->publish($retention, $definition);
        $s = $f->campus->sis;
        $events = $s->sql->rows('sis_history_events');
        $s->access->staff = false;
        $id = EntityId::generate();
        $documents->upload($f->application, $id, 'fictional.pdf', self::PDF, false);
        $row = $repo->find($id);
        $content = new AdmissionDocumentContent($f->campus->cipher, $storage);
        self::assertSame(730, $content->metadata($row)['retention_basis']['days']);
        self::assertSame($policy['id'], $content->metadata($row)['retention_basis']['policy_id']);
        self::assertArrayNotHasKey(
            'retention_basis',
            $documents->listing($f->application, false)['items'][0],
        );
        $s->access->staff = true;
        $this->publish($retention, $this->definition(new EntityId($definition->defaultId), 30));
        self::assertSame($row, $repo->find($id));
        self::assertSame(
            ['id' => $id->value, 'status' => 'quarantined'],
            $documents->upload($f->application, $id, 'fictional.pdf', self::PDF, false)
        );
        self::assertSame($row, $repo->find($id));
        self::assertCount(1, $retention->history($f->application, $id)['items']);
        self::assertSame($events, $s->sql->rows('sis_history_events'));
    }

    public function testDeploymentFallbackIsCapturedOnlyForNewUploadsAndRemainsASeparateBasis(): void
    {
        [$f, $documents, $retention, $storage, $repo] = $this->fixture();
        $id = EntityId::generate();
        $documents->upload($f->application, $id, 'fallback.pdf', self::PDF, false);
        $content = new AdmissionDocumentContent($f->campus->cipher, $storage);
        $basis = $content->metadata($repo->find($id))['retention_basis'];
        self::assertSame(['source' => 'deployment', 'policy_id' => null, 'policy_revision' => 0,
            'days' => 365, 'category' => null], $basis);
        $history = $retention->history($f->application, $id)['items'];
        self::assertSame('upload', $history[0]['source']);
        self::assertNull($history[0]['reason']);
    }

    public function testClassificationExtendsFromUploadTimeAndNeverShortensAnExistingDeadlineOrReleasesHolds(): void
    {
        [$f, $documents, $retention, $storage, $repo] = $this->fixture();
        $id = EntityId::generate();
        $documents->upload($f->application, $id, 'fictional.pdf', self::PDF, false);
        $before = $repo->find($id);
        $category = EntityId::generate();
        $this->publish($retention, $this->definition($category, 730));
        $f->campus->sis->clock->time = $f->campus->sis->clock->now()->modify('+50 days');
        $documents->retain($f->application, $id, 1, true);
        $review = $retention->reviewClassification($f->application, $id, 2, $category, 'Fictional classification.');
        $this->confirm($retention, $f->application, $id, $review);
        $row = $repo->find($id);
        self::assertSame(
            new \DateTimeImmutable($before['created_at'])->modify('+730 days')->format('Y-m-d\TH:i:s.u\Z'),
            $row['expires_at']
        );
        self::assertSame(1, (int) $row['held']);
        $this->publish($retention, $this->definition($category, 30));
        $review = $retention->reviewClassification($f->application, $id, 3, $category, 'Fictional updated category.');
        $this->confirm($retention, $f->application, $id, $review);
        self::assertSame($row['expires_at'], $repo->find($id)['expires_at']);
        self::assertSame(30, new AdmissionDocumentContent($f->campus->cipher, $storage)
            ->metadata($repo->find($id))['retention_basis']['days']);
        self::assertCount(3, $retention->history($f->application, $id)['items']);
    }

    public function testAnEarlierFileWithoutRecordedBasisStaysExplicitlyUnknownWhenClassified(): void
    {
        [$f, $documents, $retention, $storage, $repo] = $this->fixture();
        $id = EntityId::generate();
        $documents->upload($f->application, $id, 'older.pdf', self::PDF, false);
        $content = new AdmissionDocumentContent($f->campus->cipher, $storage);
        $row = $repo->find($id);
        $meta = $content->metadata($row);
        unset($meta['retention_basis']);
        $f->campus->sis->sql->update(
            'sis_admission_documents',
            ['protected_metadata' => $content->protect($row, $meta)],
            ['id' => $id->value]
        );
        $f->campus->sis->sql->execute('DELETE FROM sis_admission_document_categories WHERE id = ?', [$id->value]);
        $definition = $this->definition();
        $this->publish($retention, $definition);
        $review = $retention->reviewClassification(
            $f->application,
            $id,
            1,
            new EntityId($definition->defaultId),
            'Fictional first recorded classification.'
        );
        self::assertNull($review['before']);
        $this->confirm($retention, $f->application, $id, $review);
        $history = $retention->history($f->application, $id)['items'];
        self::assertCount(1, $history);
        self::assertNull($history[0]['before']);
    }

    public function testChangedPolicyOrDocumentInvalidatesAnUnconfirmedClassification(): void
    {
        foreach (['policy', 'document'] as $changed) {
            [$f, $documents, $retention, , $repo] = $this->fixture();
            $id = EntityId::generate();
            $documents->upload($f->application, $id, 'fictional.pdf', self::PDF, false);
            $definition = $this->definition();
            $this->publish($retention, $definition);
            $review = $retention->reviewClassification(
                $f->application,
                $id,
                1,
                new EntityId($definition->defaultId),
                'Fictional reviewed selection.'
            );
            if ($changed === 'policy') {
                $this->publish($retention, $this->definition(new EntityId($definition->defaultId), 90));
            } else {
                $documents->retain($f->application, $id, 1, true);
            }
            $before = $repo->find($id);
            try {
                $this->confirm($retention, $f->application, $id, $review);
                self::fail('A changed classification source was accepted.');
            } catch (ConcurrentModificationException) {
                self::assertSame($before, $repo->find($id));
                self::assertCount(1, $retention->history($f->application, $id)['items']);
            }
        }
    }

    public function testOriginalPolicyAndClassificationRetriesRemainExactAfterLaterChangesAndExpiry(): void
    {
        [$f, $documents, $retention] = $this->fixture();
        $definition = $this->definition();
        [$policyProof, $policy] = $this->publish($retention, $definition);
        $id = EntityId::generate();
        $documents->upload($f->application, $id, 'fictional.pdf', self::PDF, false);
        $proof = $retention->reviewClassification(
            $f->application,
            $id,
            1,
            new EntityId($definition->defaultId),
            'Fictional recorded classification.'
        );
        $result = $this->confirm($retention, $f->application, $id, $proof);
        $this->publish($retention, $this->definition(new EntityId($definition->defaultId), 30));
        $f->campus->sis->clock->time = $f->campus->sis->clock->now()->modify('+1 day');
        self::assertSame($policy, $retention->publishPolicy(
            new EntityId($policyProof['operation_id']),
            $definition,
            $policyProof['revision'],
            $policyProof['reason'],
            $policyProof['review_token']
        ));
        self::assertSame($result, $this->confirm($retention, $f->application, $id, $proof));
        self::assertCount(2, $retention->history($f->application, $id)['items']);
    }

    public function testCategoryIdentitiesCannotBeRemovedRenamedByCodeOrSelectedAfterRetirement(): void
    {
        [$f, , $retention] = $this->fixture();
        $definition = $this->definition();
        $this->publish($retention, $definition);
        $changed = $definition->categories;
        $changed[0]['code'] = 'DIFFERENT';
        foreach ([new AdmissionRetentionDefinition(), new AdmissionRetentionDefinition($changed)] as $next) {
            try {
                $retention->reviewPolicy($next, 1, 'Fictional invalid removal.');
                self::fail('An existing category identity or code was removed.');
            } catch (ValidationException) {
                self::assertSame(1, $retention->configuration()['revision']);
            }
        }
        $changed = $definition->categories;
        $changed[0]['active'] = false;
        $this->publish($retention, new AdmissionRetentionDefinition($changed));
        $this->expectException(ValidationException::class);
        new AdmissionRetentionDefinition($changed, $definition->defaultId);
    }

    public function testBothAuthoritiesAreRequiredAndOwnersNeverReceiveClassificationHistory(): void
    {
        [$f, $documents, $retention] = $this->fixture();
        $id = EntityId::generate();
        $documents->upload($f->application, $id, 'fictional.pdf', self::PDF, false);
        $s = $f->campus->sis;
        $s->access->staff = false;
        foreach ([[], ['sis:admissions:manage'], ['sis:admissions:documents:retain']] as $grants) {
            $s->access->grants = $grants;
            foreach (['configuration', 'history'] as $action) {
                try {
                    if ($action === 'configuration') {
                        $retention->configuration();
                    } else {
                        $retention->history($f->application, $id);
                    }
                    self::fail('Incomplete authority read private retention details.');
                } catch (AccessDeniedException) {
                    self::assertArrayNotHasKey(
                        'retention_basis',
                        $documents->listing($f->application, false)['items'][0],
                    );
                }
            }
        }
    }

    public function testFailedPolicyAuditRollsBackThePublication(): void
    {
        [$f, , , $storage] = $this->fixture();
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit->expects(self::once())->method('record')->willThrowException(new \RuntimeException('Fictional audit.'));
        $retention = $f->documentRetention($storage, audit: $audit);
        try {
            $this->publish($retention, $this->definition());
            self::fail('An unaudited retention publication committed.');
        } catch (\RuntimeException) {
            self::assertSame(0, $retention->configuration()['revision']);
        }
    }

    public function testClassificationAuditFailureAuthorityLossAndExpiryRollBackFileAndReceiptTogether(): void
    {
        foreach (['audit', 'authority', 'expiry'] as $failure) {
            [$f, $documents, $retention, $storage, $repo] = $this->fixture();
            $definition = $this->definition();
            $this->publish($retention, $definition);
            $id = EntityId::generate();
            $documents->upload($f->application, $id, 'fictional.pdf', self::PDF, false);
            $before = $repo->find($id);
            $s = $f->campus->sis;
            $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
            $audit->expects(self::once())->method('record')
                ->willReturnCallback(static function () use ($s, $failure): void {
                    if ($failure === 'audit') {
                        throw new \RuntimeException('Fictional audit.');
                    }
                    if ($failure === 'authority') {
                        $s->access->staff = false;
                    } else {
                        $s->clock->time = $s->clock->now()->modify('+21 minutes');
                    }
                });
            $service = $f->documentRetention($storage, audit: $audit);
            $proof = $service->reviewClassification(
                $f->application,
                $id,
                1,
                new EntityId($definition->defaultId),
                'Fictional explicit classification.'
            );
            try {
                $this->confirm($service, $f->application, $id, $proof);
                self::fail('A failed final classification check committed.');
            } catch (\Throwable $error) {
                self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $error);
                self::assertSame($before, $repo->find($id));
                self::assertCount(1, $s->sql->rows('sis_admission_document_categories'));
            }
        }
    }

    public function testTamperedPolicyRoutingStopsFreshUploadsWithoutReinterpretingAnAcceptedRetry(): void
    {
        [$f, $documents, $retention, $storage] = $this->fixture();
        $definition = $this->definition();
        [, $policy] = $this->publish($retention, $definition);
        $id = EntityId::generate();
        $documents->upload($f->application, $id, 'fictional.pdf', self::PDF, false);
        $f->campus->sis->sql->update('sis_admission_retention_policies', ['revision' => 2], ['id' => $policy['id']]);
        self::assertSame(
            ['id' => $id->value, 'status' => 'quarantined'],
            $documents->upload($f->application, $id, 'fictional.pdf', self::PDF, false)
        );
        try {
            $documents->upload($f->application, EntityId::generate(), 'new.pdf', self::PDF, false);
            self::fail('Tampered policy routing was used for a new upload.');
        } catch (DomainException) {
            self::assertCount(1, $storage->files);
        }
    }
    public function testFullUnicodePoliciesAndReasonsKeepTheirRetainedDefinitions(): void
    {
        [$f, , $retention] = $this->fixture();
        $categories = [];
        for ($index = 0; $index < 25; $index++) {
            $categories[] = ['id' => EntityId::generate()->value, 'code' => 'DOC.' . $index,
                'title' => str_repeat('🙂', 120), 'days' => 365, 'active' => true];
        }
        $definition = new AdmissionRetentionDefinition($categories, $categories[0]['id']);
        $reason = str_repeat('🙂', 1000);
        $proof = $retention->reviewPolicy($definition, 0, $reason);
        $retention->publishPolicy(
            new EntityId($proof['operation_id']),
            $definition,
            0,
            $reason,
            $proof['review_token'],
        );
        self::assertSame($definition->data(), $retention->configuration()['definition']);
        self::assertSame($reason, $retention->policies()['items'][0]['reason']);
        self::assertSame([], $retention->configuration()['new_ids']);
    }

    public function testClassificationKeepsRecordedDecisionEvidenceAndDoesNotAddAcademicEvents(): void
    {
        [$f, $documents, $retention, $storage, $repo] = $this->fixture();
        $s = $f->campus->sis;
        $id = EntityId::generate();
        $documents->upload($f->application, $id, 'evidence.pdf', self::PDF, false);
        $scanner = new class implements \Domain\Admissions\Service\AdmissionDocumentScanner {
            public function scan(string $bytes): string
            {
                return 'clean';
            }
        };
        $worker = new \Domain\Admissions\Service\AdmissionDocumentWorker(
            $repo,
            $s->admissions,
            $storage,
            $scanner,
            new AdmissionDocumentPolicy(true, 365, true),
            $f->campus->cipher,
            $s->transactions,
            $s->clock,
            $f->documentHolds(),
        );
        $worker->run();
        $evidence = new \Domain\Admissions\Service\AdmissionEvidenceService(
            $repo,
            new AdmissionDocumentContent($f->campus->cipher, $storage),
            $s->clock,
        );
        $decisions = $s->decisionService(evidence: $evidence);
        $proof = $decisions->review(
            $f->application,
            true,
            'Fictional actual admission decision.',
            [['id' => $id->value, 'version' => (int) $repo->find($id)['version']]]
        );
        $decisions->commit($f->application, $proof['review_token']);
        $before = $s->sql->rows('sis_history_events');
        $content = new AdmissionDocumentContent($f->campus->cipher, $storage);
        $retained = $content->metadata($repo->find($id))['evidence_decisions'];
        $definition = $this->definition();
        $this->publish($retention, $definition);
        $review = $retention->reviewClassification(
            $f->application,
            $id,
            (int) $repo->find($id)['version'],
            new EntityId($definition->defaultId),
            'Fictional retention classification.'
        );
        $this->confirm($retention, $f->application, $id, $review);
        self::assertSame($retained, $content->metadata($repo->find($id))['evidence_decisions']);
        self::assertSame($before, $s->sql->rows('sis_history_events'));
        $s->clock->time = $s->clock->now()->modify('+3 years');
        $this->expectException(ConcurrentModificationException::class);
        $documents->reviewPurge($f->application, $id, (int) $repo->find($id)['version'], 'Fictional expiry.');
    }

    public function testOriginalClassificationReceiptAndHistoryRemainAvailableAfterApprovedDisposal(): void
    {
        [$f, $documents, $retention, , $repo] = $this->fixture();
        $definition = $this->definition(days: 1);
        $this->publish($retention, $definition);
        $id = EntityId::generate();
        $documents->upload($f->application, $id, 'fictional.pdf', self::PDF, false);
        $proof = $retention->reviewClassification(
            $f->application,
            $id,
            1,
            new EntityId($definition->defaultId),
            'Fictional recorded classification.'
        );
        $result = $this->confirm($retention, $f->application, $id, $proof);
        $f->campus->sis->clock->time = $f->campus->sis->clock->now()->modify('+2 days');
        $purge = $documents->reviewPurge($f->application, $id, 2, 'Fictional approved expiry.');
        $documents->purge($f->application, $id, $purge['review_token']);
        $before = $repo->find($id);
        self::assertSame($result, $this->confirm($retention, $f->application, $id, $proof));
        self::assertSame($before, $repo->find($id));
        self::assertCount(2, $retention->history($f->application, $id)['items']);
    }

    public function testPolicyHistoryIsPagedAndOriginalCommandsIgnoreAssociativeKeyOrder(): void
    {
        [$f, , $retention] = $this->fixture();
        $definition = $this->definition();
        [$proof, $result] = $this->publish($retention, $definition);
        $reordered = new AdmissionRetentionDefinition(
            [array_reverse($definition->categories[0], true)],
            $definition->defaultId
        );
        self::assertSame($result, $retention->publishPolicy(
            new EntityId($proof['operation_id']),
            $reordered,
            $proof['revision'],
            $proof['reason'],
            $proof['review_token']
        ));
        for ($i = 0; $i < 20; $i++) {
            $this->publish($retention, $definition);
        }
        $first = $retention->policies();
        self::assertCount(20, $first['items']);
        $last = $retention->policies($first['next_cursor']);
        self::assertCount(1, $last['items']);
        self::assertNull($last['next_cursor']);
        self::assertSame(1, $last['items'][0]['revision']);
    }
    public function testLongerClassificationCannotReopenExpiredApplicantAccessIncludingOlderSharedExpiryFiles(): void
    {
        foreach ([false, true] as $older) {
            [$f, $documents, $retention, $storage, $repo] = $this->fixture();
            $s = $f->campus->sis;
            $id = EntityId::generate();
            $documents->upload($f->application, $id, 'fictional.pdf', self::PDF, false);
            $scanner = new class implements \Domain\Admissions\Service\AdmissionDocumentScanner {
                public function scan(string $bytes): string
                {
                    return 'clean';
                }
            };
            new \Domain\Admissions\Service\AdmissionDocumentWorker(
                $repo,
                $s->admissions,
                $storage,
                $scanner,
                new AdmissionDocumentPolicy(true, 365, true),
                $f->campus->cipher,
                $s->transactions,
                $s->clock,
                $f->documentHolds(),
            )->run();
            if ($older) {
                $content = new AdmissionDocumentContent($f->campus->cipher, $storage);
                $row = $repo->find($id);
                $meta = $content->metadata($row);
                unset($meta['applicant_expires_at']);
                $s->sql->update(
                    'sis_admission_documents',
                    ['protected_metadata' => $content->protect($row, $meta)],
                    ['id' => $id->value]
                );
            }
            $originalCutoff = $repo->find($id)['expires_at'];
            $s->clock->time = $s->clock->now()->modify('+366 days');
            $s->access->staff = false;
            self::assertFalse($documents->listing($f->application, false)['items'][0]['can_download']);
            $s->access->staff = true;
            $definition = $this->definition();
            $this->publish($retention, $definition);
            $proof = $retention->reviewClassification(
                $f->application,
                $id,
                3,
                new EntityId($definition->defaultId),
                'Fictional longer retention without reopening applicant access.'
            );
            $this->confirm($retention, $f->application, $id, $proof);
            self::assertGreaterThan($s->clock->now()->format('Y-m-d\TH:i:s.u\Z'), $repo->find($id)['expires_at']);
            $s->access->staff = false;
            self::assertSame($originalCutoff, $documents->listing($f->application, false)['items'][0]['expires_at']);
            self::assertFalse($documents->listing($f->application, false)['items'][0]['can_download']);
            try {
                $documents->download($f->application, $id, false);
                self::fail('Retention classification reopened an expired applicant download.');
            } catch (DomainException) {
                self::assertSame(4, (int) $repo->find($id)['version']);
            }
            $s->access->staff = true;
            $documents->retain($f->application, $id, 4, false, substr($repo->find($id)['expires_at'], 0, 10), true);
            $s->access->staff = false;
            self::assertTrue($documents->listing($f->application, false)['items'][0]['can_download']);
            self::assertSame(self::PDF, $documents->download($f->application, $id, false)['bytes']);
        }
    }
}
