<?php

declare(strict_types=1);

namespace Domain\Lms\ValueObject;

final readonly class MoodleAccountReceipt
{
    public function __construct(public string $status, public ?MoodleProvisionedAccount $account)
    {
        if (
            !in_array($status, ["not_observed", "recorded", "restricted"], true) ||
            ($status === "recorded") !== ($account !== null)
        ) {
            throw new \InvalidArgumentException("Invalid Moodle receipt result.");
        }
    }
}
