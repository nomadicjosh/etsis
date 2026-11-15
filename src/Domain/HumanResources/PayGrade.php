<?php

declare(strict_types=1);

namespace Domain\HumanResources;

use Domain\HumanResources\ValueObject\DefinitionRules;
use Domain\Shared\ValueObject\EntityId;

final readonly class PayGrade
{
    public string $code;
    public string $title;
    public function __construct(
        public EntityId $id,
        string $code,
        string $title,
        public string $currency,
        public string $basis,
        public int $minimumMinor,
        public int $maximumMinor,
        public bool $active,
    ) {
        $this->code = DefinitionRules::code($code);
        $this->title = DefinitionRules::title($title);
        DefinitionRules::units($currency, $basis);
        DefinitionRules::amount($minimumMinor, 'minimum_minor');
        DefinitionRules::amount($maximumMinor, 'maximum_minor');
        if ($minimumMinor > $maximumMinor) {
            DefinitionRules::invalid('maximum_minor', 'The maximum must be at least the minimum.');
        }
    }
    /** @return array<string,scalar|null> */
    public function fields(): array
    {
        return ['id' => $this->id->value, 'code' => $this->code, 'title' => $this->title,
            'currency' => $this->currency, 'basis' => $this->basis, 'minimum_minor' => $this->minimumMinor,
            'maximum_minor' => $this->maximumMinor, 'active' => $this->active];
    }
}
