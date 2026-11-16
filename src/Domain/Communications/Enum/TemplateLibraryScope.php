<?php

declare(strict_types=1);

namespace Domain\Communications\Enum;

enum TemplateLibraryScope: string
{
    case Mine = 'mine';
    case Submitted = 'submitted';
    case Retired = 'retired';
    case Institutional = 'institutional';
    case Review = 'review';

    public function status(): string
    {
        return match ($this) {
            self::Mine => 'template',
            self::Submitted, self::Review => 'template_review',
            self::Retired => 'template_retired',
            self::Institutional => 'template_shared',
        };
    }

    public function personal(): bool
    {
        return !in_array($this, [self::Institutional, self::Review], true);
    }
}
