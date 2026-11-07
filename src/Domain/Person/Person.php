<?php

declare(strict_types=1);

namespace Domain\Person;

use Domain\Shared\ValueObject\EntityId;
use Domain\Person\ValueObject\InstitutionalId;
use InvalidArgumentException;

final readonly class Person
{
    public function __construct(
        public EntityId $id,
        public ?EntityId $ownerUserId,
        public string $givenName,
        public string $familyName,
        public InstitutionalId $institutionalId,
        public bool $directoryRestricted = true,
        public int $identityVersion = 0,
    ) {
        if ($identityVersion < 0) {
            throw new InvalidArgumentException('The identity version cannot be negative.');
        }
        foreach ([$givenName, $familyName] as $name) {
            if (trim($name) === '' || mb_strlen($name) > 191 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
                throw new InvalidArgumentException('A valid name of at most 191 characters is required.');
            }
        }
    }

    public function withInstitutionalId(InstitutionalId $id): self
    {
        if ($id->value === $this->institutionalId->value) {
            return $this;
        }

        return new self(
            $this->id,
            $this->ownerUserId,
            $this->givenName,
            $this->familyName,
            $id,
            $this->directoryRestricted,
            $this->identityVersion + 1
        );
    }
}
