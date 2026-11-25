<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

final readonly class GraduateEnrollmentEvidence implements \JsonSerializable
{
    public GraduateCourseScope $scope;

    public function __construct(#[\SensitiveParameter] public array $data)
    {
        try {
            if (
                count($data) !== 4 ||
                array_diff(array_keys($data), [
                    "scope",
                    "advisor_operation_id",
                    "registrar_operation_id",
                    "approval_version",
                ]) !== [] ||
                !is_array($data["scope"]) ||
                !is_int($data["approval_version"]) ||
                $data["approval_version"] < 2 ||
                $data["approval_version"] > 2000
            ) {
                throw new \InvalidArgumentException();
            }
            $this->scope = new GraduateCourseScope($data["scope"]);
            new EntityId($data["advisor_operation_id"]);
            new EntityId($data["registrar_operation_id"]);
            if ($data["advisor_operation_id"] === $data["registrar_operation_id"]) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function routing(
        string $student,
        string $section,
        ?string $courseVersion,
        ?string $sectionVersion,
        string $pursuit,
    ): void {
        $s = $this->scope->data;
        if (
            $s["student_id"] !== $student ||
            $s["section_id"] !== $section ||
            $s["course_version_id"] !== $courseVersion ||
            $s["section_version_id"] !== $sectionVersion ||
            $s["pursuit_id"] !== $pursuit
        ) {
            throw new CorruptHistoryException();
        }
    }

    public function __debugInfo(): array
    {
        return ["evidence" => "[protected graduate-course registration evidence]"];
    }
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
