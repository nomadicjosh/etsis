<?php

declare(strict_types=1);

namespace Domain\Reporting\Service;

use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Reporting\Repository\RelationalReportRepository;
use Domain\Reporting\Repository\RelationalReportSource;
use Domain\Reporting\Repository\ReportCaptureSource;
use Domain\Reporting\Repository\ReportLifecycleRepository;
use Domain\Reporting\Repository\ReportRepository;
use Domain\Reporting\StudentReport;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use JsonException;

/** Report results are retained private artifacts; their expiry never changes academic event history. */
final readonly class ReportLifecycleService
{
    public function __construct(
        private ReportRepository $reports,
        private RelationalReportRepository $definitions,
        private RelationalReportSource $source,
        private ReportCaptureSource $capture,
        private ReportLifecycleRepository $storage,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** @return array<string,mixed> */
    public function view(EntityId $id): array
    {
        $actor = $this->manage($id);
        $report = $this->reports->get($id);
        $definition = $this->definitions->get($id);
        $result = ['name' => $report->name, 'version' => $definition === null ? -1 : (int) $definition['version'],
            'lifecycle' => $this->describe($id)];
        $this->manage($id, $actor);
        return $result;
    }

    public function isStatic(EntityId $id): bool
    {
        return ($this->storage->get($id)['mode'] ?? 'dynamic') === 'static';
    }

    public function assertEditable(EntityId $id): void
    {
        if ($this->isStatic($id)) {
            self::invalid('A static report keeps its captured results. '
                . 'Create a new report from its definition to change it.');
        }
    }

    /**
     * Internal summary after the calling report reader has authorized access.
     * @return array<string,mixed>
     * @throws JsonException
     */
    public function describe(EntityId $id): array
    {
        $state = $this->storage->get($id);
        $manifest = ($state['mode'] ?? 'dynamic') === 'static' ? $this->manifest($id, $state) : null;
        return ['mode' => $state['mode'] ?? 'dynamic', 'expires_at' => $state['expires_at'] ?? null,
            'captured_at' => $state['captured_at'] ?? null, 'row_count' => $manifest['row_count'] ?? null];
    }

    /**
     * Configure expiry, or make one complete immutable capture. Reads and external queries precede persistence.
     * Nullable days is a required choice: null means retain until explicitly changed by the owner.
     * @return array<string,mixed>
     * @throws JsonException
     */
    public function configure(EntityId $id, EntityId $operation, int $version, string $mode, ?int $days): array
    {
        if (!in_array($mode, ['dynamic', 'static'], true) || ($days !== null && ($days < 1 || $days > 3650))) {
            self::invalid('Choose dynamic or static results and an optional expiry of 1–3,650 days.');
        }
        $actor = $this->manage($id);
        $request = ['kind' => 'report-settings', 'version' => $version, 'mode' => $mode, 'days' => $days];
        if (($previous = $this->receipt($id, $operation, $actor, $request)) !== null) {
            return $previous;
        }
        $report = $this->reports->get($id);
        $initial = $this->definitions->get($id);
        if (($initial === null ? -1 : (int) $initial['version']) !== $version) {
            throw new ConcurrentModificationException('This report changed. Reload its settings.');
        }
        $state = $this->storage->get($id);
        if (($state['mode'] ?? 'dynamic') === 'static' && $mode !== 'static') {
            self::invalid('A static report stays fixed. Create a new dynamic report from its definition.');
        }
        $query = $this->query($report, $initial);
        $captured = null;
        if ($mode === 'static' && ($state['mode'] ?? 'dynamic') !== 'static') {
            $this->read($id, $actor);
            $captured = $this->capture->capture($query);
        }
        return $this->transactions->run(function () use (
            $id,
            $operation,
            $version,
            $mode,
            $days,
            $actor,
            $request,
            $report,
            $query,
            $captured
        ): array {
            $this->reports->lock($id);
            $this->manage($id, $actor);
            if (($previous = $this->receipt($id, $operation, $actor, $request)) !== null) {
                return $previous;
            }
            $current = $this->definitions->get($id);
            if (($current === null ? -1 : (int) $current['version']) !== $version) {
                throw new ConcurrentModificationException(
                    'This report changed while its results were captured. Reload it.'
                );
            }
            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
            $state = $this->storage->get($id);
            if ($mode === 'static') {
                $this->read($id, $actor);
            }
            $values = ['mode' => $mode, 'expires_at' => $days === null ? null :
                $now->modify('+' . $days . ' days')->format(DATE_ATOM)];
            if ($captured !== null) {
                $capture = EntityId::generate();
                $pages = $hashes = [];
                foreach (array_chunk($captured->rows, 50) as $index => $rows) {
                    $packet = json_encode(['report_id' => $id->value, 'capture_id' => $capture->value,
                        'page' => $index, 'rows' => $rows], JSON_THROW_ON_ERROR);
                    $hashes[] = hash('sha256', $packet);
                    $pages[] = $this->cipher->seal($packet);
                }
                $manifest = ['report_id' => $id->value, 'capture_id' => $capture->value,
                    'columns' => $captured->columns, 'row_count' => count($captured->rows), 'pages' => $hashes,
                    'captured_at' => $now->format(DATE_ATOM), 'definition_version' => $version + 1,
                    'grouped' => array_any(
                        $query->definition['columns'],
                        static fn(array $c): bool => $c['aggregate'] !== 'none'
                    )];
                $values += ['capture_id' => $capture->value, 'captured_at' => $now->format(DATE_ATOM),
                    'encrypted_manifest' => $this->cipher->seal(json_encode($manifest, JSON_THROW_ON_ERROR))];
                $this->storage->savePages($id, $pages);
            } elseif ($mode === 'static') {
                $this->manifest($id, $state ?? throw new \RuntimeException('Missing static capture.'));
            }
            $this->storage->save($id, $values);
            // Legacy guided reports join the current definition model on an explicit settings change only.
            $this->definitions->save(
                $id,
                $report->name,
                $current['encrypted_content'] ??
                    $this->cipher->seal(json_encode($query->definition, JSON_THROW_ON_ERROR)),
                $version
            );
            $result = ['id' => $id->value, 'version' => $version + 1, 'lifecycle' => $this->describe($id)];
            $this->definitions->record(
                $operation,
                $id,
                $actor,
                $version + 1,
                $this->cipher->seal(json_encode(['request' => $request, 'result' => $result], JSON_THROW_ON_ERROR)),
                $now->format(DATE_ATOM)
            );
            $this->audit->record($actor, 'report.settings_changed', $id);
            $this->manage($id, $actor);
            $this->grants($query);
            if ($mode === 'static') {
                $this->read($id, $actor);
            }
            return $result;
        });
    }

    /**
     * One authorized fixed result page; source data is never re-executed here.
     * @return array<string,mixed>
     */
    public function frozenPage(EntityId $id, int $offset): array
    {
        $actor = $this->read($id);
        return $this->transactions->run(function () use ($id, $offset, $actor): array {
            $this->reports->lock($id);
            $this->read($id, $actor);
            return $this->frozenRead($id, $offset, $actor);
        });
    }
    /** @return array<string,mixed>
     * @throws JsonException
     */
    private function frozenRead(EntityId $id, int $offset, EntityId $actor): array
    {
        if ($offset < 0 || $offset % 50 !== 0) {
            self::invalid('Choose a valid static result page.');
        }
        $state = $this->storage->get($id) ?? throw new RecordNotFoundException('Static report not found.');
        $manifest = $this->manifest($id, $state);
        $index = intdiv($offset, 50);
        $rows = [];
        if (isset($manifest['pages'][$index])) {
            $encrypted = $this->storage->page($id, $index) ??
            throw new \RuntimeException('Static report page is missing.');
            $packet = $this->cipher->open($encrypted);
            if (!hash_equals($manifest['pages'][$index], hash('sha256', $packet))) {
                throw new \RuntimeException('Static report page could not be verified.');
            }
            $data = json_decode($packet, true, 12, JSON_THROW_ON_ERROR);
            if (
                $data['report_id'] !== $id->value || $data['capture_id'] !== $manifest['capture_id'] ||
                $data['page'] !== $index || !is_array($data['rows']) ||
                count($data['rows']) !== min(50, $manifest['row_count'] - $offset)
            ) {
                throw new \RuntimeException('Static report page does not match its capture.');
            }
            $rows = $data['rows'];
        } elseif ($offset < $manifest['row_count']) {
            throw new \RuntimeException('Static report page is missing.');
        }
        $this->audit->record($actor, 'report.static_read', $id);
        $this->read($id, $actor);
        return ['columns' => $manifest['columns'], 'items' => $rows, 'offset' => $offset,
            'next_offset' => $offset + 50 < $manifest['row_count'] ? $offset + 50 : null,
            'limit' => 50, 'grouped' => $manifest['grouped'], 'lifecycle' => $this->describe($id)];
    }

    /**
     * @param array<string,mixed>|null $record
     * @throws JsonException
     */
    private function query(StudentReport $report, ?array $record): ReportQuery
    {
        if ($record !== null) {
            $definition = json_decode(
                $this->cipher->open($record['encrypted_content']),
                true,
                32,
                JSON_THROW_ON_ERROR
            );
        } else {
            $rules = [];
            foreach (
                ['primary_program_id' => $report->programId?->value,
                'institutional_entry_term_id' => $report->termId?->value,
                'active' => $report->active] as $field => $value
            ) {
                if ($value !== null) {
                    $rules[] = ['id' => $field, 'operator' => 'equal', 'value' => $value];
                }
            }
            $definition = ['dataset' => 'students', 'columns' => [
                ['field' => 'institutional_id', 'label' => 'Institutional ID', 'aggregate' => 'none'],
                ['field' => 'program_code', 'label' => 'Primary program', 'aggregate' => 'none'],
                ['field' => 'entry_term', 'label' => 'Institutional entry term', 'aggregate' => 'none'],
                ['field' => 'active', 'label' => 'Active student', 'aggregate' => 'none'],
            ], 'joins' => [], 'filters' => ['condition' => 'AND', 'rules' => $rules],
                'having' => null, 'sort' => [], 'distinct' => false];
        }
        $schema = $this->source->datasets()[$definition['dataset']] ??
        throw new \RuntimeException('Unknown report source.');
        $query = new ReportQuery($definition, $schema);
        $this->grants($query);
        return $query;
    }

    private function grants(ReportQuery $query): void
    {
        foreach ($query->permissions() as $permission) {
            $this->access->requirePermission($permission);
        }
    }
    private function manage(EntityId $id, ?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission('sis:reporting:manage');
        $report = $this->reports->get($id);
        $this->access->requireOwnerOrPermission($report->ownerUserId, 'sis:reporting:admin');
        if ($expected !== null && $actor->value !== $expected->value) {
            throw new AccessDeniedException('The report owner changed.');
        }
        return $actor;
    }
    private function read(EntityId $id, ?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission('sis:reporting:run');
        $report = $this->reports->get($id);
        if ($actor->value !== $report->ownerUserId->value && !$this->reports->isSharedWith($id, $actor)) {
            $this->access->requirePermission('sis:reporting:admin');
        }
        $this->query($report, $this->definitions->get($id));
        if ($expected !== null && $actor->value !== $expected->value) {
            throw new AccessDeniedException('The report reader changed.');
        }
        return $actor;
    }
    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     * @throws JsonException
     */
    private function manifest(EntityId $id, array $state): array
    {
        $data = json_decode($this->cipher->open($state['encrypted_manifest'] ?? ''), true, 12, JSON_THROW_ON_ERROR);
        if (
            !is_array($data) || ($state['mode'] ?? null) !== 'static' || ($data['report_id'] ?? null) !== $id->value ||
            ($data['capture_id'] ?? null) !== $state['capture_id'] || !is_array($data['columns'] ?? null) ||
            !is_int($data['row_count'] ?? null) || $data['row_count'] < 0 || $data['row_count'] > 5000 ||
            !is_bool($data['grouped'] ?? null) || ($data['captured_at'] ?? null) !== $state['captured_at'] ||
            !is_int($data['definition_version'] ?? null) ||
            !is_array($data['pages'] ?? null) || count($data['pages']) !== (int) ceil($data['row_count'] / 50)
        ) {
            throw new \RuntimeException('Static report capture could not be verified.');
        }
        return $data;
    }
    /** @param array<string,mixed> $request
     * @return array<string,mixed>|null
     * @throws JsonException
     */
    private function receipt(EntityId $id, EntityId $operation, EntityId $actor, array $request): ?array
    {
        $previous = $this->definitions->operation($operation);
        if ($previous === null) {
            return null;
        }
        $packet = json_decode($this->cipher->open($previous['encrypted_content']), true, 12, JSON_THROW_ON_ERROR);
        if (
            $previous['report_id'] !== $id->value || $previous['actor_id'] !== $actor->value ||
            ($packet['request'] ?? null) !== $request || !is_array($packet['result'] ?? null)
        ) {
            throw new ConcurrentModificationException('This operation belongs to another report change.');
        }
        $this->manage($id, $actor);
        $this->query($this->reports->get($id), $this->definitions->get($id));
        if ($request['mode'] === 'static') {
            $this->read($id, $actor);
        }
        return $packet['result'];
    }
    private static function invalid(string $message): never
    {
        throw new ValidationException([['field' => 'report', 'message' => $message]]);
    }
}
