<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use InvalidArgumentException;

/** Plain text, not trusted HTML. These limits apply to the current course definition. */
final readonly class CourseText
{
    public ?string $transcriptTitle;
    public ?string $description;
    public ?string $courseNumber;

    public function __construct(
        ?string $transcriptTitle = null,
        ?string $description = null,
        ?string $courseNumber = null,
        public bool $courseNumberRecorded = true,
    ) {
        foreach ([$transcriptTitle, $description] as $value) {
            if (
                $value !== null &&
                (!mb_check_encoding($value, "UTF-8") || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value))
            ) {
                throw new InvalidArgumentException("Use valid plain text for course descriptions.");
            }
        }
        if (
            ($transcriptTitle !== null &&
                (mb_strlen($transcriptTitle) > 80 || preg_match('/[\r\n\t]/', $transcriptTitle))) ||
            ($description !== null && mb_strlen($description) > 10000)
        ) {
            throw new InvalidArgumentException(
                "Use a transcript title up to 80 characters and description up to 10000 characters.",
            );
        }
        if (
            ($courseNumber !== null && (!mb_check_encoding($courseNumber, 'UTF-8') ||
                mb_strlen($courseNumber) > 32 || preg_match('/[\x00-\x1f\x7f]/', $courseNumber))) ||
            (!$courseNumberRecorded && $courseNumber !== null)
        ) {
            throw new InvalidArgumentException('Use an optional course number of up to 32 printable characters.');
        }
        $this->courseNumber = $courseNumber === null || trim($courseNumber) === '' ? null : trim($courseNumber);
        $this->transcriptTitle =
        $transcriptTitle === null || trim($transcriptTitle) === "" ? null : trim($transcriptTitle);
        $this->description = $description === null || trim($description) === "" ? null : trim($description);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (["transcript_title", "description"] as $field) {
            if (!array_key_exists($field, $data) || ($data[$field] !== null && !is_string($data[$field]))) {
                throw new InvalidArgumentException("Provide a transcript title and description, or leave them blank.");
            }
        }
        if (isset($data['course_number']) && !is_string($data['course_number'])) {
            throw new InvalidArgumentException('The course number must remain text.');
        }
        return new self(
            $data['transcript_title'],
            $data['description'],
            $data['course_number'] ?? null,
            array_key_exists('course_number', $data)
        );
    }

    /** @return array{transcript_title: ?string, description: ?string, course_number?: ?string} */
    public function describe(): array
    {
        return ["transcript_title" => $this->transcriptTitle, "description" => $this->description] +
        ($this->courseNumberRecorded ? ['course_number' => $this->courseNumber] : []);
    }
}
