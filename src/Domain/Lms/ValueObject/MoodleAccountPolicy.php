<?php

declare(strict_types=1);

namespace Domain\Lms\ValueObject;

final readonly class MoodleAccountPolicy
{
    public function __construct(public string $hash, public bool $sso, public bool $passwordEmail)
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new \InvalidArgumentException("Invalid Moodle account policy.");
        }
    }
    public function permits(string $mode): bool
    {
        return match ($mode) {
            "sso" => $this->sso,
            "password_email" => $this->passwordEmail,
            default => false,
        };
    }
}
