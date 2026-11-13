<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Shared\ValueObject\EntityId;
use Domain\Lms\ValueObject\MoodleBinding;

interface MoodleSectionGateway
{
    /**
     * @param array{source:string,reference_id:string,code:string,title:string,version:int,category_id:string} $category
     * @return array{id:string,owned:bool,status:string}
     */
    public function syncCategory(MoodleBinding $binding, array $category): array;
    /** Create or return the original course for one retained publication decision.
     * @param array{shortname:string,fullname:string,startdate:int,enddate:int,category:string} $course */
    public function provisionCourse(
        \Domain\Lms\ValueObject\MoodleBinding $binding,
        EntityId $operation,
        array $course,
    ): \Domain\Lms\ValueObject\MoodleExternalId;
    /** @return array{policy_hash:string} */
    public function rosterPolicy(MoodleBinding $binding): array;
    /** @param array{section:string,course:string,person:string,user:string,version:int,student:bool,instructor:bool} $member */
    public function syncMember(
        MoodleBinding $binding,
        string $policyHash,
        #[\SensitiveParameter] array $member,
    ): string;
}
