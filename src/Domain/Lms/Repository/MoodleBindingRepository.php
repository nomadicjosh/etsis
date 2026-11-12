<?php

declare(strict_types=1);

namespace Domain\Lms\Repository;

use Domain\Lms\ValueObject\MoodleBinding;

interface MoodleBindingRepository
{
    /** Reads shared database state, which can change between calls.
     * @phpstan-impure
     */
    public function current(): ?MoodleBinding;

    /** The unique institutional scope prevents two concurrent initial bindings. */
    public function add(MoodleBinding $binding, #[\SensitiveParameter] string $encryptedReason): void;
}
