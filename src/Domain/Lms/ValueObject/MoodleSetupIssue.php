<?php

declare(strict_types=1);

namespace Domain\Lms\ValueObject;

/** Fixed local guidance only. Never use Moodle's response message as an error description. */
enum MoodleSetupIssue: string
{
    case VerificationDisabled = "verification_disabled";
    case FunctionUnavailable = "function_unavailable";
    case PermissionDenied = "permission_denied";
    case IdentityUnavailable = "identity_unavailable";

    public function message(): string
    {
        return match ($this) {
            self::VerificationDisabled =>
            'Moodle instance verification is disabled. In Moodle, open Site administration > Plugins > '
            . 'Local plugins > etSIS connector, enable "Enable instance verification" and save changes.',
            self::FunctionUnavailable =>
            'The configured Moodle token does not advertise installation verification. '
            . 'Use a token for the etSIS instance verification service '
            . 'or authorize the plugin function in the intended service.',
            self::PermissionDenied =>
            'Moodle denied installation verification. Review the service account authorization '
            . 'and its local/etsis:viewinstance capability at system level.',
            self::IdentityUnavailable =>
            'The Moodle installation identity is unavailable. '
            . 'Review or restore the original connector configuration; do not generate a replacement identity.',
        };
    }
}
