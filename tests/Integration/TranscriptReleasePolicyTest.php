<?php

declare(strict_types=1);

namespace Tests\Integration;

use ArrayObject;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transcript\Repository\TranscriptReleasePolicyRepository;
use Domain\Transcript\Service\TranscriptDocumentRenderer;
use Domain\Transcript\Service\TranscriptReleasePolicyService;
use Domain\Transcript\ValueObject\TranscriptReleaseConfirmation;
use Domain\Transcript\ValueObject\TranscriptReleasePolicy;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlTranscriptReleasePolicyRepository;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\CreateTable;
use TranscriptFixture;

final class TranscriptReleasePolicyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/TranscriptFixture.php';
    }

    private function repository(TranscriptFixture $t): SqlTranscriptReleasePolicyRepository
    {
        return new SqlTranscriptReleasePolicyRepository($t->sis->sql, $t->campus->cipher);
    }

    private function policyService(TranscriptFixture $t, ?AuditTrail $audit = null): TranscriptReleasePolicyService
    {
        return new TranscriptReleasePolicyService(
            $this->repository($t),
            $t->sis->access,
            new SqlAccessRepository($t->sis->sql),
            $t->sis->transactions,
            $audit ?? $t->sis->audit
        );
    }

    private function check(string $stage = 'both', string $label = 'Confirm release authority'): array
    {
        return ['id' => EntityId::generate()->value, 'label' => $label, 'details' => 'Use the approved office process.',
            'stage' => $stage];
    }

    private function confirmation(array $policy, string $stage): TranscriptReleaseConfirmation
    {
        return new TranscriptReleaseConfirmation($policy['revision'], array_column(
            new TranscriptReleasePolicy($policy['revision'], $policy['checks'])->required($stage),
            'id'
        ));
    }

    private function renderer(?callable $render = null): TranscriptDocumentRenderer
    {
        $r = $this->createStub(TranscriptDocumentRenderer::class);
        $r->method('html')->willReturn('<p>Fictional protected transcript</p>');
        $r->method('pdf')->willReturnCallback($render ?? static fn(): string => '%PDF-1.4 Fictional transcript');
        return $r;
    }

    public function testConfigurationIsEncryptedAuditedBoundedAndIndependentOfTranscriptPermission(): void
    {
        $t = new TranscriptFixture();
        $setup = $this->policyService($t);
        self::assertSame(['revision' => 0, 'checks' => []], $setup->current());
        $history = $t->sis->sql->rows('sis_history_events');
        $check = $this->check();
        $policy = $setup->save(0, [$check]);
        self::assertSame(1, $policy['revision']);
        $row = $t->sis->sql->one('sis_transcript_policy', ['id' => TranscriptReleasePolicyRepository::POLICY_ID]);
        self::assertStringNotContainsString($check['label'], $row['encrypted_definition']);
        self::assertSame($policy, $setup->current());
        self::assertSame($history, $t->sis->sql->rows('sis_history_events'));
        self::assertSame($policy, $setup->save(1, [$check])); // No-op does not make a spurious revision.
        $t->sis->access->staff = false;
        $t->sis->access->grants = ['sis:transcript:manage', 'sis:student:read'];
        self::assertSame($policy, $setup->current());
        try {
            $setup->save(1, []);
            self::fail('Document operators changed institutional policy without its independent permission.');
        } catch (AccessDeniedException) {
            self::assertSame($policy, $setup->current());
        }
        $t->sis->access->grants[] = 'sis:transcript:policy';
        self::assertSame(2, $setup->save(1, [])['revision']);
        $this->expectException(ConcurrentModificationException::class);
        $setup->save(1, [$check]);
    }

    public function testChecksRejectDuplicateIdsUnknownStagesControlsAndExtraFields(): void
    {
        $check = $this->check();
        foreach (
            [[$check, $check],
            [array_replace($check, ['stage' => 'registration'])],
            [array_replace($check, ['label' => "Not\na title"])],
            [array_replace($check, ['label' => str_repeat('x', 161)])],
            [array_replace($check, ['details' => str_repeat('x', 501)])],
            [$check + ['automatic_balance_block' => 'yes']],
            array_fill(0, 13, $check)] as $checks
        ) {
            try {
                new TranscriptReleasePolicy(1, $checks);
                self::fail('Malformed institutional checks were accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $policy = new TranscriptReleasePolicy(1, [array_reverse($check, true)]);
        self::assertSame([$check], $policy->checks);
        foreach (
            [['revision' => 1, 'checks' => [], 'actor_id' => 'client'],
            ['revision' => '1', 'checks' => []], ['revision' => 1, 'checks' => [$check['id'], $check['id']]]] as $data
        ) {
            try {
                TranscriptReleaseConfirmation::fromArray($data);
                self::fail('Malformed confirmation was accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testPreparationAndDeliveryRequireTheirOwnExactChecksAndRetainEarlierEvidence(): void
    {
        $t = new TranscriptFixture();
        $request = $t->request();
        $setup = $this->policyService($t);
        $policy = $setup->save(0, [$this->check('preparation', 'Review release authorization'),
            $this->check('delivery', 'Verify recipient identity'),
            $this->check('both', 'Check institutional instructions')]);
        $service = $t->service(renderer: $this->renderer());
        $review = $service->review($request);
        self::assertArrayNotHasKey('release_policy', $review['snapshot']);
        $op = new EntityId($review['operation_id']);
        foreach ([null, $this->confirmation($policy, 'delivery')] as $confirmation) {
            try {
                $service->prepare(
                    $request,
                    $op,
                    $review['revision'],
                    true,
                    $review['review_token'],
                    release: $confirmation
                );
                self::fail('Preparation without all preparation checks was accepted.');
            } catch (ValidationException) {
                self::assertSame([], $t->sis->sql->rows('sis_transcript_documents'));
            }
        }
        $prep = $this->confirmation($policy, 'preparation');
        $saved = $service->prepare($request, $op, $review['revision'], true, $review['review_token'], release: $prep);
        $bytes = $service->pdf($request, $op);
        $html = $service->html($request, $op);
        $view = $service->view($request, $op);
        self::assertSame(
            ['Review release authorization', 'Check institutional instructions'],
            array_column($view['preparation_release']['checks'], 'label')
        );
        $next = $setup->save(1, [$this->check('delivery', 'Follow revised delivery procedure')]);
        self::assertSame($saved, $service->prepare($request, $op, $review['revision'], true, '', release: $prep));
        self::assertFalse($service->view($request, $op)['academic_changed']);
        self::assertSame($bytes, $service->pdf($request, $op));
        self::assertSame($html, $service->html($request, $op));
        self::assertStringNotContainsString('Review release authorization', $html);
        try {
            $service->reviewDelivery(
                $request,
                $op,
                $saved['prepared_version'],
                $t->sis->clock->now(),
                'Fictional pickup'
            );
            self::fail('Delivery skipped current institutional checks.');
        } catch (ValidationException) {
            self::assertSame([], $t->sis->sql->rows('sis_transcript_deliveries'));
        }
        $confirmation = $this->confirmation($next, 'delivery');
        $at = $t->sis->clock->now();
        $delivery = $service->reviewDelivery(
            $request,
            $op,
            $saved['prepared_version'],
            $at,
            'Fictional pickup',
            $confirmation
        );
        $deliveryOp = new EntityId($delivery['operation_id']);
        $result = $service->deliver(
            $request,
            $op,
            $deliveryOp,
            $saved['prepared_version'],
            $at,
            'Fictional pickup',
            $delivery['review_token'],
            $confirmation
        );
        $setup->save(2, []);
        self::assertSame($result, $service->deliver(
            $request,
            $op,
            $deliveryOp,
            $saved['prepared_version'],
            $at,
            'Fictional pickup',
            '',
            $confirmation
        ));
        $view = $service->view($request, $op);
        self::assertSame(1, $view['preparation_release']['revision']);
        self::assertSame(2, $view['delivery_release']['revision']);
        self::assertSame('Follow revised delivery procedure', $view['delivery_release']['checks'][0]['label']);
        self::assertArrayNotHasKey('actor_id', $view['delivery_release']);
        $this->expectException(ConcurrentModificationException::class);
        $service->deliver(
            $request,
            $op,
            $deliveryOp,
            $saved['prepared_version'],
            $at,
            'Fictional pickup',
            '',
            new TranscriptReleaseConfirmation(3, [])
        );
    }

    public function testPolicyChangeDuringRenderingRejectsStalePreparationAndLeavesNoDocument(): void
    {
        $t = new TranscriptFixture();
        $request = $t->request();
        $setup = $this->policyService($t);
        $renderer = $this->renderer(function () use ($setup): string {
            $setup->save(0, [$this->check('preparation')]);
            return '%PDF-1.4 Fictional transcript';
        });
        $service = $t->service(renderer: $renderer);
        $review = $service->review($request);
        try {
            $service->prepare(
                $request,
                new EntityId($review['operation_id']),
                $review['revision'],
                true,
                $review['review_token']
            );
            self::fail('A policy changed during rendering was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $t->sis->sql->rows('sis_transcript_documents'));
            self::assertSame('pending', $t->repository->request($request)['status']);
        }
    }

    public function testPolicyChangeDuringDeliveryAuditRollsBackEntireConfirmation(): void
    {
        $t = new TranscriptFixture();
        $request = $t->request();
        $normal = $t->service(renderer: $this->renderer());
        $review = $normal->review($request);
        $document = new EntityId($review['operation_id']);
        $saved = $normal->prepare($request, $document, $review['revision'], true, $review['review_token']);
        $at = $t->sis->clock->now();
        $delivery = $normal->reviewDelivery($request, $document, $saved['prepared_version'], $at, 'Fictional receipt');
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(function (EntityId $actor, string $action) use ($t): void {
            if ($action === 'transcript.delivery_recorded') {
                $this->repository($t)->save(new TranscriptReleasePolicy(1, [$this->check('delivery')]), 0);
            }
        });
        $service = $t->service(renderer: $this->renderer(), audit: $audit);
        try {
            $service->deliver(
                $request,
                $document,
                new EntityId($delivery['operation_id']),
                $saved['prepared_version'],
                $at,
                'Fictional receipt',
                $delivery['review_token']
            );
            self::fail('An intervening policy change committed delivery.');
        } catch (ConcurrentModificationException | ValidationException) {
            self::assertSame([], $t->sis->sql->rows('sis_transcript_deliveries'));
            self::assertSame('ready', $t->repository->request($request)['status']);
            self::assertSame(0, $this->repository($t)->current()->revision);
        }
    }

    public function testStoredPolicyRejectsTransplantAndEvidenceRejectsActorOrStageMismatch(): void
    {
        $t = new TranscriptFixture();
        $check = $this->check();
        $policy = $this->policyService($t)->save(0, [$check]);
        $vo = new TranscriptReleasePolicy($policy['revision'], $policy['checks']);
        $at = $t->sis->clock->now()->format(DATE_ATOM);
        $evidence = $vo->evidence(
            'preparation',
            $t->sis->access->actor,
            $at,
            $this->confirmation($policy, 'preparation')
        );
        TranscriptReleasePolicy::validateEvidence($evidence, 'preparation', $t->sis->access->actor->value, $at);
        try {
            TranscriptReleasePolicy::validateEvidence($evidence, 'delivery', $t->sis->access->actor->value, $at);
            self::fail('An attestation was reused for another stage.');
        } catch (\RuntimeException) {
            self::assertTrue(true);
        }
        $packet = $t->campus->cipher->seal(json_encode(['kind' => 'different-purpose',
            'id' => TranscriptReleasePolicyRepository::POLICY_ID] + $policy, JSON_THROW_ON_ERROR));
        $t->sis->sql->update(
            'sis_transcript_policy',
            ['encrypted_definition' => $packet],
            ['id' => TranscriptReleasePolicyRepository::POLICY_ID]
        );
        $this->expectException(\RuntimeException::class);
        $this->repository($t)->current();
    }

    public function testChangingPolicyAfterDeliveryReviewRequiresFreshConfirmation(): void
    {
        $t = new TranscriptFixture();
        $request = $t->request();
        $service = $t->service(renderer: $this->renderer());
        $review = $service->review($request);
        $document = new EntityId($review['operation_id']);
        $saved = $service->prepare($request, $document, $review['revision'], true, $review['review_token']);
        $at = $t->sis->clock->now();
        $confirmed = new TranscriptReleaseConfirmation(0, []);
        $delivery = $service->reviewDelivery(
            $request,
            $document,
            $saved['prepared_version'],
            $at,
            'Fictional receipt',
            $confirmed
        );
        $this->policyService($t)->save(0, [$this->check('delivery')]);
        try {
            $service->deliver(
                $request,
                $document,
                new EntityId($delivery['operation_id']),
                $saved['prepared_version'],
                $at,
                'Fictional receipt',
                $delivery['review_token'],
                $confirmed
            );
            self::fail('An earlier delivery review bypassed revised checks.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $t->sis->sql->rows('sis_transcript_deliveries'));
            self::assertFalse($service->view($request, $document)['academic_changed']);
        }
    }

    public function testEarlierProtectedArtifactsRemainReadableWithoutInventedConfirmations(): void
    {
        $t = new TranscriptFixture();
        $request = $t->request();
        $service = $t->service(renderer: $this->renderer());
        $review = $service->review($request);
        $document = new EntityId($review['operation_id']);
        $saved = $service->prepare($request, $document, $review['revision'], true, $review['review_token']);
        $html = $service->html($request, $document);
        $at = $t->sis->clock->now();
        $delivery = $service->reviewDelivery($request, $document, $saved['prepared_version'], $at, 'Fictional receipt');
        $operation = new EntityId($delivery['operation_id']);
        $result = $service->deliver(
            $request,
            $document,
            $operation,
            $saved['prepared_version'],
            $at,
            'Fictional receipt',
            $delivery['review_token']
        );
        // Represent the actual earlier artifact shape, which has no check-capture field.
        foreach (
            [
            ['sis_transcript_documents', $document], ['sis_transcript_deliveries', $operation],
            ] as [$table, $id]
        ) {
            $row = $t->sis->sql->one($table, ['id' => $id->value]);
            $payload = json_decode(
                $t->campus->cipher->open($row['encrypted_content']),
                true,
                flags: JSON_THROW_ON_ERROR
            );
            unset($payload['release_review'], $payload['request']['release_confirmation']);
            $t->sis->sql->update(
                $table,
                ['encrypted_content' => $t->campus->cipher->seal(json_encode($payload, JSON_THROW_ON_ERROR))],
                ['id' => $id->value]
            );
        }
        $this->policyService($t)->save(0, [$this->check()]);
        $view = $service->view($request, $document);
        self::assertNull($view['preparation_release']);
        self::assertNull($view['delivery_release']);
        self::assertSame($html, $service->html($request, $document));
        self::assertSame($saved, $service->prepare($request, $document, $review['revision'], true, ''));
        self::assertSame($result, $service->deliver(
            $request,
            $document,
            $operation,
            $saved['prepared_version'],
            $at,
            'Fictional receipt',
            ''
        ));
    }

    public function testPolicyAuthorityLossDuringAuditRollsBackSetup(): void
    {
        $t = new TranscriptFixture();
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($t): void {
            $t->sis->access->staff = false;
            $t->sis->access->grants = ['sis:transcript:manage', 'sis:student:read'];
        });
        try {
            $this->policyService($t, $audit)->save(0, [$this->check()]);
            self::fail('Policy setup committed after its authority was lost.');
        } catch (AccessDeniedException) {
            self::assertSame(0, $this->repository($t)->current()->revision);
        }
    }

    public function testPortableMigrationSeedsOneProtectedIndependentPermissionAndPreservesHistory(): void
    {
        $t = new TranscriptFixture();
        $definitions = [];
        $schema = $this->createStub(Schema::class);
        $schema->method('hasTable')->willReturn(false);
        $schema->method('create')->willReturnCallback(static function (
            string $name,
            callable $callback,
        ) use (&$definitions): void {
            $definition = new CreateTable($name);
            $callback($definition);
            $definitions[] = $definition;
        });
        $connection = $this->createStub(Connection::class);
        $connection->method('getSchema')->willReturn($schema);
        $connection->method('queryBuilder')->willReturn($t->sis->connection->queryBuilder());
        $migration = new \CreateSisTranscriptReleasePolicy('transcript-policy-compiler');
        $migration->setObjectMap(new ArrayObject([
            'phpmig.adapter' => new DbalMigrationAdapter($connection, 'fixture'),
        ]));
        $before = $t->sis->sql->rows('sis_history_events');
        $migration->up();
        self::assertCount(1, $definitions);
        self::assertSame('sis_transcript_policy', $definitions[0]->getTableName());
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $driver) {
            $dialect = $this->createStub(Connection::class);
            $dialect->method('quoteIdentifier')->willReturnCallback(
                static fn(string $name): string => '"' . $name . '"'
            );
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $driver;
            $sql = json_encode(new $compiler($dialect)->create($definitions[0]), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('encrypted_definition', $sql);
            self::assertStringNotContainsString('sis_history', $sql);
        }
        $permission = $t->sis->sql->one('sis_access_permissions', ['name' => 'sis:transcript:policy']);
        self::assertSame(1, (int) $permission['is_system']);
        self::assertSame([], $t->sis->sql->rows('sis_access_role_permissions', ['permission_id' => $permission['id']]));
        self::assertSame([], $t->sis->sql->rows('sis_access_user_permissions', ['permission_id' => $permission['id']]));
        self::assertSame($before, $t->sis->sql->rows('sis_history_events'));
    }
}
