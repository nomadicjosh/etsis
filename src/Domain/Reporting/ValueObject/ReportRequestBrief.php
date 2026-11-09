<?php

declare(strict_types=1);

namespace Domain\Reporting\ValueObject;

use DateTimeImmutable;
use Domain\Shared\Exception\ValidationException;

final readonly class ReportRequestBrief
{
    public string $title;
    public string $question;
    public string $purpose;
    public function __construct(string $title, string $question, string $purpose, public ?string $dueOn = null)
    {
        $this->title = self::text("title", $title, 120);
        $this->question = self::text("question", $question, 5000);
        $this->purpose = self::text("purpose", $purpose, 2000);
        if ($dueOn !== null) {
            $date = DateTimeImmutable::createFromFormat("!Y-m-d", $dueOn);
            if ($date === false || $date->format("Y-m-d") !== $dueOn) {
                throw new ValidationException([["field" => "due_on", "message" => "Use a valid calendar date."]]);
            }
        }
    }
    public static function text(string $field, string $value, int $limit): string
    {
        $raw = $value;
        $value = trim($value);
        if ($value === "" || mb_strlen($value) > $limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $raw)) {
            throw new ValidationException([
                ["field" => $field, "message" => "Enter text within the displayed length limit."],
            ]);
        }
        return $value;
    }
    /** @return array{title: string, question: string, purpose: string, due_on: ?string} */
    public function values(): array
    {
        return [
            "title" => $this->title,
            "question" => $this->question,
            "purpose" => $this->purpose,
            "due_on" => $this->dueOn,
        ];
    }
}
