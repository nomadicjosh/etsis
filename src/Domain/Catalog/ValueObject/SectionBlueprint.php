<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use Domain\Finance\ValueObject\Money;
use Domain\Registration\Enum\DeliveryMode;
use Domain\Registration\Section;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** Reusable defaults, without an enrollment, section identity or offering term. */
final readonly class SectionBlueprint
{
    public string $code;
    public function __construct(
        public EntityId $course,
        string $code,
        public int $capacity,
        public ?EntityId $faculty,
        public Money $fee,
        public DeliveryMode $mode,
        public bool $webRegistration,
    ) {
        $this->code = trim($code);
        if (
            $this->code === "" ||
            strlen($this->code) > 80 ||
            preg_match('/[\x00-\x1F\x7F]/', $code) ||
            $capacity < 1 ||
            $capacity > 100000 ||
            $fee->minor < 0
        ) {
            throw new ValidationException([
                ["field" => "sections", "message" => "Use valid section codes, 1–100000 seats and nonnegative fees."],
            ]);
        }
    }
    public function instantiate(EntityId $term): Section
    {
        return new Section(
            EntityId::generate(),
            $this->course,
            $term,
            $this->faculty,
            $this->code,
            $this->capacity,
            false,
            $this->webRegistration,
            $this->fee,
            $this->mode,
        );
    }
    /** @return array{course_id: string, code: string, capacity: int, faculty_user_id: ?string, fee_minor: int, currency: string, delivery_mode: string, web_registration: bool} */
    public function describe(): array
    {
        return [
            "course_id" => $this->course->value,
            "code" => $this->code,
            "capacity" => $this->capacity,
            "faculty_user_id" => $this->faculty?->value,
            "fee_minor" => $this->fee->minor,
            "currency" => $this->fee->currency,
            "delivery_mode" => $this->mode->value,
            "web_registration" => $this->webRegistration,
        ];
    }
}
