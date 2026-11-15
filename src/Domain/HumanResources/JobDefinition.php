<?php

declare(strict_types=1);

namespace Domain\HumanResources;

use Domain\HumanResources\ValueObject\DefinitionRules;
use Domain\Shared\ValueObject\EntityId;

final readonly class JobDefinition
{
    public string $code;
    public string $title;
    public function __construct(
        public EntityId $id,
        string $code,
        string $title,
        public string $currency,
        public string $basis,
        public bool $active,
        public ?EntityId $payGradeId = null,
        public ?int $rateMinor = null,
        public ?int $weeklyMinutes = null,
    ) {
        $this->code = DefinitionRules::code($code);
        $this->title = DefinitionRules::title($title);
        DefinitionRules::units($currency, $basis);
        DefinitionRules::amount($rateMinor, 'rate_minor');
        if ($weeklyMinutes !== null && ($weeklyMinutes < 0 || $weeklyMinutes > 10080)) {
            DefinitionRules::invalid('weekly_minutes', 'Weekly hours must fit within a seven-day week.');
        }
    }
    public function validateGrade(PayGrade $grade): void
    {
        if ($this->active && !$grade->active) {
            DefinitionRules::invalid('pay_grade_id', 'Choose an active pay grade for an active job.');
        }
        if ($grade->currency !== $this->currency || $grade->basis !== $this->basis) {
            DefinitionRules::invalid('pay_grade_id', 'Currency and rate basis must match the selected pay grade.');
        }
        if (
            $this->rateMinor !== null
            && ($this->rateMinor < $grade->minimumMinor || $this->rateMinor > $grade->maximumMinor)
        ) {
            DefinitionRules::invalid('rate_minor', 'The default rate must fall within the selected pay grade.');
        }
    }
    /** @return array<string,scalar|null> */
    public function fields(): array
    {
        return ['id' => $this->id->value, 'code' => $this->code, 'title' => $this->title,
            'currency' => $this->currency, 'basis' => $this->basis, 'rate_minor' => $this->rateMinor,
            'weekly_minutes' => $this->weeklyMinutes, 'pay_grade_id' => $this->payGradeId?->value,
            'active' => $this->active];
    }
}
