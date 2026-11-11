<?php

declare(strict_types=1);

namespace Domain\Assistance\Service;

use Domain\Assistance\ValueObject\AuthoringAdvice;
use Domain\Assistance\ValueObject\AuthoringTask;

/** Provider-neutral authoring only. Student/financial records are not part of this contract. */
interface AuthoringAssistant
{
    /** Public consent label, never credentials, deployment names or endpoint URLs.
     * @return array{enabled:bool,label:string,fingerprint:string}
     */
    public function availability(): array;

    /** Already minimized catalog input. No tools, writes, publication or student data access.
     * @param array<string,mixed> $draft
     */
    public function suggest(AuthoringTask $task, #[\SensitiveParameter] array $draft): AuthoringAdvice;
}
