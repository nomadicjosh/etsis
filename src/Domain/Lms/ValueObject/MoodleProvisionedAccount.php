<?php

declare(strict_types=1);

namespace Domain\Lms\ValueObject;

final readonly class MoodleProvisionedAccount
{
    public function __construct(public MoodleExternalId $user, public string $signInStatus)
    {
        if (!in_array($signInStatus, ["sso_ready", "email_sent", "email_review_required"], true)) {
            throw new \InvalidArgumentException("Invalid Moodle sign-in status.");
        }
    }
}
