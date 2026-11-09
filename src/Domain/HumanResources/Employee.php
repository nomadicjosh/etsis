<?php

declare(strict_types=1);

namespace Domain\HumanResources;

use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\ValidationException;

final readonly class Employee
{
    public function __construct(
        public EntityId $id,
        public EntityId $personId,
        public string $jobTitle,
        public bool $active,
    ) {
        if (trim($jobTitle) === '' || mb_strlen($jobTitle) > 191 || preg_match('/[\x00-\x1F\x7F]/', $jobTitle)) {
            throw new ValidationException([['field' => 'job_title',
                'message' => 'Enter a job title of up to 191 characters.']]);
        }
    }
}
