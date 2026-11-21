<?php

declare(strict_types=1);

namespace Domain\Import\ValueObject;

use Domain\Catalog\Course;
use Domain\Catalog\ValueObject\ProgramDefinition;
use Domain\Catalog\ValueObject\ClassificationSnapshot;
use Domain\Import\Enum\CatalogImportTarget;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** A validated catalog source record, without allocating persistent identities during review. */
final readonly class CatalogImportRow
{
    /** @var array<string,string> Explicit source codes; omissions never infer or clear a classification. */
    public array $references;

    /** @param array<array-key,mixed> $references */
    public function __construct(
        public int $row,
        public string $externalId,
        public string $code,
        public string $title,
        public ?int $creditsMilli,
        array $references = [],
        public CatalogImportTarget $target = CatalogImportTarget::Course,
        public ?string $description = null,
        public bool $descriptionMapped = false,
    ) {
        if (
            $row < 2 ||
            preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $externalId) !== 1 ||
            !mb_check_encoding($code . $title, "UTF-8") ||
            preg_match('/[\x00-\x1F\x7F]/', $code . $title)
        ) {
            throw new InvalidArgumentException("A source ID and printable catalog details are required.");
        }
        $clean = [];
        foreach ($references as $kind => $code) {
            if (!is_string($kind) || !isset($target->references()[$kind]) || !is_string($code)) {
                throw new InvalidArgumentException("Use known catalog reference codes.");
            }
            $code = strtoupper(trim($code));
            if ($code === "") {
                continue;
            }
            if (preg_match('~^[A-Z0-9][A-Z0-9._/-]{0,39}$~D', $code) !== 1) {
                throw new InvalidArgumentException(
                    "Use classification codes of up to 40 letters, digits or . _ / -.",
                );
            }
            $clean[$kind] = $code;
        }
        ksort($clean, SORT_STRING);
        $this->references = $clean;
        if ($target === CatalogImportTarget::Course) {
            if ($creditsMilli === null || $description !== null || $descriptionMapped) {
                throw new InvalidArgumentException("Course imports require credits without program fields.");
            }
            $this->draft();
        } elseif ($target->isReference()) {
            if ($creditsMilli !== null || $description !== null || $descriptionMapped) {
                throw new InvalidArgumentException(
                    "Reference imports require only source ID, code and name.",
                );
            }
            new ClassificationSnapshot(
                EntityId::generate(),
                $this->code,
                $this->title,
            );
        } else {
            if ($creditsMilli !== null || (!$descriptionMapped && $description !== null)) {
                throw new InvalidArgumentException("Program imports have no course credits.");
            }
            new ProgramDefinition($this->code, $this->title, $description);
        }
    }

    public function draft(): Course
    {
        if ($this->target !== CatalogImportTarget::Course || $this->creditsMilli === null) {
            throw new InvalidArgumentException("Only course rows create course drafts.");
        }
        return new Course(EntityId::generate(), $this->code, $this->title, $this->creditsMilli, false);
    }

    public function fingerprint(): string
    {
        if ($this->target->isReference()) {
            return hash(
                "sha256",
                json_encode([$this->target->value, $this->code, $this->title], JSON_THROW_ON_ERROR),
            );
        }
        $facts =
        $this->target === CatalogImportTarget::Course
        ? [$this->code, $this->title, $this->creditsMilli]
        : [
                    "program",
                    $this->code,
                    $this->title,
                    $this->descriptionMapped ? ["description" => $this->description] : [],
                ];
        // Existing source receipts attest to these original three facts; preserve their actual meaning.
        if ($this->references !== []) {
            $facts[] = $this->references;
        }
        return hash("sha256", json_encode($facts, JSON_THROW_ON_ERROR));
    }
}
