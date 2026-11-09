<?php

declare(strict_types=1);

require_once __DIR__ . "/AcademicCalendarFixture.php";
require_once __DIR__ . "/CatalogWorkflowFixture.php";
use Domain\DegreeAudit\Service\CourseGroupService;
use Domain\DegreeAudit\Service\RequirementsService;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinition;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\Persistence\Repository\SqlCourseGroupRepository;
use Infrastructure\Persistence\Repository\SqlRequirementsRepository;
final class ReusableGroupFixture
{
    public CatalogWorkflowFixture $catalog;
    public SisFixture $sis;
    public CourseGroupService $groups;
    public RequirementsService $degrees;
    public SqlHistoricalEventStore $history;
    public EntityId $year;
    public EntityId $group;
    public EntityId $groupVersion;
    public EntityId $degree;
    public array $groupDefinition;
    public array $definition;
    public function __construct()
    {
        $this->catalog = new CatalogWorkflowFixture();
        $this->sis = $f = $this->catalog->sis;
        $a = new AcademicCalendarFixture($f);
        $a->type();
        $this->year = new EntityId($a->year(2027)["id"]);
        $this->history = new SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new ProtectedEventCodec(EventStoreFixture::config($f->connection->queryBuilder())),
            new HistoricalSchemaRegistry(),
        );
        $this->groups = $this->groupService();
        $this->degrees = $this->degreeService();
        $this->groupDefinition = CourseGroupDefinition::blank("General education")->definition;
        $this->groupDefinition["min_credits_milli"] = 12000;
        $ids = [];
        foreach (["MATH.101", "ENG.203", "CS.201", "CS.301", "CLIT.505"] as $code) {
            $id = EntityId::generate();
            $f->registration->addCourse(new \Domain\Catalog\Course($id, $code, "Fictional " . $code, 3000));
            $r = $this->catalog->publications()->review($id, "Fictional course approval");
            $v = $this->catalog->publications()->publish($id, $r["review_token"]);
            $this->groupDefinition["courses"][] = [
                "course_id" => $id->value,
                "version_id" => $v["operation_id"],
            ];
            $ids[] = $id->value;
        }
        foreach ([[$ids[0]], [$ids[1]], [$ids[2], $ids[3]], [$ids[4]]] as $courses) {
            $this->groupDefinition["rules"][] = [
                "id" => EntityId::generate()->value,
                "mode" => count($courses) === 1 ? "all" : "choose",
                "minimum_courses" => 1,
                "course_ids" => $courses,
            ];
        }
        $this->group = EntityId::generate();
        $this->groups->create($this->group, new CourseGroupDefinition($this->groupDefinition));
        $this->groupVersion = $this->publishGroup();
        $opened = $this->degrees->open($f->program, $this->year);
        $this->degree = new EntityId($opened["id"]);
        $this->definition = AuditRequirementsV3::blank("B.S. Computing")->definition;
        $this->definition["min_credits_milli"] = 120000;
        $this->definition["min_resident_milli"] = 30000;
        $this->definition["policies"] = [
            "repeat_credit" => "highest_earned",
            "repeat_gpa" => "all_eligible",
            "transfer_overall_gpa" => "exclude",
            "waiver" => "waive_required_course_everywhere",
        ];
        $this->definition["groups"] = [
            ["group_id" => $this->group->value, "version_id" => $this->groupVersion->value],
        ];
    }
    public function groupService(?\Domain\Audit\Repository\AuditTrail $audit = null): CourseGroupService
    {
        $f = $this->sis;
        return new CourseGroupService(
            new SqlCourseGroupRepository($f->sql),
            $this->catalog->publications(),
            $this->history,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $this->catalog->fixture->cipher,
            $f->clock,
            $this->gradingScales(),
        );
    }
    public function gradingScales(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): \Domain\Gradebook\Service\GradingScaleService {
        $f = $this->sis;
        return new \Domain\Gradebook\Service\GradingScaleService(
            new \Infrastructure\Persistence\Repository\SqlGradingScaleRepository($f->sql),
            $this->history,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $this->catalog->fixture->cipher,
            $f->clock,
        );
    }
    public function degreeService(?\Domain\Audit\Repository\AuditTrail $audit = null): RequirementsService
    {
        $f = $this->sis;
        return new RequirementsService(
            new SqlRequirementsRepository($f->sql),
            $this->catalog->publications(),
            $this->history,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $this->catalog->fixture->cipher,
            $f->clock,
            $this->groupService($audit),
        );
    }
    public function publishGroup(): EntityId
    {
        $r = $this->groups->review($this->group, "Fictional reusable group approval");
        return new EntityId($this->groups->publish($this->group, $r["review_token"])["operation_id"]);
    }
    public function saveDegree(): array
    {
        return $this->degrees->save($this->degree, 0, new AuditRequirementsV3($this->definition));
    }
    public function publishDegree(): array
    {
        $r = $this->degrees->review($this->degree, "Fictional degree approval");
        return $this->degrees->publish($this->degree, $r["review_token"]);
    }
    public function digest(string $table): string
    {
        return hash("sha256", json_encode($this->sis->sql->rows($table), JSON_THROW_ON_ERROR));
    }
    public function oldDefinition(bool $policies = true): array
    {
        $d = $this->definition;
        $g = $this->groupDefinition;
        $d["courses"] = $g["courses"];
        unset($g["courses"]);
        $g["id"] = EntityId::generate()->value;
        $d["groups"] = [$g];
        if (!$policies) {
            unset($d["policies"]);
        }
        return $d;
    }
}
