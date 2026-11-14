<?php

declare(strict_types=1);

namespace Domain\Catalog;

use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\ValidationException;
use InvalidArgumentException;

/** Program-owned authoring reference. Academic use requires a separate retained publication. */
final readonly class ProgramSpecialization
{
    public function __construct(
        public EntityId $id,
        public EntityId $programId,
        public string $code,
        public string $title,
        public ?string $description = null,
        public bool $active = true,
        public int $revision = 1,
    ) {
        $errors = [];
        if (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,79}$/D', $code) !== 1) {
            $errors[] = [
                'field' => 'code',
                'message' => 'Use up to 80 letters, numbers, dots, underscores or hyphens.',
            ];
        }
        if (
            trim($title) === '' ||
            $title !== trim($title) ||
            mb_strlen($title) > 191 ||
            !mb_check_encoding($title, 'UTF-8') ||
            preg_match('/[\x00-\x1F\x7F]/', $title)
        ) {
            $errors[] = [
                'field' => 'title',
                'message' => 'Enter a valid specialization title up to 191 characters.',
            ];
        }
        if (
            $description !== null &&
            (trim($description) === '' ||
                $description !== trim($description) ||
                mb_strlen($description) > 10000 ||
                !mb_check_encoding($description, 'UTF-8') ||
                preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $description))
        ) {
            $errors[] = ['field' => 'description', 'message' => 'Use plain text up to 10000 characters.'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($revision < 1 || $revision > 2000000000) {
            throw new InvalidArgumentException('Use a valid saved specialization revision.');
        }
    }

    /** @return array<string,scalar|null> */
    public function describe(): array
    {
        return [
            'id' => $this->id->value,
            'program_id' => $this->programId->value,
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'active' => $this->active,
            'revision' => $this->revision,
        ];
    }
}
