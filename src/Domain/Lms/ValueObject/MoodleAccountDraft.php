<?php

declare(strict_types=1);

namespace Domain\Lms\ValueObject;

use Domain\Shared\Exception\ValidationException;

/** Reviewed destination attributes only; no SIS or Moodle password is accepted. */
final readonly class MoodleAccountDraft implements \JsonSerializable
{
    public function __construct(
        #[\SensitiveParameter] public string $username,
        #[\SensitiveParameter] public string $email,
        public string $signIn,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_.@-]{0,99}$/D', $username) !== 1) {
            throw new ValidationException([
                [
                    "field" => "username",
                    "message" => "Use a lowercase Moodle username of 1–100 permitted characters.",
                ],
            ]);
        }
        if (
            strlen($email) > 254 ||
            filter_var($email, FILTER_VALIDATE_EMAIL) === false ||
            preg_match('/[\x00-\x20\x7F]/', $email)
        ) {
            throw new ValidationException([
                ["field" => "email", "message" => "Use a valid account email address."],
            ]);
        }
        if (!in_array($signIn, ["sso", "password_email"], true)) {
            throw new ValidationException([
                [
                    "field" => "sign_in",
                    "message" => "Choose institutional SSO or a Moodle password-setup email.",
                ],
            ]);
        }
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ["account" => "[private Moodle account draft]"];
    }

    /** @return array<string,string> */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
