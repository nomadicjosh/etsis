<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Lms\ValueObject\MoodleBinding;

interface MoodleSectionGateway
{
    /** @return array{policy_hash:string} */
    public function rosterPolicy(MoodleBinding $binding): array;
    /** @param array{section:string,course:string,person:string,user:string,version:int,student:bool,instructor:bool} $member */
    public function syncMember(
        MoodleBinding $binding,
        string $policyHash,
        #[\SensitiveParameter] array $member,
    ): string;
}
