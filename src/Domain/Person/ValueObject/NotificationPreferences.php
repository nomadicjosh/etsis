<?php

declare(strict_types=1);

namespace Domain\Person\ValueObject;

use InvalidArgumentException;

use function Codefy\Framework\Helpers\trans_html;

final readonly class NotificationPreferences
{
    public const array SOUNDS = ["tone", "message", "warm", "bird", "piano"];
    public const array DEFAULTS = ["sound" => false, "sound_id" => "tone", "keep_visible" => false];

    public array $values;

    public function __construct(array $values = self::DEFAULTS)
    {
        if (
            count($values) !== 3 ||
            !is_bool($values["sound"] ?? null) ||
            !is_bool($values["keep_visible"] ?? null) ||
            !in_array($values["sound_id"] ?? null, self::SOUNDS, true)
        ) {
            throw new InvalidArgumentException(trans_html("Choose valid notification display and sound preferences."));
        }
        $this->values = [
            "sound" => $values["sound"],
            "sound_id" => $values["sound_id"],
            "keep_visible" => $values["keep_visible"],
        ];
    }
}
