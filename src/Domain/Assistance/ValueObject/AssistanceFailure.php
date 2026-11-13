<?php

declare(strict_types=1);

namespace Domain\Assistance\ValueObject;

/** Fixed public categories only; never carry upstream messages or credentials. */
enum AssistanceFailure
{
    case Unavailable;
    case Configuration;
    case Authentication;
    case Capacity;
    case RequestRejected;

    public function message(): string
    {
        return match ($this) {
            self::Unavailable
            => "Optional assistance is unavailable. Your draft is unchanged; continue editing or try again later.",
            self::Configuration
            => "AI assistance settings are incomplete or invalid. " .
            "Ask an administrator to check the endpoint, model and authentication settings. Your draft is unchanged.",
            self::Authentication
            => "The assistance provider rejected authentication. " .
            "Ask an administrator to check the AI credential and authentication setting. Your draft is unchanged.",
            self::Capacity
            => "The assistance provider is at its usage or rate limit. " .
            "Try later or ask an administrator to check provider usage and billing. Your draft is unchanged.",
            self::RequestRejected
            => "The assistance provider rejected this request. " .
            "Ask an administrator to check model access and support for the configured " .
            "API protocol and structured output. " .
            "Your draft is unchanged.",
        };
    }
}
