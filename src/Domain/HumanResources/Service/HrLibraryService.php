<?php

declare(strict_types=1);

namespace Domain\HumanResources\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\HumanResources\Enum\HrLibraryKind;
use Domain\HumanResources\JobDefinition;
use Domain\HumanResources\PayGrade;
use Domain\HumanResources\Repository\HrLibraryRepository;
use Domain\HumanResources\ValueObject\DefinitionRules;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Money\Currencies\ISOCurrencies;

final readonly class HrLibraryService
{
    public function __construct(
        private HrLibraryRepository $rows,
        private StudentAccess $access,
        private AccessRepository $roles,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }
    /** @return array<string,mixed> */
    public function page(HrLibraryKind $kind, string $search = '', ?EntityId $after = null): array
    {
        $actor = $this->access->requirePermission('sis:hr:manage');
        if (
            !mb_check_encoding($search, 'UTF-8') || mb_strlen($search) > 100
            || preg_match('/[\x00-\x1f\x7f]/', $search)
        ) {
            DefinitionRules::invalid('search', 'Use a search of up to 100 characters.');
        }
        $rows = $this->rows->page($kind, trim($search), $after);
        $currencies = [];
        $iso = new ISOCurrencies();
        foreach ($iso as $currency) {
            $currencies[] = ['code' => $currency->getCode(), 'digits' => $iso->subunitFor($currency)];
        }
        $items = array_map(
            static fn(array $row): array => $row['value']->fields() + ['version' => $row['version']],
            array_slice($rows, 0, 50)
        );
        $this->authorize($actor);
        return ['items' => $items, 'next_after' => count($rows) > 50 ? $rows[49]['value']->id->value : null,
            'new_id' => EntityId::generate()->value, 'currencies' => $currencies];
    }
    /** @return array<string,mixed> */
    public function get(HrLibraryKind $kind, EntityId $id): array
    {
        $actor = $this->access->requirePermission('sis:hr:manage');
        $row = $this->rows->find($kind, $id) ?? throw new RecordNotFoundException('HR definition not found.');
        $this->authorize($actor);
        return $row['value']->fields() + ['version' => $row['version']];
    }
    /** @return array<string,mixed> */
    public function save(PayGrade|JobDefinition $value, ?int $expectedVersion): array
    {
        $actor = $this->access->requirePermission('sis:hr:manage');
        if ($expectedVersion !== null && ($expectedVersion < 0 || $expectedVersion >= 2_000_000_000)) {
            DefinitionRules::invalid('version', 'Reload the HR definition before editing.');
        }
        return $this->transactions->run(function () use ($value, $expectedVersion, $actor): array {
            // Library writes and permission changes use the existing access serialization lock.
            $this->roles->lock();
            $this->authorize($actor);
            $kind = $value instanceof PayGrade ? HrLibraryKind::PayGrades : HrLibraryKind::Jobs;
            if ($expectedVersion !== null) {
                $this->rows->lock($kind, $value->id);
            }
            $current = $this->rows->find($kind, $value->id);
            if ($expectedVersion === null && $current !== null) {
                DefinitionRules::invalid('id', 'That definition already exists. Open it to make changes.');
            }
            if ($expectedVersion !== null && ($current === null || $current['version'] !== $expectedVersion)) {
                throw new ConcurrentModificationException('Reload HR setup before saving.');
            }
            if ($value instanceof JobDefinition && $value->payGradeId !== null) {
                $grade = $this->rows->find(HrLibraryKind::PayGrades, $value->payGradeId)['value']
                    ?? throw new RecordNotFoundException('Pay grade not found.');
                if (!$grade instanceof PayGrade) {
                    throw new RecordNotFoundException('Pay grade not found.');
                }
                $value->validateGrade($grade);
            }
            if ($value instanceof PayGrade && $this->rows->conflicts($value)) {
                DefinitionRules::invalid(
                    $value->active ? 'linked_jobs' : 'active',
                    'Update or retire active linked jobs before changing this pay grade.'
                );
            }
            if ($current !== null && $current['value']->fields() === $value->fields()) {
                $this->authorize($actor);
                return $value->fields() + ['version' => $current['version']];
            }
            $this->rows->save($value, $expectedVersion);
            $this->audit->record(
                $actor,
                'hr.' . ($value instanceof PayGrade ? 'pay_grade' : 'job')
                . ($expectedVersion === null ? '.created' : '.updated'),
                $value->id
            );
            $this->authorize($actor);
            return $value->fields() + ['version' => $expectedVersion === null ? 0 : $expectedVersion + 1];
        });
    }
    private function authorize(EntityId $actor): void
    {
        if (
            $this->access->requirePermission('sis:hr:manage')->value !== $actor->value
            || $this->access->authenticatedUserId()->value !== $actor->value
        ) {
            throw new AccessDeniedException('The HR setup editor changed.');
        }
    }
}
