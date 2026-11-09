<?php

declare(strict_types=1);

namespace Infrastructure\EventSourcing;

use Codefy\Domain\Aggregate\AggregateType;
use Codefy\Domain\Aggregate\AggregateId;
use Codefy\Domain\EventSourcing\AggregateChanged;
use Codefy\Domain\EventSourcing\DomainEvent;
use Codefy\Domain\EventSourcing\EventId;
use Codefy\Domain\Metadata;
use DateTimeImmutable;
use DateTimeZone;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StreamReference;
use Infrastructure\Persistence\EventClassMap;

/** Explicit schema allowlist; unsupported event/version combinations fail closed. */
final readonly class HistoricalSchemaRegistry
{
    /** @return class-string<AggregateChanged> */
    private function resolve(string $class, string $type, int $version): string
    {
        if (
            $version === 1 ||
            ($version === 2 &&
                in_array(
                    $class,
                    [
                        \Domain\DegreeAudit\Event\CourseGroupWasPublished::class,
                        \Domain\DegreeAudit\Event\RequirementAdjustmentWasGranted::class,
                        \Domain\Catalog\Event\CourseDefinitionWasPublished::class,
                        \Domain\DegreeAudit\Event\RequirementsWerePublished::class,
                    ],
                    true,
                )) ||
            (in_array($version, [3, 4], true) &&
                $class === \Domain\DegreeAudit\Event\RequirementsWerePublished::class)
        ) {
            foreach (EventClassMap::registered() as $registered) {
                if ($class === $registered && $type === AggregateType::fromClassName($registered)) {
                    return $registered;
                }
            }
        }
        throw new CorruptHistoryException();
    }

    /** @return array{type: string, root: class-string, fields: list<string>} */
    private function definition(string $class): array
    {
        return match ($class) {
            \Domain\Student\Event\PrimaryProgramWasObserved::class => [
                "type" => "student_primary_program",
                "root" => \Domain\Student\StudentPrimaryProgram::class,
                "fields" => [
                    "decision_id",
                    "student_id",
                    "person_id",
                    "institutional_entry_term_id",
                    "selection",
                ],
            ],
            \Domain\Student\Event\PrimaryProgramWasSelected::class => [
                "type" => "student_primary_program",
                "root" => \Domain\Student\StudentPrimaryProgram::class,
                "fields" => ["decision_id", "previous_decision_id", "selection"],
            ],
            \Domain\Student\Event\StudentProgramWasObserved::class => [
                "type" => "student_program_status",
                "root" => \Domain\Student\StudentProgramStatus::class,
                "fields" => ["decision_id", "assignment"],
            ],
            \Domain\Student\Event\StudentProgramStatusWasChanged::class => [
                "type" => "student_program_status",
                "root" => \Domain\Student\StudentProgramStatus::class,
                "fields" => ["decision_id", "previous_decision_id", "status"],
            ],
            \Domain\Student\Event\StudentActivityWasObserved::class => [
                "type" => "student_activity",
                "root" => \Domain\Student\StudentActivity::class,
                "fields" => ["decision_id", "student_id", "person_id", "active", "effective_on"],
            ],
            \Domain\Student\Event\StudentActivityWasDecided::class => [
                "type" => "student_activity",
                "root" => \Domain\Student\StudentActivity::class,
                "fields" => [
                    "decision_id",
                    "previous_decision_id",
                    "student_id",
                    "active",
                    "effective_on",
                    "source",
                    "reference_id",
                ],
            ],
            \Domain\Registration\Event\EnrollmentWasEstablished::class => [
                "type" => "enrollment",
                "root" => \Domain\Registration\EnrollmentHistory::class,
                "fields" => ["decision_id", "provenance", "enrollment", "source", "eligibility", "seat"],
            ],
            \Domain\Registration\Event\EnrollmentWasClosed::class => [
                "type" => "enrollment",
                "root" => \Domain\Registration\EnrollmentHistory::class,
                "fields" => ["decision_id", "previous_decision_id", "status", "status_at", "policy", "seat"],
            ],
            \Domain\Transfer\Event\TransferCreditWasEstablished::class => [
                "type" => "transfer_credit",
                "root" => \Domain\Transfer\TransferCredit::class,
                "fields" => ["decision_id", "provenance", "source", "credit", "grade"],
            ],
            \Domain\Transfer\Event\TransferCreditWasChanged::class => [
                "type" => "transfer_credit",
                "root" => \Domain\Transfer\TransferCredit::class,
                "fields" => ["decision_id", "previous_decision_id", "action", "credits_milli", "grade"],
            ],
            \Domain\AcademicRecord\Event\InstitutionalCreditWasEstablished::class => [
                "type" => "academic_credit",
                "root" => \Domain\AcademicRecord\AcademicCredit::class,
                "fields" => ["decision_id", "provenance", "credit", "section_id", "policies", "grade"],
            ],
            \Domain\AcademicRecord\Event\FinalGradeWasDecided::class => [
                "type" => "academic_credit",
                "root" => \Domain\AcademicRecord\AcademicCredit::class,
                "fields" => ["decision_id", "previous_decision_id", "grade", "assignment_id"],
            ],
            \Domain\AcademicRecord\Event\InstitutionalCreditWasDropped::class => [
                "type" => "academic_credit",
                "root" => \Domain\AcademicRecord\AcademicCredit::class,
                "fields" => ["decision_id", "previous_decision_id", "outcome", "grade"],
            ],
            \Domain\DegreeAudit\Event\RequirementAdjustmentWasGranted::class => [
                "type" => "student_requirement_adjustments",
                "root" => \Domain\DegreeAudit\RequirementAdjustments::class,
                "fields" => [
                    "adjustment_id",
                    "supersedes_adjustment_id",
                    "student_id",
                    "program_id",
                    "requirement_decision_id",
                    "template_id",
                    "requirements_version_id",
                    "definition",
                ],
            ],
            \Domain\DegreeAudit\Event\RequirementAdjustmentWasRevoked::class => [
                "type" => "student_requirement_adjustments",
                "root" => \Domain\DegreeAudit\RequirementAdjustments::class,
                "fields" => ["adjustment_id", "decision_id", "student_id", "program_id"],
            ],
            \Domain\DegreeAudit\Event\StudentRequirementsWereAssigned::class => [
                "type" => "student_degree_requirements",
                "root" => \Domain\DegreeAudit\StudentRequirements::class,
                "fields" => [
                    "decision_id",
                    "previous_decision_id",
                    "student_id",
                    "program_id",
                    "template_id",
                    "requirements_version_id",
                    "academic_year_id",
                    "cohort_catalog_id",
                    "cohort_binding_revision",
                    "pursuit_started_on",
                ],
            ],
            \Domain\Gradebook\Event\GradingScaleWasChanged::class => [
                "type" => "grading_scale",
                "root" => \Domain\Gradebook\GradingScale::class,
                "fields" => ["version_id", "previous_version_id", "definition"],
            ],
            \Domain\DegreeAudit\Event\CourseGroupWasPublished::class => [
                "type" => "course_group_publication",
                "root" => \Domain\DegreeAudit\CourseGroupPublication::class,
                "fields" => ["version_id", "previous_version_id", "definition"],
            ],
            \Domain\DegreeAudit\Event\RequirementsWerePublished::class => [
                "type" => "degree_audit_requirements",
                "root" => \Domain\DegreeAudit\RequirementsPublication::class,
                "fields" => [
                    "program_id",
                    "academic_year_id",
                    "version_id",
                    "previous_version_id",
                    "labels",
                    "definition",
                ],
            ],
            \Domain\Registration\Event\SectionCourseVersionWasBound::class => [
                "type" => "section_course_binding",
                "root" => \Domain\Registration\SectionCourseBinding::class,
                "fields" => ["course_id", "course_version_id"],
            ],
            \Domain\Catalog\Event\CourseDefinitionWasPublished::class => [
                "type" => "course_publication",
                "root" => \Domain\Catalog\CoursePublication::class,
                "fields" => ["version_id", "previous_version_id", "definition"],
            ],
            \Domain\Finance\Event\StudentAccountWasOpened::class => [
                "type" => "student_account",
                "root" => \Domain\Finance\StudentAccount::class,
                "fields" => ["student_id", "term_id", "currency"],
            ],
            \Domain\Finance\Event\AccountEntryWasPosted::class => [
                "type" => "student_account",
                "root" => \Domain\Finance\StudentAccount::class,
                "fields" => ["operation_id", "actor_id", "type", "minor", "currency"],
            ],
            \Domain\Finance\Event\AccountEntryWasReversed::class => [
                "type" => "student_account",
                "root" => \Domain\Finance\StudentAccount::class,
                "fields" => ["operation_id", "actor_id"],
            ],
            \Domain\Finance\Event\BillingRuleWasDefined::class => [
                "type" => "billing_rule",
                "root" => \Domain\Finance\BillingRule::class,
                "fields" => ["term_id", "program_id", "name", "kind", "minor", "currency", "actor_id"],
            ],
            \Domain\Finance\Event\BillingRuleWasRetired::class => [
                "type" => "billing_rule",
                "root" => \Domain\Finance\BillingRule::class,
                "fields" => ["actor_id"],
            ],
            \Domain\Finance\Event\JournalWasPosted::class => [
                "type" => "journal",
                "root" => \Domain\Finance\Journal::class,
                "fields" => ["reference", "posted_on", "currency", "lines", "actor_id"],
            ],
            \Domain\Finance\Event\JournalWasReversed::class => [
                "type" => "journal",
                "root" => \Domain\Finance\Journal::class,
                "fields" => ["actor_id"],
            ],
            \Domain\User\Event\UserWasCreated::class => [
                "type" => "user",
                "root" => \Domain\User\User::class,
                "fields" => [
                    "user_id",
                    "person_id",
                    "username",
                    "token",
                    "first_name",
                    "middle_name",
                    "last_name",
                    "email",
                    "role",
                    "password",
                    "created_on",
                ],
            ],
            \Domain\User\Event\UserWasDeleted::class => [
                "type" => "user",
                "root" => \Domain\User\User::class,
                "fields" => [],
            ],
            \Domain\User\Event\EmailAddressWasChanged::class => [
                "type" => "user",
                "root" => \Domain\User\User::class,
                "fields" => ["email"],
            ],
            \Domain\User\Event\NameWasChanged::class => [
                "type" => "user",
                "root" => \Domain\User\User::class,
                "fields" => ["first_name", "middle_name", "last_name"],
            ],
            \Domain\User\Event\PasswordWasChanged::class => [
                "type" => "user",
                "root" => \Domain\User\User::class,
                "fields" => ["password", "token"],
            ],
            \Domain\User\Event\RoleWasChanged::class => [
                "type" => "user",
                "root" => \Domain\User\User::class,
                "fields" => ["role"],
            ],
            default => throw new CorruptHistoryException(),
        };
    }

    /** Frozen serialization checks; never call current business commands or publish during replay.
     * @param array<mixed> $payload
     * @param array<mixed> $metadata
     */
    private function validate(
        StreamReference $stream,
        string $class,
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] array $metadata,
        int $version,
    ): void {
        try {
            $definition = $this->definition($class);
            if ($class === \Domain\DegreeAudit\Event\RequirementsWerePublished::class && $version === 4) {
                $definition["fields"][] = "grade_scale";
            }
            if ($stream->type === "student_primary_program") {
                \Domain\Student\ValueObject\PrimaryProgramSchema::validate($class, $payload);
                if (
                    $class === \Domain\Student\Event\PrimaryProgramWasObserved::class &&
                    $payload["student_id"] !== (string) $stream->aggregateId
                ) {
                    throw new CorruptHistoryException();
                }
            }
            if ($stream->type === "student_program_status") {
                \Domain\Student\ValueObject\StudentProgramStatusSchema::validate($class, $payload);
                if (
                    $class === \Domain\Student\Event\StudentProgramWasObserved::class &&
                    $payload["assignment"]["id"] !== (string) $stream->aggregateId
                ) {
                    throw new CorruptHistoryException();
                }
            }
            if ($stream->type === "student_activity") {
                \Domain\Student\ValueObject\StudentActivitySchema::validate($class, $payload);
                if ($payload["student_id"] !== (string) $stream->aggregateId) {
                    throw new CorruptHistoryException();
                }
            }
            if ($stream->type === "enrollment") {
                \Domain\Registration\ValueObject\EnrollmentEventSchema::validate($class, $payload);
                if (
                    $class === \Domain\Registration\Event\EnrollmentWasEstablished::class &&
                    $payload["enrollment"]["id"] !== (string) $stream->aggregateId
                ) {
                    throw new CorruptHistoryException();
                }
            }
            if ($stream->type === "transfer_credit") {
                \Domain\Transfer\ValueObject\TransferEventSchema::validate($class, $payload);
                if (
                    $class === \Domain\Transfer\Event\TransferCreditWasEstablished::class &&
                    $payload["source"]["award_id"] !== (string) $stream->aggregateId
                ) {
                    throw new CorruptHistoryException();
                }
            }
            if ($stream->type === "academic_credit") {
                \Domain\AcademicRecord\ValueObject\AcademicEventSchema::validate($class, $payload);
                if (
                    $class === \Domain\AcademicRecord\Event\InstitutionalCreditWasEstablished::class &&
                    $payload["credit"]["id"] !== (string) $stream->aggregateId
                ) {
                    throw new CorruptHistoryException();
                }
            }
            // Earliest User v1 events predate the optional person link.
            if (
                $class === \Domain\User\Event\UserWasCreated::class &&
                !array_key_exists("person_id", $payload)
            ) {
                $payload["person_id"] = null;
            }
            if (
                $definition["type"] !== $stream->type ||
                $definition["root"] !== $stream->aggregateId->aggregateClassName() ||
                count($payload) !== count($definition["fields"]) ||
                array_diff(array_keys($payload), $definition["fields"]) !== []
            ) {
                throw new CorruptHistoryException();
            }
            if ($class === \Domain\Gradebook\Event\GradingScaleWasChanged::class) {
                if (
                    (string) $stream->aggregateId ===
                        \Domain\Gradebook\Service\GradingScaleService::GLOBAL_ID &&
                    $payload["definition"] === null
                ) {
                    throw new CorruptHistoryException();
                }
                if ($payload["version_id"] === $payload["previous_version_id"]) {
                    throw new CorruptHistoryException();
                }
                if ($payload["definition"] !== null) {
                    if (!is_array($payload["definition"])) {
                        throw new CorruptHistoryException();
                    }
                    $scale = new \Domain\Gradebook\ValueObject\GradingScaleDefinition($payload["definition"]);
                    if ($scale->definition["grades"] === []) {
                        throw new CorruptHistoryException();
                    }
                }
            }
            if ($class === \Domain\DegreeAudit\Event\RequirementAdjustmentWasGranted::class) {
                if (
                    !is_array($payload["definition"]) ||
                    !is_string($payload["program_id"]) ||
                    $payload["adjustment_id"] === $payload["supersedes_adjustment_id"]
                ) {
                    throw new CorruptHistoryException();
                }
                if ($version === 1) {
                    new \Domain\DegreeAudit\ValueObject\RequirementAdjustmentDefinition(
                        $payload["definition"],
                    );
                } else {
                    new \Domain\DegreeAudit\ValueObject\RequirementAdjustmentDefinitionV2(
                        $payload["definition"],
                    );
                }
            }
            if (
                $class === \Domain\DegreeAudit\Event\RequirementAdjustmentWasRevoked::class &&
                (!is_string($payload["program_id"]) || $payload["decision_id"] === $payload["adjustment_id"])
            ) {
                throw new CorruptHistoryException();
            }
            if ($class === \Domain\DegreeAudit\Event\StudentRequirementsWereAssigned::class) {
                if (
                    !is_string($payload["program_id"]) ||
                    !is_int($payload["cohort_binding_revision"]) ||
                    $payload["cohort_binding_revision"] < 0 ||
                    !is_string($payload["pursuit_started_on"]) ||
                    $payload["decision_id"] === $payload["previous_decision_id"]
                ) {
                    throw new CorruptHistoryException();
                }
                new \Domain\DegreeAudit\ValueObject\PursuitStart($payload["pursuit_started_on"]);
            }
            if ($class === \Domain\DegreeAudit\Event\CourseGroupWasPublished::class) {
                if (
                    !is_array($payload["definition"]) ||
                    $payload["version_id"] === $payload["previous_version_id"]
                ) {
                    throw new CorruptHistoryException();
                }
                $group =
                $version === 1
                ? new \Domain\DegreeAudit\ValueObject\CourseGroupDefinition($payload["definition"])
                : new \Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2($payload["definition"]);
                $group->assertPublishable();
            }
            if ($class === \Domain\DegreeAudit\Event\RequirementsWerePublished::class) {
                if (
                    !is_string($payload["program_id"]) ||
                    !is_array($payload["definition"]) ||
                    !is_array($payload["labels"]) ||
                    count($payload["labels"]) !== 3 ||
                    array_diff(array_keys($payload["labels"]), [
                        "program_code",
                        "program_title",
                        "academic_year_code",
                    ]) !== [] ||
                    $payload["version_id"] === $payload["previous_version_id"]
                ) {
                    throw new CorruptHistoryException();
                }
                foreach ($payload["labels"] as $label) {
                    if (
                        !is_string($label) ||
                        trim($label) === "" ||
                        mb_strlen($label) > 191 ||
                        !mb_check_encoding($label, "UTF-8") ||
                        preg_match('/[\x00-\x1F\x7F]/', $label)
                    ) {
                        throw new CorruptHistoryException();
                    }
                }
                $requirements = match ($version) {
                    1 => new \Domain\DegreeAudit\ValueObject\AuditRequirements($payload["definition"]),
                    2 => new \Domain\DegreeAudit\ValueObject\AuditRequirementsV2($payload["definition"]),
                    3, 4 => new \Domain\DegreeAudit\ValueObject\AuditRequirementsV3($payload["definition"]),
                    default => throw new CorruptHistoryException(),
                };
                $requirements->assertPublishable();
                if ($version === 4) {
                    if (!is_array($payload["grade_scale"])) {
                        throw new CorruptHistoryException();
                    }
                    $snapshot = new \Domain\Gradebook\ValueObject\GradingScaleSnapshot(
                        $payload["grade_scale"],
                    );
                    if (
                        $snapshot->data["program_id"] !== null &&
                        $snapshot->data["program_id"] !== $payload["program_id"]
                    ) {
                        throw new CorruptHistoryException();
                    }
                }
            }
            foreach ($payload as $name => $value) {
                if (str_ends_with((string) $name, "_id")) {
                    if (
                        $class === \Domain\Student\Event\StudentActivityWasDecided::class &&
                        $name === "reference_id" &&
                        $value === null
                    ) {
                        continue;
                    }
                    if (
                        $class === \Domain\AcademicRecord\Event\FinalGradeWasDecided::class &&
                        $name === "assignment_id" &&
                        $value === null
                    ) {
                        continue;
                    }
                    if (
                        in_array(
                            $name,
                            [
                                "program_id",
                                "person_id",
                                "previous_version_id",
                                "previous_decision_id",
                                "supersedes_adjustment_id",
                            ],
                            true,
                        ) &&
                        $value === null
                    ) {
                        continue;
                    }
                    if (!is_string($value)) {
                        throw new CorruptHistoryException();
                    }
                    new \Domain\Shared\ValueObject\EntityId($value);
                }
            }
            foreach ($metadata as $name => $value) {
                if ($name === "schema_version" && $value === $version) {
                    continue;
                }
                if (
                    !in_array(
                        $name,
                        [
                            "policy_version_id",
                            "course_version_id",
                            "section_version_id",
                            "curriculum_version_id",
                            "grading_scheme_version_id",
                        ],
                        true,
                    ) ||
                    !is_string($value)
                ) {
                    throw new CorruptHistoryException();
                }
                new \Domain\Shared\ValueObject\EntityId($value);
            }
            if (
                array_key_exists("currency", $payload) &&
                (!is_string($payload["currency"]) || preg_match('/^[A-Z]{3}$/D', $payload["currency"]) !== 1)
            ) {
                throw new CorruptHistoryException();
            }
            if (
                array_key_exists("minor", $payload) &&
                (!is_int($payload["minor"]) || $payload["minor"] < 1 || $payload["minor"] > 2000000000)
            ) {
                throw new CorruptHistoryException();
            }
            if (
                array_key_exists("type", $payload) &&
                !in_array($payload["type"], ["charge", "payment", "refund"], true)
            ) {
                throw new CorruptHistoryException();
            }
            if (
                array_key_exists("name", $payload) &&
                (!is_string($payload["name"]) ||
                    !mb_check_encoding($payload["name"], "UTF-8") ||
                    trim($payload["name"]) === "" ||
                    mb_strlen($payload["name"]) > 191 ||
                    !in_array($payload["kind"], ["fee", "tuition"], true))
            ) {
                throw new CorruptHistoryException();
            }
            if ($definition["type"] === "course_publication") {
                $this->courseDefinition($payload, $stream, $version);
            }
            if ($definition["type"] === "user") {
                $this->user($payload, $stream);
            }
            if ($class === \Domain\Finance\Event\JournalWasPosted::class) {
                $this->journal($payload);
            }
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }

    /** @param array<mixed> $payload */
    private function user(#[\SensitiveParameter] array $payload, StreamReference $stream): void
    {
        foreach ($payload as $name => $value) {
            if ($name === "person_id" && $value === null) {
                continue;
            }
            if ($name === "created_on") {
                $normalized = json_decode(
                    ProtectedEventCodec::canonical(["date" => $value]),
                    true,
                    32,
                    JSON_THROW_ON_ERROR,
                )["date"];
                if (
                    is_array($normalized) &&
                    is_string($normalized["date"] ?? null) &&
                    is_string($normalized["timezone"] ?? null)
                ) {
                    new DateTimeImmutable($normalized["date"], new DateTimeZone($normalized["timezone"]));
                } elseif (is_string($normalized)) {
                    new DateTimeImmutable($normalized, new DateTimeZone("UTC"));
                } else {
                    throw new CorruptHistoryException();
                }
                continue;
            }
            if (!is_string($value) || !mb_check_encoding($value, "UTF-8") || strlen($value) > 4096) {
                throw new CorruptHistoryException();
            }
            if ($name === "user_id" && $value !== (string) $stream->aggregateId) {
                throw new CorruptHistoryException();
            }
            if ($name === "token") {
                new \Domain\User\ValueObject\UserToken($value);
            }
            if (in_array($name, ["username", "email", "password", "role"], true) && trim($value) === "") {
                throw new CorruptHistoryException();
            }
        }
    }

    /** @param array<mixed> $payload */
    private function courseDefinition(array $payload, StreamReference $stream, int $version): void
    {
        $value = $payload["definition"];
        if (
            !is_array($value) ||
            count($value) !== ($version === 1 ? 4 : 6) ||
            !is_string($value["code"] ?? null) ||
            !is_string($value["title"] ?? null) ||
            !is_int($value["credits_milli"] ?? null) ||
            !is_array($value["prerequisites"] ?? null) ||
            !array_is_list($value["prerequisites"]) ||
            count($value["prerequisites"]) > 100
        ) {
            throw new CorruptHistoryException();
        }
        foreach ($value["prerequisites"] as $id) {
            if (!is_string($id) || $id === (string) $stream->aggregateId) {
                throw new CorruptHistoryException();
            }
        }
        new \Domain\Catalog\ValueObject\CourseDefinition(
            $value["code"],
            $value["title"],
            $value["credits_milli"],
            $value["prerequisites"],
        );
        if ($version === 2) {
            \Domain\Catalog\ValueObject\CourseText::fromArray($value);
        }
        if ($payload["version_id"] === $payload["previous_version_id"]) {
            throw new CorruptHistoryException();
        }
    }

    public function streamFor(AggregateId $id): StreamReference
    {
        $type = match ($id->aggregateClassName()) {
            \Domain\Student\StudentPrimaryProgram::class => "student_primary_program",
            \Domain\Student\StudentProgramStatus::class => "student_program_status",
            \Domain\Student\StudentActivity::class => "student_activity",
            \Domain\Registration\EnrollmentHistory::class => "enrollment",
            \Domain\Transfer\TransferCredit::class => "transfer_credit",
            \Domain\AcademicRecord\AcademicCredit::class => "academic_credit",
            \Domain\DegreeAudit\RequirementAdjustments::class => "student_requirement_adjustments",
            \Domain\DegreeAudit\StudentRequirements::class => "student_degree_requirements",
            \Domain\DegreeAudit\CourseGroupPublication::class => "course_group_publication",
            \Domain\DegreeAudit\RequirementsPublication::class => "degree_audit_requirements",
            \Domain\Registration\SectionCourseBinding::class => "section_course_binding",
            \Domain\Catalog\CoursePublication::class => "course_publication",
            \Domain\Finance\StudentAccount::class => "student_account",
            \Domain\Finance\BillingRule::class => "billing_rule",
            \Domain\Finance\Journal::class => "journal",
            \Domain\User\User::class => "user",
            default => throw new CorruptHistoryException(),
        };
        return new StreamReference($id, $type);
    }

    public function stream(string $type, string $id): StreamReference
    {
        $aggregateId = match ($type) {
            "student_primary_program" => new \Domain\Student\ValueObject\StudentPrimaryProgramId($id),
            "student_program_status" => new \Domain\Student\ValueObject\StudentProgramStatusId($id),
            "student_activity" => new \Domain\Student\ValueObject\StudentActivityId($id),
            "enrollment" => new \Domain\Registration\ValueObject\EnrollmentHistoryId($id),
            "transfer_credit" => new \Domain\Transfer\ValueObject\TransferCreditId($id),
            "academic_credit" => new \Domain\AcademicRecord\ValueObject\AcademicCreditId($id),
            "student_requirement_adjustments" => new \Domain\DegreeAudit\ValueObject\RequirementAdjustmentsId(
                $id,
            ),
            "student_degree_requirements" => new \Domain\DegreeAudit\ValueObject\StudentRequirementsId($id),
            "course_group_publication" => new \Domain\DegreeAudit\ValueObject\CourseGroupPublicationId($id),
            "degree_audit_requirements" => new \Domain\DegreeAudit\ValueObject\RequirementsPublicationId($id),
            "section_course_binding" => new \Domain\Registration\ValueObject\SectionCourseBindingId($id),
            "course_publication" => new \Domain\Catalog\ValueObject\CoursePublicationId($id),
            "student_account" => new \Domain\Finance\ValueObject\AccountId($id),
            "billing_rule" => new \Domain\Finance\ValueObject\BillingRuleId($id),
            "journal" => new \Domain\Finance\ValueObject\JournalId($id),
            "user" => new \Domain\User\ValueObject\UserId($id),
            default => throw new CorruptHistoryException(),
        };
        return $this->streamFor($aggregateId);
    }

    /** @param array<mixed> $payload */
    private function journal(#[\SensitiveParameter] array $payload): void
    {
        if (
            !is_string($payload["reference"]) ||
            preg_match('~^[A-Z0-9][A-Z0-9._/-]{0,79}$~D', $payload["reference"]) !== 1 ||
            !is_string($payload["posted_on"]) ||
            !is_array($payload["lines"]) ||
            !array_is_list($payload["lines"]) ||
            count($payload["lines"]) < 2 ||
            count($payload["lines"]) > 500
        ) {
            throw new CorruptHistoryException();
        }
        $on = DateTimeImmutable::createFromFormat("!Y-m-d", $payload["posted_on"]);
        if ($on === false || $on->format("Y-m-d") !== $payload["posted_on"]) {
            throw new CorruptHistoryException();
        }
        $balance = 0;
        $debits = 0;
        $accounts = [];
        foreach ($payload["lines"] as $line) {
            if (
                !is_array($line) ||
                count($line) !== 2 ||
                !is_string($line["account_id"] ?? null) ||
                !is_int($line["minor"] ?? null) ||
                $line["minor"] === 0 ||
                abs($line["minor"]) > 2000000000
            ) {
                throw new CorruptHistoryException();
            }
            new \Domain\Shared\ValueObject\EntityId($line["account_id"]);
            $balance += $line["minor"];
            $debits += max(0, $line["minor"]);
            $accounts[$line["account_id"]] = true;
        }
        if ($balance !== 0 || $debits > 2000000000 || count($accounts) < 2) {
            throw new CorruptHistoryException();
        }
    }

    /** @return array<string, mixed> */
    public function describe(#[\SensitiveParameter] DomainEvent $event): array
    {
        $version = $event->metaParam("schema_version", 1);
        if (!is_int($version)) {
            throw new CorruptHistoryException();
        }
        $this->resolve($event::class, $event->eventType(), $version);
        $metadata = array_diff_key(
            $event->metadata(),
            array_flip([
                Metadata::AGGREGATE_ID,
                Metadata::AGGREGATE_TYPE,
                Metadata::AGGREGATE_PLAYHEAD,
                Metadata::EVENT_ID,
                Metadata::EVENT_TYPE,
                Metadata::RECORDED_AT,
            ]),
        );
        $this->validate(
            new StreamReference($event->aggregateId(), $event->metaParam(Metadata::AGGREGATE_TYPE)),
            $event::class,
            $event->payload(),
            $metadata,
            $version,
        );
        if (
            strlen(
                DateTimeImmutable::createFromInterface($event->recordedAt())
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format("Y-m-d\TH:i:s.u\Z"),
            ) !== 27
        ) {
            throw new CorruptHistoryException();
        }
        return [
            "class" => $event::class,
            "type" => $event->eventType(),
            "schema_version" => $version,
            "payload" => $event->payload(),
            "metadata" => $metadata,
            "source_recorded_at" => DateTimeImmutable::createFromInterface($event->recordedAt())
                ->setTimezone(new DateTimeZone("UTC"))
                ->format("Y-m-d\TH:i:s.u\Z"),
        ];
    }

    /** @param array<string, mixed> $data */
    public function restore(
        StreamReference $stream,
        string $eventId,
        int $position,
        #[\SensitiveParameter] array $data,
    ): DomainEvent {
        if (
            !is_string($data["class"] ?? null) ||
            !is_string($data["type"] ?? null) ||
            !is_int($data["schema_version"] ?? null) ||
            !is_array($data["payload"] ?? null) ||
            !is_array($data["metadata"] ?? null) ||
            !is_string($data["source_recorded_at"] ?? null)
        ) {
            throw new CorruptHistoryException();
        }
        $class = $this->resolve($data["class"], $data["type"], $data["schema_version"]);
        $this->validate($stream, $class, $data["payload"], $data["metadata"], $data["schema_version"]);
        $date = DateTimeImmutable::createFromFormat(
            "!Y-m-d\TH:i:s.u\Z",
            $data["source_recorded_at"],
            new DateTimeZone("UTC"),
        );
        if ($date === false || $date->format("Y-m-d\TH:i:s.u\Z") !== $data["source_recorded_at"]) {
            throw new CorruptHistoryException();
        }
        if (($data["metadata"]["schema_version"] ?? 1) !== $data["schema_version"]) {
            throw new CorruptHistoryException();
        }
        // Framework metadata is reconstructed from verified envelope fields; custom metadata cannot override it.
        return $class::fromArray([
            "aggregateId" => $stream->aggregateId,
            "payload" => $data["payload"],
            "metadata" =>
                [
                    Metadata::AGGREGATE_ID => $stream->aggregateId,
                    Metadata::AGGREGATE_TYPE => $stream->type,
                    Metadata::AGGREGATE_PLAYHEAD => $position,
                    Metadata::EVENT_ID => new EventId($eventId),
                    Metadata::EVENT_TYPE => $data["type"],
                    Metadata::RECORDED_AT => $date,
                ] + $data["metadata"],
        ]);
    }
}
