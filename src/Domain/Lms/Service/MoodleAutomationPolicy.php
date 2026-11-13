<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Shared\ValueObject\EntityId;

interface MoodleAutomationPolicy
{
    public function courseCreationEnabled(): bool;
    public function automaticAccountsEnabled(): bool;
    public function signIn(): string;
    public function actor(): EntityId;
}
