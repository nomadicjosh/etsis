<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Lms\Repository\MoodleAccountDefaults;
use Domain\Person\Person;

final readonly class MoodleAccountProfile
{
    public function __construct(private MoodleAccountDefaults $defaults)
    {
    }
    public function revision(Person $person): string
    {
        return hash(
            "sha256",
            json_encode(
                [
                    $person->id->value,
                    $person->ownerUserId?->value,
                    $person->givenName,
                    $person->familyName,
                    $person->institutionalId->value,
                    $person->identityVersion,
                    $person->directoryRestricted,
                    $this->defaults->forOwner($person->ownerUserId),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
