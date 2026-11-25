<?php

declare(strict_types=1);

namespace Domain\Reporting\ValueObject;

use Domain\Shared\Exception\ValidationException;

/** Ordered text labels only. Predicate typing and the shared rule budget belong to ReportQuery. */
final readonly class ReportLabelMapping
{
    public function __construct(public array $data)
    {
        if (
            count($data) !== 2 || array_diff(array_keys($data), ['branches', 'otherwise']) !== [] ||
            !is_array($data['branches']) || !array_is_list($data['branches']) ||
            count($data['branches']) < 1 || count($data['branches']) > 8
        ) {
            self::invalid();
        }
        foreach ($data['branches'] as $branch) {
            if (
                !is_array($branch) || count($branch) !== 2 ||
                array_diff(array_keys($branch), ['when', 'label']) !== [] ||
                !is_array($branch['when']) || !self::label($branch['label'])
            ) {
                self::invalid();
            }
        }
        if ($data['otherwise'] !== null && !self::label($data['otherwise'])) {
            self::invalid();
        }
    }

    private static function label(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= 120 &&
        mb_check_encoding($value, 'UTF-8') && !preg_match('/[\x00-\x1F\x7F]/', $value);
    }

    private static function invalid(): never
    {
        throw new ValidationException([['field' => 'label_mapping', 'message' =>
            \Codefy\Framework\Helpers\trans_html(
                'Choose one to eight label rules, readable labels up to 120 characters, and a label or blank fallback.',
            )]]);
    }
}
