<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Codefy\Domain\EventSourcing\EventStream;
use Domain\Catalog\Service\CoursePublicationService;
use Domain\Registration\Event\SectionDefinitionWasPublished;
use Domain\Registration\Section;
use Domain\Registration\SectionPublication;
use Domain\Registration\Service\SectionCourseBindingService;
use Domain\Registration\Service\SectionPublicationService;
use Domain\Registration\ValueObject\SectionDefinitionV1;
use Domain\Registration\ValueObject\SectionPublicationId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlCatalogWorkflowRepository;
use Infrastructure\Persistence\Repository\SqlCourseTextProjection;
use Infrastructure\Persistence\Repository\SqlSectionCourseBindingRepository;
use PHPUnit\Framework\TestCase;

final class MoodleSectionSyncTest extends TestCase
{
    private SisFixture $f;
    private Section $section;
    private SectionPublicationService $service;
    private SqlHistoricalEventStore $history;
    private CoursePublicationService $courses;
    private SqlCatalogRevisionRepository $catalog;

    protected function setUp(): void
    {
        $this->f = $f = new SisFixture();
        $this->section = $f->section();
        $this->catalog = new SqlCatalogRevisionRepository($f->sql);
        $this->history = new SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new ProtectedEventCodec(
                EventStoreFixture::config($f->connection->queryBuilder()),
            ),
            new HistoricalSchemaRegistry(),
        );
        $relationships = new SqlCatalogWorkflowRepository($f->sql);
        $this->courses = new CoursePublicationService(
            $this->catalog,
            $relationships,
            $this->history,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
            new SqlCourseTextProjection($f->sql),
            new \Infrastructure\Persistence\Repository\SqlSubjectRepository($f->sql),
            new \Infrastructure\Persistence\Repository\SqlClassificationRepository($f->sql),
        );
        $bindings = new SqlSectionCourseBindingRepository($f->sql);
        $review = $this->courses->review(
            $this->section->courseId,
            "Fictional course publication",
        );
        $version = $this->courses->publish(
            $this->section->courseId,
            $review["review_token"],
        );
        $bind = new SectionCourseBindingService(
            $this->catalog,
            $this->courses,
            $bindings,
            $this->history,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
        );
        $proof = $bind->review(
            $this->section->id,
            new EntityId($version["operation_id"]),
            "Fictional binding",
        );
        $bind->commit($this->section->id, $proof["review_token"]);
        $this->service = new SectionPublicationService(
            $this->catalog,
            $relationships,
            $bindings,
            $this->courses,
            $this->history,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
            new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($f->sql),
        );
    }

    private function context(): array
    {
        $f=$this->f;
        $review=$this->service->review($this->section->id,'2026-08-24','2026-12-18','Fictional published section');
        $publication=$this->service->publish($this->section->id,$review['review_token']);
        $selection=$this->service->reviewSelection($this->section->id,new EntityId($publication['operation_id']),'Fictional selected section');
        $this->service->select($this->section->id,$selection['review_token']);
        $policy=new class($f->access->actor) implements \Domain\Lms\Service\MoodleSectionSyncPolicy {
            public bool $on=true;
            public function __construct(private EntityId $id) {}
            public function enabled(): bool { return $this->on; }
            public function actor(): EntityId { return $this->id; }
            public function beforeDays(): int { return 14; }
            public function afterDays(): int { return 7; }
        };
        $repo=new \Infrastructure\Lms\SqlMoodleSectionRepository($f->sql,$f->registration,new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($f->sql),$this->history,$f->admissions,$f->enrollmentLedger(),$f->activityLedger(),$f->transactions,$policy,$f->clock);
        $bindings=new \Infrastructure\Lms\SqlMoodleBindingRepository($f->sql);
        $binding=new \Domain\Lms\ValueObject\MoodleBinding(EntityId::generate(),str_repeat('a',64),str_repeat('b',64),$f->access->actor,'2026-08-10T17:00:00Z'); $bindings->add($binding,'fictional-encrypted');
        $remote=new class($f) implements \Domain\Lms\Service\MoodleSectionGateway {
            public array $members=[]; public ?Closure $duringCall=null; public bool $fail=false;
            public function __construct(private SisFixture $f) {}
            public function rosterPolicy(\Domain\Lms\ValueObject\MoodleBinding $binding): array { TestCase::assertFalse($this->f->connection->pdo->inTransaction()); return ['policy_hash'=>str_repeat('c',64)]; }
            public function syncMember(\Domain\Lms\ValueObject\MoodleBinding $binding,string $policyHash,array $member): string {
                TestCase::assertFalse($this->f->connection->pdo->inTransaction()); $this->members[]=$member;
                if ($this->duringCall!==null) ($this->duringCall)(); if ($this->fail) throw new RuntimeException('fictional-private-upstream'); return 'synchronized';
            }
        };
        $lookup=$this->createStub(\Domain\Lms\Service\MoodleBoundRecordReader::class);
        $lookup->method('inspectBound')->willReturn(new \Domain\Lms\ValueObject\MoodleRecordCheck(\Domain\Lms\ValueObject\MoodleResourceKind::Course,true));
        $people=new \Infrastructure\Lms\SqlMoodlePersonLinkRepository($f->sql);
        $roles=new \Infrastructure\Persistence\Repository\SqlAccessRepository($f->sql);
        $roles->assign(\Domain\Access\Enum\GrantKind::UserRole,$f->access->actor,$roles->roleNamed('super')->id,true);
        $service=new \Domain\Lms\Service\MoodleSectionSyncService($repo,$remote,$lookup,$bindings,$people,$policy,$roles,$f->access,$f->transactions,$f->clock,$f->dropCipher,$f->audit);
        return [$service,$repo,$remote,$policy,$binding,$people,$roles];
    }
    private function studentLink(array $context): \Domain\Student\Student
    {
        $f=$this->f; $student=$f->student(); $f->service->register($student->id,$this->section->id,true);
        $owner=$f->admissions->person($student->personId)->ownerUserId;
        $context[6]->assign(\Domain\Access\Enum\GrantKind::UserRole,$owner,$context[6]->roleNamed('student')->id,true);
        $context[5]->add(new \Domain\Lms\ValueObject\MoodlePersonLink(EntityId::generate(),$context[4]->id,$student->personId,new \Domain\Lms\ValueObject\MoodleExternalId('501'),$f->access->actor,'2026-08-10T17:00:00Z'),'fictional-person-link');
        return $student;
    }
    public function testPublishedSectionLinksOnceAndSynchronizesStudentWithoutAcademicMutations(): void
    {
        $c=$this->context(); $student=$this->studentLink($c); $s=$c[0];
        $history=$this->f->sql->rows('sis_history_events');
        self::assertSame(['linked'=>true],$s->link($this->section->id,new \Domain\Lms\ValueObject\MoodleExternalId('81'),'Verified the matching Moodle course.',true));
        self::assertSame(['linked'=>true],$s->link($this->section->id,new \Domain\Lms\ValueObject\MoodleExternalId('81'),'Retry after response interruption.',true));
        self::assertCount(1,$this->f->sql->rows('sis_moodle_section_links'));
        self::assertSame('synchronized',$s->runOne());
        self::assertTrue($c[2]->members[0]['student']); self::assertFalse($c[2]->members[0]['instructor']);
        self::assertSame($student->personId->value,$c[2]->members[0]['person']);
        self::assertSame('cycle_completed',$s->runOne());
        self::assertSame($history,$this->f->sql->rows('sis_history_events'));
        self::assertStringNotContainsString('Alex',json_encode($c[2]->members));
        self::assertSame('81',$s->directory(substr($this->section->code,0,3))['items'][0]['moodle_course_id']);
    }
    public function testMissingAccountIsRetainedAsActionableSetupInsteadOfSkippingThePerson(): void
    {
        $c=$this->context(); $student=$this->f->student(); $this->f->service->register($student->id,$this->section->id,true);
        $owner=$this->f->admissions->person($student->personId)->ownerUserId;
        $c[6]->assign(\Domain\Access\Enum\GrantKind::UserRole,$owner,$c[6]->roleNamed('student')->id,true);
        $c[0]->link($this->section->id,new \Domain\Lms\ValueObject\MoodleExternalId('81'),'Verified course.',true);
        self::assertSame('setup_required',$c[0]->runOne());
        self::assertSame('account_setup_required',$this->f->sql->rows('sis_moodle_section_members')[0]['issue_code']);
        self::assertSame([],$c[2]->members);
        self::assertSame('cycle_completed',$c[0]->runOne());
        self::assertSame(1,$c[0]->directory($this->section->code)['items'][0]['members_needing_setup']);
        $c[5]->add(new \Domain\Lms\ValueObject\MoodlePersonLink(EntityId::generate(),$c[4]->id,$student->personId,new \Domain\Lms\ValueObject\MoodleExternalId('501'),$this->f->access->actor,'2026-08-10T17:00:00Z'),'fictional-person-link');
        $this->f->clock->time=$this->f->clock->time->modify('+61 seconds');
        self::assertSame('synchronized',$c[0]->runOne());
        self::assertSame(0,$c[0]->directory($this->section->code)['items'][0]['members_needing_setup']);
    }
    public function testRoleLossDuringEffectKeepsCursorForImmediateConvergence(): void
    {
        $c=$this->context(); $student=$this->studentLink($c);
        $owner=$this->f->admissions->person($student->personId)->ownerUserId;
        $c[0]->link($this->section->id,new \Domain\Lms\ValueObject\MoodleExternalId('81'),'Verified course.',true);
        $c[2]->duringCall=function() use($c,$owner): void { $c[6]->assign(\Domain\Access\Enum\GrantKind::UserRole,$owner,$c[6]->roleNamed('student')->id,false); };
        self::assertSame('source_changed',$c[0]->runOne());
        self::assertNull($this->f->sql->rows('sis_moodle_section_links')[0]['after_person']);
        $c[2]->duringCall=null;
        self::assertSame('setup_required',$c[0]->runOne());
        self::assertFalse($c[2]->members[1]['student']);
        self::assertGreaterThan($c[2]->members[0]['version'],$c[2]->members[1]['version']);
    }
    public function testDisabledWorkerAndMissingConfirmationMakeNoExternalChanges(): void
    {
        $c=$this->context(); $c[3]->on=false; self::assertSame('disabled',$c[0]->runOne()); self::assertSame([],$c[2]->members);
        $this->expectException(\Domain\Shared\Exception\ValidationException::class);
        $c[0]->link($this->section->id,new \Domain\Lms\ValueObject\MoodleExternalId('81'),'Verified course.',false);
    }
    public function testAnApprovedSectionCannotBeMovedToAnotherCourse(): void
    {
        $c=$this->context(); $c[0]->link($this->section->id,new \Domain\Lms\ValueObject\MoodleExternalId('81'),'Verified course.',true);
        $this->expectException(ConcurrentModificationException::class);
        $c[0]->link($this->section->id,new \Domain\Lms\ValueObject\MoodleExternalId('82'),'Different course.',true);
    }
    public function testUnknownOutcomeKeepsReferencesAndResumesWithAHigherFence(): void
    {
        $c=$this->context(); $this->studentLink($c); $c[0]->link($this->section->id,new \Domain\Lms\ValueObject\MoodleExternalId('81'),'Verified course.',true);
        $c[2]->fail=true; self::assertSame('review_required',$c[0]->runOne());
        self::assertCount(1,$this->f->sql->rows('sis_moodle_section_members')); self::assertNull($this->f->sql->rows('sis_moodle_section_links')[0]['after_person']);
        self::assertSame('idle',$c[0]->runOne());
        $this->f->clock->time=$this->f->clock->time->modify('+61 seconds'); $c[2]->fail=false;
        self::assertSame('synchronized',$c[0]->runOne()); self::assertGreaterThan($c[2]->members[0]['version'],$c[2]->members[1]['version']);
    }
    public function testEarlyDropRetainsARevocationCandidateAfterCurrentEnrollmentIsRemoved(): void
    {
        $c=$this->context(); $student=$this->studentLink($c);
        $c[0]->link($this->section->id,new \Domain\Lms\ValueObject\MoodleExternalId('81'),'Verified course.',true);
        self::assertSame('synchronized',$c[0]->runOne()); self::assertSame('cycle_completed',$c[0]->runOne());
        $this->f->clock->time=$this->f->clock->time->modify('+61 seconds');
        $this->f->drops->drop($student->id,$this->section->id);
        self::assertNull($this->f->registration->enrollment($student->id,$this->section->id));
        $history=$this->f->sql->rows('sis_history_events');
        self::assertSame('synchronized',$c[0]->runOne()); self::assertFalse($c[2]->members[1]['student']);
        self::assertSame($history,$this->f->sql->rows('sis_history_events'));
    }
    public function testPublishedInstructorAndCurrentFacultyRoleControlCourseAccess(): void
    {
        $owner=$this->f->user();
        $person=new \Domain\Person\Person(EntityId::generate(),$owner,'Fictional','Instructor',new \Domain\Person\ValueObject\InstitutionalId('FICTIONAL-FACULTY'));
        $this->f->admissions->addPerson($person);
        $roles=new \Infrastructure\Persistence\Repository\SqlAccessRepository($this->f->sql);
        $roles->assign(\Domain\Access\Enum\GrantKind::UserRole,$owner,$roles->roleNamed('faculty')->id,true);
        $this->f->sql->update('sis_sections',['faculty_user_id'=>$owner->value],['id'=>$this->section->id->value]);
        $c=$this->context();
        $c[5]->add(new \Domain\Lms\ValueObject\MoodlePersonLink(EntityId::generate(),$c[4]->id,$person->id,new \Domain\Lms\ValueObject\MoodleExternalId('502'),$this->f->access->actor,'2026-08-10T17:00:00Z'),'fictional-link');
        $c[0]->link($this->section->id,new \Domain\Lms\ValueObject\MoodleExternalId('81'),'Verified course.',true);
        self::assertSame('synchronized',$c[0]->runOne()); self::assertTrue($c[2]->members[0]['instructor']); self::assertFalse($c[2]->members[0]['student']);
        self::assertSame('cycle_completed',$c[0]->runOne()); $this->f->clock->time=$this->f->clock->time->modify('+61 seconds');
        $roles->assign(\Domain\Access\Enum\GrantKind::UserRole,$owner,$roles->roleNamed('faculty')->id,false);
        self::assertSame('setup_required',$c[0]->runOne()); self::assertFalse($c[2]->members[1]['instructor']);
    }
}
