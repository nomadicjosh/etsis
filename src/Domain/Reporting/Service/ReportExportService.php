<?php

declare(strict_types=1);

namespace Domain\Reporting\Service;

use DateTimeZone;
use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Reporting\Repository\RelationalReportRepository;
use Domain\Reporting\Repository\RelationalReportSource;
use Domain\Reporting\Repository\ReportExportCapture;
use Domain\Reporting\Repository\ReportExportRepository;
use Domain\Reporting\Repository\ReportLifecycleRepository;
use Domain\Reporting\Repository\ReportRepository;
use Domain\Reporting\ValueObject\ReportExportArtifact;
use Domain\Reporting\ValueObject\ReportExportDownload;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Throwable;

/** One owner-bound export workflow. Working jobs/results remain CRUD, never domain events. */
final readonly class ReportExportService
{
    public function __construct(
        private ReportRepository $reports,
        private RelationalReportRepository $definitions,
        private RelationalReportSource $source,
        private ReportLifecycleRepository $lifecycles,
        private ReportExportRepository $jobs,
        private ReportExportCapture $capture,
        private StudentAccess $access,
        private AccessRepository $permissions,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** @return array<string,mixed> */
    public function request(EntityId $report, EntityId $operation, int $version): array
    {
        $actor = $this->access->authenticatedUserId();
        return $this->transactions->run(function () use ($report, $operation, $version, $actor): array {
            $this->reports->lock($report);
            $context = $this->authorize($report, $actor);
            $previous = $this->jobs->get($operation);
            if ($previous !== null) {
                $this->owned($previous, $report, $actor);
                if ((int) $previous['definition_version'] !== $version) {
                    throw new ConcurrentModificationException('This request belongs to another report export.');
                }
                return $this->describe($previous);
            }
            if ($context['version'] !== $version) {
                throw new ConcurrentModificationException('This report changed. Reload it before exporting.');
            }
            $latest = $this->jobs->latest($report, $actor);
            if (
                $latest !== null && $latest['expires_at'] > $this->now()
                && in_array($latest['status'], ['queued', 'running'], true)
            ) {
                return $this->describe($latest);
            }
            if ($latest !== null && $latest['status'] === 'ready') {
                $old = new EntityId($latest['id']);
                $this->jobs->lock($old);
                $this->jobs->clear($old);
                $this->jobs->update($old, ['status' => 'superseded', 'encrypted_manifest' => null,
                    'row_count' => null, 'byte_count' => null]);
            }
            // Serialize per report: repeated clicks never create a parallel queue of the same work.
            $job = ['id' => $operation->value, 'report_id' => $report->value, 'actor_id' => $actor->value,
                'definition_version' => $version, 'capture_id' => $context['static']['capture_id'] ?? null,
                'status' => 'queued', 'attempts' => 0, 'created_at' => $this->now(),
                'expires_at' => $this->clock->now()->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))
                    ->format(DATE_ATOM)];
            $this->jobs->add($job);
            $this->audit->record($actor, 'report.export_requested', $operation);
            $this->authorize($report, $actor, $job);
            return $this->describe($job);
        });
    }

    /** @return array<string,mixed>|null */
    public function latest(EntityId $report): ?array
    {
        $actor = $this->access->authenticatedUserId();
        $this->authorize($report, $actor);
        $job = $this->jobs->latest($report, $actor);
        $this->authorize($report, $actor);
        return $job === null || $job['expires_at'] <= $this->now() ? null : $this->describe($job);
    }

    /** @return array<string,mixed> */
    public function cancel(EntityId $report, EntityId $id): array
    {
        $actor = $this->access->authenticatedUserId();
        return $this->transactions->run(function () use ($report, $id, $actor): array {
            $this->reports->lock($report);
            $this->authorize($report, $actor);
            $this->jobs->lock($id);
            $job = $this->jobs->get($id) ?? throw new RecordNotFoundException('Export not found.');
            $this->owned($job, $report, $actor);
            if ($job['status'] !== 'cancelled') {
                $this->jobs->clear($id);
                $this->jobs->update($id, ['status' => 'cancelled', 'lease_id' => null, 'lease_until' => null,
                    'encrypted_manifest' => null, 'row_count' => null, 'byte_count' => null]);
                $this->audit->record($actor, 'report.export_cancelled', $id);
            }
            $this->authorize($report, $actor);
            return $this->describe($this->jobs->get($id));
        });
    }

    public function download(EntityId $report, EntityId $id): ReportExportDownload
    {
        $actor = $this->access->authenticatedUserId();
        $job = $this->jobs->get($id) ?? throw new RecordNotFoundException('Export not found.');
        $this->owned($job, $report, $actor);
        $this->authorize($report, $actor, $job);
        if ($job['status'] !== 'ready') {
            throw new RecordNotFoundException('This export is not ready or has expired.');
        }
        $manifest = json_decode($this->cipher->open($job['encrypted_manifest'] ?? ''), true, 8, JSON_THROW_ON_ERROR);
        if (
            ($manifest['job_id'] ?? null) !== $id->value || ($manifest['report_id'] ?? null) !== $report->value ||
            ($manifest['actor_id'] ?? null) !== $actor->value ||
            ($manifest['definition_version'] ?? null) !== (int) $job['definition_version'] ||
            ($manifest['capture_id'] ?? null) !== $job['capture_id'] ||
            !is_int($manifest['bytes'] ?? null) || $manifest['bytes'] < 1 ||
            $manifest['bytes'] > ReportExportArtifact::MAX_BYTES ||
            ($manifest['rows'] ?? null) !== (int) $job['row_count'] ||
            $manifest['rows'] < 0 || $manifest['rows'] > ReportExportArtifact::MAX_ROWS ||
            $manifest['bytes'] !== (int) $job['byte_count'] ||
            !is_array($manifest['chunks'] ?? null) || !array_is_list($manifest['chunks']) ||
            count($manifest['chunks']) < 1 || count($manifest['chunks']) > 501
        ) {
            throw new \RuntimeException('Report export could not be verified.');
        }
        $chunks = [];
        $bytes = 0;
        foreach ($manifest['chunks'] as $number => $hash) {
            $encrypted = $this->jobs->chunk($id, $number) ?? throw new \RuntimeException('Export chunk missing.');
            $packet = $this->cipher->open($encrypted);
            if (!is_string($hash) || !hash_equals($hash, hash('sha256', $packet))) {
                throw new \RuntimeException('Export chunk could not be verified.');
            }
            $data = json_decode($packet, true, 8, JSON_THROW_ON_ERROR);
            if (
                ($data['job_id'] ?? null) !== $id->value || ($data['report_id'] ?? null) !== $report->value ||
                ($data['chunk'] ?? null) !== $number || !is_string($data['content'] ?? null)
            ) {
                throw new \RuntimeException('Export chunk has a different scope.');
            }
            $bytes += strlen($data['content']);
            if ($bytes > $manifest['bytes']) {
                throw new \RuntimeException('Export length could not be verified.');
            }
            $chunks[] = $data['content'];
        }
        if ($bytes !== $manifest['bytes']) {
            throw new \RuntimeException('Export is incomplete.');
        }
        $this->audit->record($actor, 'report.export_downloaded', $id);
        $this->authorize($report, $actor, $job);
        $after = $this->jobs->get($id);
        if (
            $after === null || $after['status'] !== 'ready' ||
            $after['encrypted_manifest'] !== $job['encrypted_manifest']
        ) {
            throw new ConcurrentModificationException('This export is no longer available.');
        }
        return new ReportExportDownload($chunks, $bytes);
    }

    /** Trusted deployment worker. Does not create a session or use browser authority.
     * @return array{examined:int,ready:int,failed:int,apply:bool} */
    public function work(bool $apply = false, int $limit = 1): array
    {
        if ($limit < 1 || $limit > 5) {
            throw new \InvalidArgumentException('Use an export work limit of 1–5.');
        }
        $ids = $this->jobs->pending($this->now(), $limit);
        $ready = $failed = 0;
        if ($apply) {
            foreach ($ids as $value) {
                $id = new EntityId($value);
                $lease = EntityId::generate();
                try {
                    $job = $this->claim($id, $lease);
                } catch (RecordNotFoundException) {
                    continue;
                }
                if ($job === null) {
                    continue;
                }
                if ($job['status'] === 'failed') {
                    $failed++;
                    continue;
                }
                try {
                    $report = new EntityId($job['report_id']);
                    $actor = new EntityId($job['actor_id']);
                    $context = $this->authorize($report, $actor, $job);
                    $artifact = $this->capture->capture($id, $report, $context['query'], $context['static']);
                    $ready += $this->publish($id, $lease, $job, $artifact) ? 1 : 0;
                } catch (Throwable) {
                    // Fixed state only: no SQL, reasons, result cells or upstream exception details in jobs/logs.
                    $failed += $this->fail($id, $lease);
                }
            }
        }
        return ['examined' => count($ids), 'ready' => $ready, 'failed' => $failed, 'apply' => $apply];
    }

    private function fail(EntityId $id, EntityId $lease): int
    {
        try {
            return $this->transactions->run(function () use ($id, $lease): int {
                $this->jobs->lock($id);
                $current = $this->jobs->get($id);
                if ($current === null || $current['status'] !== 'running' || $current['lease_id'] !== $lease->value) {
                    return 0;
                }
                $this->jobs->clear($id);
                return (int) $this->jobs->update($id, ['status' => 'failed', 'lease_id' => null,
                    'lease_until' => null, 'completed_at' => $this->now(), 'encrypted_manifest' => null]);
            });
        } catch (RecordNotFoundException) {
            return 0;
        }
    }

    /** @return array<string,mixed>|null */
    private function claim(EntityId $id, EntityId $lease): ?array
    {
        return $this->transactions->run(function () use ($id, $lease): ?array {
            $this->jobs->lock($id);
            $job = $this->jobs->get($id);
            if (
                $job === null || $job['expires_at'] <= $this->now() ||
                ($job['status'] !== 'queued' && !($job['status'] === 'running' && $job['lease_until'] <= $this->now()))
            ) {
                return null;
            }
            if ((int) $job['attempts'] >= 3) {
                $this->jobs->update($id, ['status' => 'failed', 'lease_id' => null, 'lease_until' => null]);
                return $this->jobs->get($id);
            }
            $this->jobs->update($id, ['status' => 'running', 'lease_id' => $lease->value,
                'lease_until' => $this->clock->now()->modify('+2 minutes')->setTimezone(new DateTimeZone('UTC'))
                    ->format(DATE_ATOM), 'attempts' => (int) $job['attempts'] + 1, 'started_at' => $this->now()]);
            return $this->jobs->get($id);
        });
    }

    /** @param array<string,mixed> $job */
    private function publish(EntityId $id, EntityId $lease, array $job, ReportExportArtifact $artifact): bool
    {
        return $this->transactions->run(function () use ($id, $lease, $job, $artifact): bool {
            $report = new EntityId($job['report_id']);
            $actor = new EntityId($job['actor_id']);
            $this->reports->lock($report);
            $this->jobs->lock($id);
            $current = $this->jobs->get($id);
            if (
                $current === null || $current['status'] !== 'running' || $current['lease_id'] !== $lease->value ||
                $current['lease_until'] <= $this->now()
            ) {
                return false;
            }
            $this->authorize($report, $actor, $current);
            $this->jobs->saveChunks($id, $artifact->chunks);
            $manifest = $this->cipher->seal(json_encode(['job_id' => $id->value, 'report_id' => $report->value,
                'actor_id' => $actor->value, 'definition_version' => (int) $job['definition_version'],
                'capture_id' => $job['capture_id'], 'rows' => $artifact->rows, 'bytes' => $artifact->bytes,
                'chunks' => $artifact->hashes], JSON_THROW_ON_ERROR));
            $this->jobs->update($id, ['status' => 'ready', 'lease_id' => null, 'lease_until' => null,
                'row_count' => $artifact->rows, 'byte_count' => $artifact->bytes,
                'encrypted_manifest' => $manifest, 'completed_at' => $this->now()]);
            $this->audit->record($actor, 'report.export_prepared', $id);
            $this->authorize($report, $actor, $current);
            return true;
        });
    }

    /** @param array<string,mixed>|null $expected
     * @return array{query:ReportQuery,version:int,static:array<string,mixed>|null} */
    private function authorize(EntityId $report, EntityId $actor, ?array $expected = null): array
    {
        $saved = $this->reports->get($report);
        if (
            !$this->permissions->allows($actor->value, 'sis:reporting:run') ||
            ($saved->ownerUserId->value !== $actor->value &&
            !$this->reports->isSharedWith($report, $actor) &&
                !$this->permissions->allows($actor->value, 'sis:reporting:admin'))
        ) {
            throw new AccessDeniedException('Report access is no longer available.');
        }
        $definition = $this->definitions->get($report) ?? throw new RecordNotFoundException('Saved report not found.');
        $data = json_decode($this->cipher->open($definition['encrypted_content']), true, 32, JSON_THROW_ON_ERROR);
        $schema = $this->source->datasets()[$data['dataset'] ?? ''] ??
        throw new RecordNotFoundException('Report source not found.');
        $query = new ReportQuery($data, $schema);
        if ($schema['financial_as_of'] ?? false) {
            new \Domain\Reporting\ValueObject\ReportFinancialCutoff($data['financial_as_of'])
                ->requireRecordedBy($this->clock->now());
        }
        foreach ($query->permissions() as $permission) {
            if (!$this->permissions->allows($actor->value, $permission)) {
                throw new AccessDeniedException('Report source access is no longer available.');
            }
        }
        $state = $this->lifecycles->get($report);
        $static = ($state['mode'] ?? 'dynamic') === 'static' ? $state : null;
        if (
            $expected !== null && ($expected['expires_at'] <= $this->now() ||
            (int) $expected['definition_version'] !== (int) $definition['version'] ||
            $expected['capture_id'] !== ($static['capture_id'] ?? null))
        ) {
            throw new ConcurrentModificationException('This export expired or its saved report changed. Start again.');
        }
        return ['query' => $query, 'version' => (int) $definition['version'], 'static' => $static];
    }

    /** @param array<string,mixed> $job */
    private function owned(array $job, EntityId $report, EntityId $actor): void
    {
        if ($job['report_id'] !== $report->value || $job['actor_id'] !== $actor->value) {
            throw new AccessDeniedException('This private export belongs to another account.');
        }
    }

    /** @param array<string,mixed> $job
     * @return array<string,mixed> */
    private function describe(array $job): array
    {
        return ['id' => $job['id'], 'status' => $job['status'], 'created_at' => $job['created_at'],
            'expires_at' => $job['expires_at'], 'started_at' => $job['started_at'] ?? null,
            'completed_at' => $job['completed_at'] ?? null,
            'rows' => isset($job['row_count']) ? (int) $job['row_count'] : null];
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
