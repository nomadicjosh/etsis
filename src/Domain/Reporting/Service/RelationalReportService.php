<?php

declare(strict_types=1);

namespace Domain\Reporting\Service;

use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Reporting\Enum\StudentPopulationAction;
use Domain\Reporting\Repository\RelationalReportRepository;
use Domain\Reporting\Repository\RelationalReportSource;
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

final readonly class RelationalReportService
{
    public function __construct(
        private ReportRepository $reports,
        private RelationalReportRepository $definitions,
        private RelationalReportSource $source,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
        private ReportLifecycleService $lifecycles,
    ) {
    }

    /** @return array<string, mixed> */
    public function catalog(): array
    {
        $this->access->requirePermission('sis:reporting:manage');
        $datasets = [];
        foreach ($this->source->datasets() as $dataset) {
            try {
                $datasets[] = $this->describeSource($dataset);
            } catch (AccessDeniedException) {
                continue;
            }
        }
        return ['datasets' => $datasets];
    }

    /** Metadata only: resolve named copies through the approved server registry.
     * @param array<string,mixed> $aliases
     * @return array<string,mixed>
     */
    public function graph(string $id, array $aliases): array
    {
        $actor = $this->access->requirePermission('sis:reporting:manage');
        $base = $this->source->datasets()[$id] ?? throw new RecordNotFoundException('Report source not found.');
        $schema = \Domain\Reporting\ValueObject\ReportGraph::expand($base, $aliases);
        foreach (array_keys($aliases) as $name) {
            $this->relationshipGrants($schema, $name);
        }
        $result = $this->describeSource($schema);
        $this->grants($schema['permissions']);
        foreach (array_keys($aliases) as $name) {
            $this->relationshipGrants($schema, $name);
        }
        if ($this->access->requirePermission('sis:reporting:manage')->value !== $actor->value) {
            throw new AccessDeniedException('The report author changed.');
        }
        return ['dataset' => $result];
    }

    /** @param array<string,mixed> $dataset */
    private function relationshipGrants(array $dataset, string $name): void
    {
        while ($name !== $dataset['root']) {
            $relation = $dataset['relationships'][$name];
            $this->grants($relation['permissions']);
            $name = $relation['parent'];
        }
    }

    /** @param array<string,mixed> $dataset
     * @return array<string,mixed>
     */
    private function describeSource(array $dataset): array
    {
        $this->grants($dataset['permissions']);
        $fields = [];
        foreach ($dataset['fields'] as $id => $field) {
            try {
                $this->grants($field['permissions']);
                $this->relationshipGrants($dataset, $field['relation']);
            } catch (AccessDeniedException) {
                continue;
            }
            $fields[$id] = array_intersect_key(
                $field,
                array_flip(['label', 'type', 'relation', 'copy_group', 'choices']),
            );
        }
        $relationships = [];
        foreach ($dataset['relationships'] as $id => $relation) {
            try {
                $this->relationshipGrants($dataset, $id);
            } catch (AccessDeniedException) {
                continue;
            }
            $relationships[$id] = [
                'label' => $relation['label'], 'parent' => $relation['parent'], 'many' => $relation['many'] ?? false,
            ] + array_intersect_key($relation, array_flip(['copy_of', 'copy_group']));
        }
        return ['id' => $dataset['id'], 'label' => $dataset['label'], 'description' => $dataset['description'],
            'fields' => $fields, 'relationships' => $relationships];
    }

    /** @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public function preview(array $definition, int $offset): array
    {
        $this->access->requirePermission("sis:reporting:manage");
        $actor = $this->access->requirePermission("sis:reporting:run");
        $query = $this->query($definition);
        $this->audit->record($actor, "report.draft_previewed", EntityId::generate());
        $result = $this->results($query, $offset);
        $this->access->requirePermission('sis:reporting:manage');
        $this->verifyReader($actor, $query);
        return $result;
    }

    /** @param array<string, mixed> $definition */
    public function save(EntityId $id, EntityId $operation, int $version, string $name, array $definition): void
    {
        $actor = $this->access->requirePermission("sis:reporting:manage");
        if (
            trim($name) === "" ||
            mb_strlen($name) > 191 ||
            !mb_check_encoding($name, "UTF-8") ||
            preg_match('/[\x00-\x1F\x7F]/', $name)
        ) {
            throw new ValidationException([["field" => "name", "message" => "Enter a valid report name."]]);
        }
        $this->query($definition);
        $payload = ["version" => $version, "name" => $name, "definition" => $definition];
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 100000) {
            throw new ValidationException([["field" => "report", "message" => "Reduce the report definition size."]]);
        }
        $this->transactions->run(function () use (
            $id,
            $operation,
            $version,
            $name,
            $actor,
            $definition,
            $payload,
            $encoded,
        ): void {
            if ($version >= 0) {
                $this->reports->lock($id);
                $report = $this->reports->get($id);
                $this->access->requireOwnerOrPermission($report->ownerUserId, "sis:reporting:admin");
            }
            $previous = $this->definitions->operation($operation);
            if ($previous !== null) {
                if (
                    $previous["report_id"] !== $id->value ||
                    $previous["actor_id"] !== $actor->value ||
                    json_decode($this->cipher->open($previous["encrypted_content"]), true, 32, JSON_THROW_ON_ERROR) !==
                        $payload
                ) {
                    throw new ConcurrentModificationException("The operation belongs to another report change.");
                }
                return;
            }
            if ($version === -1) {
                $this->reports->add(new StudentReport($id, $actor, $name));
            } else {
                $this->lifecycles->assertEditable($id);
                $current =
                    $this->definitions->get($id) ?? throw new RecordNotFoundException("Relational report not found.");
                if ((int) $current["version"] !== $version) {
                    throw new ConcurrentModificationException("Reload the report definition before saving.");
                }
            }
            $this->definitions->save(
                $id,
                $name,
                $this->cipher->seal(json_encode($definition, JSON_THROW_ON_ERROR)),
                $version,
            );
            $this->definitions->record(
                $operation,
                $id,
                $actor,
                $version + 1,
                $this->cipher->seal($encoded),
                $this->clock
                    ->now()
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format(DATE_ATOM),
            );
            $this->audit->record($actor, "report.definition_saved", $id);
        });
    }

    /** @return array<string, mixed> */
    public function view(EntityId $id): array
    {
        $actor = $this->access->authenticatedUserId();
        $report = $this->reports->get($id);
        $manage = false;
        try {
            $this->access->requirePermission("sis:reporting:manage");
            $this->access->requireOwnerOrPermission($report->ownerUserId, "sis:reporting:admin");
            $manage = true;
        } catch (AccessDeniedException) {
        }
        if (!$manage) {
            $this->canRun($id, $actor);
        }
        $record = $this->definitions->get($id) ?? throw new RecordNotFoundException("Relational report not found.");
        $data = json_decode($this->cipher->open($record["encrypted_content"]), true, 32, JSON_THROW_ON_ERROR);
        $this->query($data);
        return [
            "id" => $id->value,
            "name" => $report->name,
            "version" => (int) $record["version"],
            "definition" => $data,
            "can_manage" => $manage,
            "student_actions" => $this->lifecycles->isStatic($id) ? [] : $this->studentActions($data, $actor),
            "lifecycle" => $this->lifecycles->describe($id),
        ];
    }

    /**
     * @param array<string,mixed> $definition
     * @return list<string>
     */
    private function studentActions(array $definition, EntityId $actor): array
    {
        try {
            $query = $this->query($definition)->studentPopulation();
            $this->grants($query->permissions());
        } catch (AccessDeniedException | ValidationException) {
            return [];
        }
        $actions = [];
        foreach (StudentPopulationAction::cases() as $action) {
            try {
                $this->populationActor($action, $actor);
                $actions[] = $action->value;
            } catch (AccessDeniedException) {
            }
        }
        return $actions;
    }

    private function populationActor(StudentPopulationAction $action, ?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission('sis:reporting:run');
        foreach (['sis:student:read', ...$action->permissions()] as $permission) {
            if ($this->access->requirePermission($permission)->value !== $actor->value) {
                throw new AccessDeniedException('The report reviewer changed.');
            }
        }
        if ($expected !== null && $actor->value !== $expected->value) {
            throw new AccessDeniedException('The report reviewer changed.');
        }
        return $actor;
    }

    /** @return array<string,mixed> */
    public function population(EntityId $id, int $offset, StudentPopulationAction $action): array
    {
        return $this->populationResults($id, $action, $offset, null, null);
    }

    /** @param array<mixed> $students
     * @return array<string,mixed>
     */
    public function selectStudents(EntityId $id, array $students, int $version, StudentPopulationAction $action): array
    {
        return $this->populationResults($id, $action, 0, $students, $version);
    }

    /** @param array<mixed>|null $students
     * @return array<string,mixed>
     */
    private function populationResults(
        EntityId $id,
        StudentPopulationAction $action,
        int $offset,
        ?array $students,
        ?int $version,
    ): array {
        $actor = $this->populationActor($action);
        return $this->transactions->run(function () use ($id, $action, $offset, $students, $version, $actor): array {
            $this->reports->lock($id);
            $this->populationActor($action, $actor);
            $this->canRun($id, $actor);
            $this->lifecycles->assertEditable($id);
            $record = $this->definitions->get($id) ?? throw new RecordNotFoundException('Saved report not found.');
            if ($version !== null && $version !== (int) $record['version']) {
                throw new ConcurrentModificationException('This report changed. Start a new student selection.');
            }
            $original = $this->query(json_decode(
                $this->cipher->open($record['encrypted_content']),
                true,
                32,
                JSON_THROW_ON_ERROR
            ));
            $query = $original->studentPopulation($students);
            $this->grants($query->permissions());
            $result = $this->results($query, $offset);
            if ($students !== null && count($result['items']) !== count($students)) {
                throw new ConcurrentModificationException(
                    'Some selected students no longer match this report. Review the population again.'
                );
            }
            $this->audit->record(
                $actor,
                'report.population_' . $action->value . ($students === null ? '_read' : '_selected'),
                $id
            );
            $this->canRun($id, $actor);
            $this->grants($original->permissions());
            $this->grants($query->permissions());
            $this->populationActor($action, $actor);
            return ['items' => array_map(static fn(array $row): array => [
                'id' => $row['c0'], 'institutional_id' => $row['c1'], 'name' => $row['c2'], 'active' => $row['c3'],
            ], $result['items']), 'offset' => $result['offset'], 'next_offset' => $result['next_offset'],
                'limit' => 50, 'report_version' => (int) $record['version'], 'action' => $action->value];
        });
    }

    /** @return array<string, mixed> */
    public function run(EntityId $id, int $offset): array
    {
        $actor = $this->access->requirePermission("sis:reporting:run");
        return $this->transactions->run(function () use ($id, $offset, $actor): array {
            $this->reports->lock($id);
            $this->canRun($id, $actor);
            if ($this->lifecycles->isStatic($id)) {
                return $this->lifecycles->frozenPage($id, $offset);
            }
            $row = $this->definitions->get($id) ?? throw new RecordNotFoundException("Relational report not found.");
            $query = $this->query(
                json_decode($this->cipher->open($row["encrypted_content"]), true, 32, JSON_THROW_ON_ERROR),
            );
            $result = $this->results($query, $offset);
            $this->audit->record($actor, "report.relational_executed", $id);
            $this->canRun($id, $actor);
            $this->verifyReader($actor, $query);
            return $result;
        });
    }

    private function verifyReader(EntityId $actor, ReportQuery $query): void
    {
        $this->grants($query->permissions());
        if ($this->access->requirePermission('sis:reporting:run')->value !== $actor->value) {
            throw new AccessDeniedException('The report reader changed.');
        }
    }

    private function canRun(EntityId $id, EntityId $actor): void
    {
        $this->access->requirePermission("sis:reporting:run");
        $report = $this->reports->get($id);
        if ($report->ownerUserId->value !== $actor->value && !$this->reports->isSharedWith($id, $actor)) {
            $this->access->requirePermission("sis:reporting:admin");
        }
    }

    /** @return array<string,mixed> */
    public function reportSettings(EntityId $id): array
    {
        return $this->lifecycles->view($id);
    }
    /** @return array<string,mixed> */
    public function configureReport(EntityId $id, EntityId $operation, int $version, string $mode, ?int $days): array
    {
        return $this->lifecycles->configure($id, $operation, $version, $mode, $days);
    }

    /** @param array<string, mixed> $data */
    private function query(array $data): ReportQuery
    {
        $id = $data["dataset"] ?? null;
        if (!is_string($id) || !isset($this->source->datasets()[$id])) {
            throw new ValidationException([["field" => "dataset", "message" => "Choose a known dataset."]]);
        }
        $query = new ReportQuery($data, $this->source->datasets()[$id]);
        $this->grants($query->permissions());
        return $query;
    }

    /** @param list<string> $permissions */
    private function grants(array $permissions): void
    {
        foreach ($permissions as $permission) {
            $this->access->requirePermission($permission);
        }
    }

    /** @return array<string, mixed> */
    private function results(ReportQuery $query, int $offset): array
    {
        if ($offset < 0 || $offset > 2_000_000_000 || $offset % 50 !== 0) {
            throw new ValidationException([["field" => "offset", "message" => "Choose a valid result page."]]);
        }
        $rows = $this->source->execute($query, $offset);
        return [
            "columns" => array_map(static fn(array $column): string => $column["label"], $query->definition["columns"]),
            "items" => array_slice($rows, 0, 50),
            "offset" => $offset,
            "next_offset" => count($rows) > 50 ? $offset + 50 : null,
            "limit" => 50,
            "grouped" => array_any(
                $query->definition["columns"],
                static fn(array $c): bool => $c["aggregate"] !== "none",
            ),
        ];
    }
}
