<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use Domain\Catalog\ProgramSpecialization;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** One program publication's complete selection policy and offered definitions. */
final readonly class ProgramSpecializationSnapshot
{
    /** @var array<string,mixed> */
    public array $data;
    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $keys = ["program_id", "institution", "program", "effective", "offered"];
        if (array_key_exists("component_sharing", $data)) {
            $keys[] = "component_sharing";
            if (
                !is_string($data["component_sharing"]) ||
                ComponentCourseSharing::tryFrom($data["component_sharing"]) === null
            ) {
                throw new InvalidArgumentException(
                    "Retain an explicit supported component-sharing policy.",
                );
            }
        }
        $typed = array_key_exists("kind_limits", $data);
        if ($typed) {
            $keys[] = "kind_limits";
        }
        self::keys($data, $keys);
        $program = new EntityId($data["program_id"]);
        self::keys($data["institution"], ["revision", "maximum", "default_maximum"]);
        self::keys($data["program"], ["revision", "minimum", "maximum"]);
        self::keys($data["effective"], ["minimum", "maximum"]);
        foreach ([$data["institution"], $data["program"]] as $settings) {
            foreach ($settings as $key => $value) {
                if ($key === "maximum" && $settings === $data["program"] && $value === null) {
                    continue;
                }
                if (!is_int($value) || $value < 0 || $value > 2000000000) {
                    throw new InvalidArgumentException("Use exact specialization policy values.");
                }
            }
        }
        $policy = new SpecializationPolicy(
            $data["institution"]["maximum"],
            $data["institution"]["default_maximum"],
            $data["program"]["minimum"],
            $data["program"]["maximum"],
        );
        if (
            $data["effective"]["minimum"] !== $policy->minimum ||
            $data["effective"]["maximum"] !== $policy->effectiveMaximum ||
            !is_array($data["offered"]) ||
            !array_is_list($data["offered"]) ||
            count($data["offered"]) > 100 ||
            $policy->minimum > count($data["offered"]) ||
            strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 49152
        ) {
            throw new InvalidArgumentException(
                "Use a bounded, consistent specialization publication.",
            );
        }
        $ids = $codes = [];
        foreach ($data["offered"] as $row) {
            self::keys($row, [
                "id",
                "code",
                "title",
                "description",
                "revision",
                ...array_key_exists("kind", $row) ? ["kind"] : [],
            ]);
            if (
                array_key_exists("kind", $row) &&
                (!is_string($row["kind"]) ||
                    CurriculumComponentKind::tryFrom($row["kind"]) === null)
            ) {
                throw new InvalidArgumentException("Retain a supported component type.");
            }
            $value = new ProgramSpecialization(
                new EntityId($row["id"]),
                $program,
                $row["code"],
                $row["title"],
                $row["description"],
                true,
                $row["revision"],
            );
            if (isset($ids[$value->id->value]) || isset($codes[$value->code])) {
                throw new InvalidArgumentException(
                    "Use distinct specialization identities and codes.",
                );
            }
            $ids[$value->id->value] = $codes[$value->code] = true;
        }
        if ($typed) {
            if (!is_array($data["kind_limits"])) {
                throw new InvalidArgumentException("Retain complete component-type limits.");
            }
            $rules = new ComponentSelectionRules($data["kind_limits"]);
            $rules->publication($data["offered"], $policy->minimum, $policy->effectiveMaximum);
            $data["kind_limits"] = $rules->limits;
        }
        $this->data = self::normalize($data);
    }

    /** @param array<mixed> $value
     * @return array<mixed> */
    private static function normalize(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::normalize($item);
            }
        }
        unset($item);
        return $value;
    }

    /** @param mixed $value
     * @param list<string> $keys */
    private static function keys(mixed $value, array $keys): void
    {
        if (
            !is_array($value) ||
            count($value) !== count($keys) ||
            array_diff(array_keys($value), $keys) !== []
        ) {
            throw new InvalidArgumentException("Provide the complete specialization snapshot.");
        }
    }
}
