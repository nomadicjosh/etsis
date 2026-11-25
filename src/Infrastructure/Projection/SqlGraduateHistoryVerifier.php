<?php

declare(strict_types=1);

namespace Infrastructure\Projection;

use Domain\Registration\GraduateRegistrationPolicy;
use Domain\Registration\Repository\GraduateHistoryVerifier;
use Domain\Registration\Repository\GraduateRegistrationPolicyState;
use Domain\Registration\Repository\SectionVersionRepository;
use Domain\Registration\Service\GraduateApprovalLedger;
use Domain\Registration\Service\GraduateEvidenceReader;
use Domain\Registration\Service\RetainedSectionReader;
use Domain\Registration\ValueObject\GraduateCourseScope;
use Domain\Registration\ValueObject\GraduateHistoryCheck;
use Domain\Registration\ValueObject\GraduateRegistrationPolicyId;
use Domain\Registration\ValueObject\GraduateRegistrationRules;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

/** Observe protected decisions and retained references outside snapshots. Never authorize, lock or project. */
final readonly class SqlGraduateHistoryVerifier implements GraduateHistoryVerifier
{
    public function __construct(
        private SqlRecords $sql,
        private HistoricalEventStore $history,
        private GraduateRegistrationPolicyState $policyState,
        private GraduateApprovalLedger $approvals,
        private GraduateEvidenceReader $references,
        private RetainedSectionReader $sections,
        private SectionVersionRepository $sectionVersions,
        private \Infrastructure\Persistence\Repository\SqlCourseVersionProjection $courseVersions,
    ) {
    }

    public function policy(int $eventLimit = 500): GraduateHistoryCheck
    {
        return $this->verify("gradpolicy", new EntityId(GraduateRegistrationPolicy::ID), $eventLimit);
    }

    public function approvals(EntityId $pursuit, int $eventLimit = 500): GraduateHistoryCheck
    {
        return $this->verify("graduate", $pursuit, $eventLimit);
    }

    private function verify(string $kind, EntityId $id, int $limit): GraduateHistoryCheck
    {
        if ($limit < 1 || $limit > 2000) {
            throw new \InvalidArgumentException("Use a graduate history allowance of 1–2000 events.");
        }
        if ($this->sql->inTransaction()) {
            return new GraduateHistoryCheck($kind, $id, "unverified", 0, issues: ["unavailable"]);
        }
        $budget = new HistoryReadBudget(
            maximumEvents: $limit,
            maximumBytes: 4_194_304,
            maximumMilliseconds: 5000,
        );
        $before = $sourceBefore = null;
        $selectors = $scopes = [];
        $records = null;
        $status = "unverified";
        $issue = "graduate_history";
        $issues = [];
        try {
            if ($this->sql->tableExists("event_store")) {
                throw new CorruptHistoryException();
            }
            $before = $this->boundary($kind, $id);
            if ($kind === "gradpolicy") {
                $records = count($this->policySource($budget)->records());
            } else {
                $issue = "pursuit_identity";
                if (
                    count($before["pursuit"]) !== 1 ||
                    count($before["student"]) !== 1 ||
                    count($before["person"]) !== 1
                ) {
                    throw new CorruptHistoryException();
                }
                $issue = "graduate_history";
                $root = $this->approvals->read($id, $budget);
                $decisions = $root->decisions();
                $issue = "pursuit_identity";
                foreach ($decisions as $decision) {
                    $scope = new GraduateCourseScope($decision["scope"]);
                    $data = $scope->data;
                    if (
                        $data["student_id"] !== $before["student"][0]["id"] ||
                        $data["pursuit_id"] !== $id->value ||
                        $data["program_id"] !== $before["pursuit"][0]["program_id"]
                    ) {
                        throw new CorruptHistoryException();
                    }
                    // All original decisions, including revoked ones, retain authentic definitions.
                    // GPA totals/attempt hashes remain accepted evidence, not a fresh historical GPA calculation.
                    $key = GraduateRegistrationRules::hash([
                        $scope->binding(),
                        $data["calculation_reference"],
                    ]);
                    $scopes[$key] = $scope;
                    if (count($scopes) > 200) {
                        throw new HistoryReadLimitException();
                    }
                }
                $selectors = $this->selectors($scopes);
                $sourceBefore = $this->inventory($selectors, $budget);
                $issue = "retained_reference";
                if ($scopes !== []) {
                    $this->policySource($budget);
                }
                $sectionReferences = [];
                foreach ($scopes as $scope) {
                    $published = $this->references->verifyScope($scope, $budget);
                    $cached = $this->courseVersions->get($published->versionId);
                    if (
                        GraduateRegistrationRules::hash($cached->describe()) !==
                        GraduateRegistrationRules::hash($published->describe())
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $d = $scope->data;
                    $sectionReferences[$d["section_id"] . ":" . $d["section_version_id"]] = [
                        "section_id" => $d["section_id"],
                        "version_id" => $d["section_version_id"],
                    ];
                }
                if ($sectionReferences !== []) {
                    $definitions = $this->sections->definitions(array_values($sectionReferences), $budget);
                    foreach ($scopes as $scope) {
                        $d = $scope->data;
                        $definition = $definitions[$d["section_id"]][$d["section_version_id"]] ?? null;
                        $cached = $this->sectionVersions->find(new EntityId($d["section_version_id"]));
                        if (
                            $definition === null ||
                            $cached === null ||
                            GraduateRegistrationRules::hash($cached->definition->data) !==
                                GraduateRegistrationRules::hash($definition) ||
                            $cached->section->value !== $d["section_id"] ||
                            $definition["course_id"] !== $d["course_id"] ||
                            $definition["course_version_id"] !== $d["course_version_id"]
                        ) {
                            throw new CorruptHistoryException();
                        }
                    }
                }
                $records = count($decisions);
            }
            $budget->checkTime();
            $status = $records === 0 ? "observed_empty" : "verified";
        } catch (HistoryReadLimitException) {
            $status = "limited";
        } catch (CorruptHistoryException) {
            $issues = [$issue];
        } catch (\Throwable) {
            $issues = ["unavailable"];
        }
        if ($before !== null) {
            try {
                if (
                    $before !== $this->boundary($kind, $id) ||
                    ($sourceBefore !== null && $sourceBefore !== $this->inventory($selectors, $budget))
                ) {
                    $status = "changed";
                    $issues = [];
                }
                $budget->checkTime();
            } catch (HistoryReadLimitException) {
                if ($status !== "changed") {
                    $status = "limited";
                    $issues = [];
                }
            } catch (\Throwable) {
                $status = "unverified";
                $issues = ["unavailable"];
            }
        }
        return new GraduateHistoryCheck(
            $kind,
            $id,
            $status,
            $budget->eventsRead(),
            in_array($status, ["verified", "observed_empty"], true) ? $records : null,
            $issues,
        );
    }

    private function policySource(HistoryReadBudget $budget): GraduateRegistrationPolicy
    {
        $stream = new StreamReference(
            new GraduateRegistrationPolicyId(GraduateRegistrationPolicy::ID),
            "graduate_registration_policy",
        );
        $root = GraduateRegistrationPolicy::root($stream->aggregateId);
        $cursor = null;
        do {
            $page = $budget->page($this->history, $stream, $cursor);
            foreach ($page->events as $event) {
                $root->accept($event);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if (
            $root->playhead() !== $page->throughVersion ||
            $root->playhead() !== $this->policyState->current()
        ) {
            throw new CorruptHistoryException();
        }
        return $root;
    }

    private function boundary(string $kind, EntityId $id): array
    {
        $type = $kind === "gradpolicy" ? "graduate_registration_policy" : "graduate_course_approvals";
        $table = match ($kind) {
            'gradpolicy' => 'sis_graduate_registration_policy_state',
            default => 'sis_graduate_course_approvals',
        };
        $pursuit = $kind === 'gradpolicy' ? [] : $this->sql->rows('sis_student_programs', ['id' => $id->value], 2);
        $student = $pursuit === [] ? [] : $this->sql->rows('sis_students', ['id' => $pursuit[0]['student_id']], 2);
        $person = $student === [] ? [] : $this->sql->rows('sis_persons', ['id' => $student[0]['person_id']], 2);
        $student = array_map(
            static fn(array $row): array => array_intersect_key($row, array_flip(["id", "person_id"])),
            $student,
        );
        $person = array_map(
            static fn(array $row): array => array_intersect_key($row, array_flip(["id", "owner_user_id"])),
            $person,
        );
        return [
            "pursuit" => $pursuit,
            "student" => $student,
            "person" => $person,
            "counter" => $this->sql->rows($table, ["id" => $id->value], 2),
            "head" => $this->sql->rows(
                "sis_history_streams",
                ["stream_type" => $type, "aggregate_id" => $id->value],
                2,
            ),
        ];
    }

    private function selectors(array $scopes): array
    {
        $selectors = [];
        $add = static function (string $table, array $where) use (&$selectors): void {
            $selectors[$table . ":" . GraduateRegistrationRules::hash($where)] = [$table, $where];
        };
        $courseTables = ['sis_course_versions', 'sis_course_version_classes', 'sis_course_version_subjects'];
        $programTables = ['sis_program_catalogs', 'sis_program_catalog_details', 'sis_program_catalog_ownership'];
        foreach ($scopes as $scope) {
            $d = $scope->data;
            $streams = [
                "graduate_registration_policy" => GraduateRegistrationPolicy::ID,
                "course_publication" => $d["course_id"],
                "program_publication" => $d["program_id"],
                "section_publication" => $d["section_id"],
                "degree_audit_requirements" => $d["calculation_reference"]["template_id"],
            ];
            foreach ($streams as $type => $aggregate) {
                $add("sis_history_streams", ["stream_type" => $type, "aggregate_id" => $aggregate]);
            }
            $add("sis_graduate_registration_policy_state", ["id" => GraduateRegistrationPolicy::ID]);
            foreach ($courseTables as $table) {
                $add($table, ["id" => $d["course_version_id"]]);
            }
            foreach ($programTables as $table) {
                $add($table, ["id" => $d["program_version_id"]]);
            }
            $add("sis_program_catalog_sites", ["catalog_id" => $d["program_version_id"]]);
            $add("sis_section_versions", ["id" => $d["section_version_id"]]);
        }
        if (count($selectors) > 200) {
            throw new HistoryReadLimitException();
        }
        ksort($selectors, SORT_STRING);
        return $selectors;
    }

    private function inventory(array $selectors, HistoryReadBudget $budget): array
    {
        $result = [];
        $bytes = 0;
        foreach ($selectors as $key => [$table, $where]) {
            $budget->checkTime();
            $rows = $this->sql->rows($table, $where, 201);
            if (count($rows) > 200) {
                throw new HistoryReadLimitException();
            }
            usort($rows, static fn(array $a, array $b): int => strcmp($a["id"], $b["id"]));
            $bytes += strlen(json_encode($rows, JSON_THROW_ON_ERROR));
            if ($bytes > 4_194_304) {
                throw new HistoryReadLimitException();
            }
            $result[$key] = $rows;
        }
        return $result;
    }
}
