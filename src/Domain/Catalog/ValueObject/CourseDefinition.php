<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Current course definition. Alpha field changes evolve this model directly. */
final readonly class CourseDefinition
{
    /** @param list<string> $prerequisites */
    public function __construct(
        public string $code,
        public string $title,
        public int $creditsMilli,
        public array $prerequisites,
        public CourseText $text = new CourseText(),
        public ?SubjectSnapshot $subject = null,
        public CourseClassifications $classifications = new CourseClassifications(),
    ) {
        if (
            trim($code) === "" ||
            strlen($code) > 80 ||
            trim($title) === "" ||
            mb_strlen($title) > 191 ||
            $creditsMilli < 0 ||
            $creditsMilli > 999999
        ) {
            throw new InvalidArgumentException(
                "Valid course code, title and credit thousandths are required.",
            );
        }
        if (
            !mb_check_encoding($code . $title, "UTF-8") ||
            preg_match('/[\x00-\x1F\x7F]/', $code . $title) ||
            count($prerequisites) > 100
        ) {
            throw new InvalidArgumentException("A bounded, valid course definition is required.");
        }
        $sorted = $prerequisites;
        sort($sorted, SORT_STRING);
        if ($sorted !== $prerequisites || count(array_unique($prerequisites)) !== count($prerequisites)) {
            throw new InvalidArgumentException(
                "Course prerequisite identities must be distinct and ordered.",
            );
        }
        foreach ($prerequisites as $id) {
            new EntityId($id);
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $keys = [
            "code",
            "title",
            "credits_milli",
            "prerequisites",
            "transcript_title",
            "description",
            "subject",
            "classifications",
        ];
        if (
            count($data) !== count($keys) ||
            array_diff(array_keys($data), $keys) !== [] ||
            !is_string($data["code"] ?? null) ||
            !is_string($data["title"] ?? null) ||
            !is_int($data["credits_milli"] ?? null) ||
            !is_array($data["prerequisites"] ?? null) ||
            !array_is_list($data["prerequisites"]) ||
            ($data["subject"] !== null && !is_array($data["subject"])) ||
            !is_array($data["classifications"] ?? null)
        ) {
            throw new InvalidArgumentException("Provide the complete current course definition.");
        }
        foreach ($data["prerequisites"] as $id) {
            if (!is_string($id)) {
                throw new InvalidArgumentException("Use course identities for prerequisites.");
            }
        }
        return new self(
            $data["code"],
            $data["title"],
            $data["credits_milli"],
            $data["prerequisites"],
            CourseText::fromArray($data),
            $data["subject"] === null ? null : SubjectSnapshot::fromArray($data["subject"]),
            CourseClassifications::fromArray($data["classifications"]),
        );
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return [
            "code" => $this->code,
            "title" => $this->title,
            "credits_milli" => $this->creditsMilli,
            "prerequisites" => $this->prerequisites,
        ] +
        $this->text->describe() + [
            "subject" => $this->subject?->describe(),
            "classifications" => $this->classifications->describe(),
        ];
    }
}
