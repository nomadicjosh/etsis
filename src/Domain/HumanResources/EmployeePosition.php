<?php

declare(strict_types=1);

namespace Domain\HumanResources;

use DateTimeImmutable;
use DateTimeZone;
use Domain\HumanResources\ValueObject\DefinitionRules;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Throwable;

/** One dated appointment with retained setup and agreed terms; not a payroll calculation. */
final readonly class EmployeePosition
{
    /** @var array<string,mixed> */
    public array $job;
    /** @var ?array<string,mixed> */
    public ?array $grade;
    /** @param array<string,mixed> $job
     * @param ?array<string,mixed> $grade
     */
    public function __construct(
        public EntityId $id,
        public EntityId $employeeId,
        array $job,
        public int $jobVersion,
        ?array $grade,
        public ?int $gradeVersion,
        public string $from,
        public ?string $through,
        public ?int $rateMinor,
        public ?int $weeklyMinutes,
        public int $version,
        public int $currencyDigits,
        public bool $voided = false,
        public ?EntityId $replaces = null,
    ) {
        $this->job = self::snapshot($job, true);
        $this->grade = $grade === null ? null : self::snapshot($grade, false);
        self::date($from, 'valid_from');
        if ($through !== null) {
            self::date($through, 'valid_through');
            if ($through < $from) {
                DefinitionRules::invalid('valid_through', 'The through date must be on or after the from date.');
            }
        }
        if (
            $jobVersion < 0 || $jobVersion > 2_000_000_000 || $version < 1 || $version > 2000
            || !$job['active'] || $currencyDigits < 0 || $currencyDigits > 6
            || ($grade === null) !== ($gradeVersion === null)
            || ($job['pay_grade_id'] ?? null) !== ($grade['id'] ?? null)
            || ($gradeVersion !== null && ($gradeVersion < 0 || $gradeVersion > 2_000_000_000))
        ) {
            throw new CorruptHistoryException();
        }
        DefinitionRules::amount($rateMinor, 'rate_minor');
        if ($weeklyMinutes !== null && ($weeklyMinutes < 0 || $weeklyMinutes > 10080)) {
            DefinitionRules::invalid('weekly_minutes', 'Weekly hours must fit within a seven-day week.');
        }
        if (
            $grade !== null && (!$grade['active'] || $grade['currency'] !== $job['currency']
            || $grade['basis'] !== $job['basis'] || ($rateMinor !== null
                && ($rateMinor < $grade['minimum_minor'] || $rateMinor > $grade['maximum_minor'])))
        ) {
            DefinitionRules::invalid('rate_minor', 'Agreed terms must use the retained pay grade units and range.');
        }
        if ($replaces?->value === $id->value) {
            throw new CorruptHistoryException();
        }
    }
    /** Retained snapshots are validated without today's ISO tables or mutable setup records.
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private static function snapshot(array $snapshot, bool $job): array
    {
        $keys = $job ? ['id', 'code', 'title', 'currency', 'basis', 'rate_minor', 'weekly_minutes',
            'pay_grade_id', 'active']
        : ['id', 'code', 'title', 'currency', 'basis', 'minimum_minor', 'maximum_minor', 'active'];
        if (
            count($snapshot) !== count($keys) || array_diff(array_keys($snapshot), $keys) !== []
            || !is_string($snapshot['id']) || !is_string($snapshot['code'])
            || !is_string($snapshot['title']) || !is_string($snapshot['currency'])
            || preg_match('/^[A-Z]{3}$/D', $snapshot['currency']) !== 1
            || !in_array($snapshot['basis'], ['hourly', 'annual'], true) || !is_bool($snapshot['active'])
        ) {
            throw new CorruptHistoryException();
        }
        new EntityId($snapshot['id']);
        if (
            DefinitionRules::code($snapshot['code']) !== $snapshot['code']
            || DefinitionRules::title($snapshot['title']) !== $snapshot['title']
        ) {
            throw new CorruptHistoryException();
        }
        foreach ($job ? ['rate_minor', 'weekly_minutes'] : ['minimum_minor', 'maximum_minor'] as $key) {
            if (
                ($snapshot[$key] !== null || !$job) && (!is_int($snapshot[$key])
                || $snapshot[$key] < 0 || $snapshot[$key] > 2_000_000_000)
            ) {
                throw new CorruptHistoryException();
            }
        }
        if ($job && ($snapshot['weekly_minutes'] !== null && $snapshot['weekly_minutes'] > 10080)) {
            throw new CorruptHistoryException();
        }
        if ($job && $snapshot['pay_grade_id'] !== null) {
            new EntityId($snapshot['pay_grade_id']);
        }
        if (!$job && $snapshot['minimum_minor'] > $snapshot['maximum_minor']) {
            throw new CorruptHistoryException();
        }
        return array_replace(array_fill_keys($keys, null), $snapshot);
    }
    public static function date(string $value, string $field): void
    {
        $date = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) === 1
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC')) : false;
        if ($date === false || $date->format('Y-m-d') !== $value || $value < '1000-01-02' || $value > '9998-12-30') {
            DefinitionRules::invalid($field, 'Enter a valid calendar date.');
        }
    }
    /** @return array<string,mixed> */
    public function facts(): array
    {
        return ['id' => $this->id->value, 'employee_id' => $this->employeeId->value,
            'job' => $this->job, 'job_version' => $this->jobVersion,
            'grade' => $this->grade, 'grade_version' => $this->gradeVersion,
            'valid_from' => $this->from, 'valid_through' => $this->through,
            'rate_minor' => $this->rateMinor, 'weekly_minutes' => $this->weeklyMinutes,
            'version' => $this->version, 'currency_digits' => $this->currencyDigits,
            'voided' => $this->voided, 'replaces_id' => $this->replaces?->value];
    }
    public function changeEnd(?string $through): self
    {
        return new self(
            $this->id,
            $this->employeeId,
            $this->job,
            $this->jobVersion,
            $this->grade,
            $this->gradeVersion,
            $this->from,
            $through,
            $this->rateMinor,
            $this->weeklyMinutes,
            $this->version + 1,
            $this->currencyDigits,
            $this->voided,
            $this->replaces
        );
    }
    public function changeVoid(bool $voided): self
    {
        return new self(
            $this->id,
            $this->employeeId,
            $this->job,
            $this->jobVersion,
            $this->grade,
            $this->gradeVersion,
            $this->from,
            $this->through,
            $this->rateMinor,
            $this->weeklyMinutes,
            $this->version + 1,
            $this->currencyDigits,
            $voided,
            $this->replaces
        );
    }
    /** @param array<string,mixed> $facts */
    public static function restore(#[\SensitiveParameter] array $facts): self
    {
        try {
            $value = new self(
                new EntityId($facts['id']),
                new EntityId($facts['employee_id']),
                $facts['job'],
                $facts['job_version'],
                $facts['grade'],
                $facts['grade_version'],
                $facts['valid_from'],
                $facts['valid_through'],
                $facts['rate_minor'],
                $facts['weekly_minutes'],
                $facts['version'],
                $facts['currency_digits'],
                $facts['voided'],
                $facts['replaces_id'] === null ? null : new EntityId($facts['replaces_id'])
            );
            $facts['job'] = $value->job;
            $facts['grade'] = $value->grade;
            if (count($facts) !== count($value->facts())) {
                throw new CorruptHistoryException();
            }
            foreach ($value->facts() as $key => $expected) {
                if (!array_key_exists($key, $facts) || $facts[$key] !== $expected) {
                    throw new CorruptHistoryException();
                }
            }
            return $value;
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }
}
