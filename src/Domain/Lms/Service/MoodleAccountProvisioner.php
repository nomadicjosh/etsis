<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Lms\ValueObject\{MoodleBinding, MoodleAccountPolicy, MoodleAccountDraft, MoodleProvisionedAccount};
use Domain\Shared\ValueObject\EntityId;

interface MoodleAccountProvisioner
{
    public function policy(MoodleBinding $binding): MoodleAccountPolicy;
    /** The operation must be reconciled remotely, never replaced on an uncertain outcome. */
    public function provision(
        MoodleBinding $binding,
        EntityId $operation,
        string $policyHash,
        #[\SensitiveParameter] MoodleAccountDraft $draft,
        #[\SensitiveParameter] string $firstName,
        #[\SensitiveParameter] string $lastName,
    ): MoodleProvisionedAccount;
}
