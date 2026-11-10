<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/AcademicCalendarFixture.php';

use Domain\AcademicCalendar\ValueObject\AcademicTerm;
use Domain\AcademicCalendar\ValueObject\TermType;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class AcademicCalendarTest extends TestCase
{
    private function student(AcademicCalendarFixture $a, string $term): \Domain\Student\Student
    {
        $f = $a->sis; $service = new \Domain\Admissions\Service\AdmissionsService(
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->audit,
            new \Infrastructure\Identity\RandomInstitutionalIdGenerator(),
        );
        $person = $service->createPerson($f->user(), 'Fictional', 'Student');
        $app = $service->apply($person->id, $f->program, new EntityId($term));
        $service->decide($app->id, true);
        return $f->legacyStudent($app->id);
    }
    public function testOnlyStartYearIsEnteredAndTermsAreGeneratedAtomicallyFromActiveTypes(): void
    {
        $a = new AcademicCalendarFixture();
        $a->type();
        $a->type('SP', 'standard', '01-15', '05-15', '01-29', 'END_YEAR');
        $review = $a->sis->calendar()->review(2027);
        self::assertSame(2028, $review['end_year']);
        self::assertSame('2027-2028', $review['code']);
        self::assertSame(['27/FA', '28/SP'], array_column($review['terms'], 'code'));
        self::assertCount(1, $a->sis->sql->rows('sis_terms')); // Preview has no writes.
        $result = $a->sis->calendar()->create(new EntityId($review['id']), new EntityId($review['operation_id']), 2027, array_column($review['terms'], 'id', 'term_type_id'), $review['revision']);
        self::assertCount(3, $a->sis->sql->rows('sis_terms'));
        self::assertSame($review['id'], $result['id']);
        self::assertSame('2027-2028', $a->sis->calendar()->years()['items'][0]['code']);
        foreach ($review['terms'] as $term) {
            self::assertSame($review['id'], $a->sis->calendar()->term(new EntityId($term['id']))['academic_year_id']);
        }
    }
    public function testStandardTermsRejectInclusiveOverlapWhileNonStandardTermsCanOverlap(): void
    {
        $a = new AcademicCalendarFixture();
        $a->type();
        $a->type('MINI', 'standard', '09-01', '10-01', '09-05');
        try {
            $a->year(2027);
            self::fail('Overlapping standard terms were generated.');
        } catch (ValidationException) {
            self::assertSame([], $a->sis->sql->rows('sis_academic_years'));
        }
        $row = $a->sis->calendar()->types()['items'][1];
        $type = new TermType(new EntityId($row['id']), $row['code'], $row['name_pattern'], $row['code_pattern'], 'non_standard', $row['dates'], true, 1);
        $a->sis->calendar()->saveType($type, 0);
        $year = $a->year(2027);
        self::assertCount(2, $year['terms']);
        try {
            $a->sis->calendar()->saveTerm(EntityId::generate(), new EntityId($year['id']), new AcademicTerm('EXACT', 'Exact boundary', 'standard', '2027-12-15', '2027-12-20', '2027-12-15'), null);
            self::fail('The inclusive end day overlapped.');
        } catch (ValidationException) {
            self::assertCount(3, $a->sis->sql->rows('sis_terms'));
        }
        $next = $a->sis->calendar()->saveTerm(EntityId::generate(), new EntityId($year['id']), new AcademicTerm('NEXT', 'Following day', 'standard', '2027-12-16', '2027-12-20', '2027-12-16'), null);
        self::assertSame('NEXT', $next['code']);
    }
    public function testTypeEditsAffectFutureYearsAndNeverRewriteExistingTerms(): void
    {
        $a = new AcademicCalendarFixture();
        $type = $a->type();
        $year = $a->year(2027);
        $id = new EntityId($year['terms'][0]['id']);
        $dates = $type->dates;
        $dates['ends_on']['month_day'] = '12-20';
        $changed = new TermType($type->id, 'FA', 'Autumn {{START_YEAR}}', '{{START_YEAR_SHORT}}/FA', 'standard', $dates, true, 1);
        $a->sis->calendar()->saveType($changed, 0);
        self::assertSame('2027-12-15', $a->sis->calendar()->term($id)['ends_on']);
        self::assertSame(0, $a->sis->calendar()->term($id)['term_type_version']);
        $next = $a->year(2028);
        self::assertSame('2028-12-20', $next['terms'][0]['ends_on']);
        self::assertSame('Autumn 2028', $next['terms'][0]['name']);
    }
    public function testStaleTypeReviewIsRejectedAndCreationReceiptDoesNotRepeatOrResetTermEdits(): void
    {
        $a = new AcademicCalendarFixture();
        $t = $a->type();
        $f = $a->sis;
        $review = $f->calendar()->review(2027);
        $f->calendar()->saveType(new TermType($t->id, $t->code, 'Changed {{START_YEAR}}', $t->codePattern, $t->kind, $t->dates, true, 1), 0);
        try {
            $f->calendar()->create(new EntityId($review['id']), new EntityId($review['operation_id']), 2027, array_column($review['terms'], 'id', 'term_type_id'), $review['revision']);
            self::fail('Stale term type was used.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows('sis_academic_years'));
        }
        $review = $f->calendar()->review(2027);
        $yearId = new EntityId($review['id']);
        $op = new EntityId($review['operation_id']);
        $refs = array_column($review['terms'], 'id', 'term_type_id');
        $receipt = $f->calendar()->create($yearId, $op, 2027, $refs, $review['revision']);
        $termId = new EntityId($receipt['terms'][0]['id']);
        $term = $f->calendar()->term($termId);
        $f->calendar()->saveTerm($termId, $yearId, new AcademicTerm($term['code'], 'Revised individual term', 'standard', $term['starts_on'], $term['ends_on'], '2027-08-30'), $term['revision']);
        self::assertSame($receipt, $f->calendar()->create($yearId, $op, 2027, $refs, $review['revision']));
        self::assertSame('2027-08-30', $f->calendar()->term($termId)['add_drop_ends_on']);
        self::assertCount(1, $f->sql->rows('sis_academic_year_actions'));
    }
    public function testMatchingLegacyTermIsAdoptedWithoutChangingEnrollmentOrFinancialIdentities(): void
    {
        $a = new AcademicCalendarFixture();
        $f = $a->sis;
        $student = $f->student();
        $section = $f->section();
        $f->service->register($student->id, $section->id);
        $events = $f->sql->rows('sis_history_events');
        $credits = $f->sql->rows('sis_academic_credits');
        $enrollments = $f->sql->rows('sis_enrollments');
        $type = new TermType(
            EntityId::generate(),
            'LEGACY',
            'Fall {{START_YEAR}}',
            '{{START_YEAR}}FA',
            'standard',
            ['starts_on' => ['year' => 'START_YEAR', 'month_day' => '08-01'], 'ends_on' => ['year' => 'START_YEAR', 'month_day' => '12-20'], 'add_drop_ends_on' => ['year' => 'START_YEAR', 'month_day' => '08-15']]
        );
        $f->calendar()->saveType($type, -1);
        $year = $a->year(2026);
        self::assertTrue($year['terms'][0]['adopt_existing']);
        self::assertSame($f->term->value, $year['terms'][0]['id']);
        self::assertSame($events, $f->sql->rows('sis_history_events'));
        self::assertSame($credits, $f->sql->rows('sis_academic_credits'));
        self::assertSame($enrollments, $f->sql->rows('sis_enrollments'));
    }
    public function testEachStudentKeepsTheProgramCurriculumForTheirMatriculationYear(): void
    {
        $a = new AcademicCalendarFixture();
        $f = $a->sis;
        $a->type();
        $year22 = $a->year(2022);
        $jj = $this->student($a, $year22['terms'][0]['id']);
        $records = new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($f->sql);
        $catalogs = new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($f->sql);
        $first = $records->primaryCatalog($jj->id);
        self::assertSame('2022-2023', $first['year_code']);
        $year24 = $a->year(2024);
        $sadie = $this->student($a, $year24['terms'][0]['id']);
        self::assertSame('2024-2025', $records->primaryCatalog($sadie->id)['year_code']);
        self::assertSame($first, $records->primaryCatalog($jj->id));
        $program = new \Domain\Catalog\Service\ProgramCatalogService($catalogs, $records, $f->access, $f->transactions, $f->audit);
        $revision = EntityId::generate();
        $program->createRelease($revision, $f->program, new EntityId($year22['id']));
        $program->publishRelease($f->program, $revision);
        $later = $this->student($a, $year22['terms'][0]['id']);
        self::assertSame(2, $records->primaryCatalog($later->id)['revision']);
        self::assertSame($first, $records->primaryCatalog($jj->id));
    }
    public function testYearCreationRollsBackAllTermsAndDerivedReferencesOnAuditFailure(): void
    {
        $a = new AcademicCalendarFixture();
        $a->type();
        $a->type('SP', 'standard', '01-15', '05-15', '01-29', 'END_YEAR');
        $f = $a->sis;
        $audit = new class implements \Domain\Audit\Repository\AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                throw new RuntimeException('Fictional calendar audit failure');
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        $service = new \Domain\AcademicCalendar\Service\AcademicCalendarService(new \Infrastructure\Persistence\Repository\SqlAcademicCalendarRepository($f->sql), $f->access, $f->transactions, $audit, $f->dropCipher, $f->clock);
        $r = $service->review(2027);
        try {
            $service->create(new EntityId($r['id']), new EntityId($r['operation_id']), 2027, array_column($r['terms'], 'id', 'term_type_id'), $r['revision']);
            self::fail('A partial academic year committed.');
        } catch (RuntimeException $e) {
            self::assertSame('Fictional calendar audit failure', $e->getMessage());
        }
        self::assertSame([], $f->sql->rows('sis_academic_years'));
        self::assertSame([], $f->sql->rows('sis_catalog_years'));
        self::assertCount(1, $f->sql->rows('sis_terms'));
    }
    public function testTermYearCannotBeReassignedAndManualCreationRetriesDoNotDuplicateTerms(): void
    {
        $a = new AcademicCalendarFixture();
        $a->type();
        $year = $a->year(2027);
        $next = $a->year(2028);
        $f = $a->sis;
        $id = EntityId::generate();
        $term = new AcademicTerm('MINI', 'Exceptional session', 'non_standard', '2027-09-01', '2027-09-30', '2027-09-07');
        $saved = $f->calendar()->saveTerm($id, new EntityId($year['id']), $term, null);
        self::assertSame($saved, $f->calendar()->saveTerm($id, new EntityId($year['id']), $term, null));
        $current = $f->calendar()->term($id);
        $this->expectException(ValidationException::class);
        $f->calendar()->saveTerm($id, new EntityId($next['id']), $term, $current['revision']);
    }
    public function testLegacyUnlinkedTermCanBeClassifiedBeforeAnAcademicYearExists(): void
    {
        $a = new AcademicCalendarFixture();
        $f = $a->sis;
        $t = $f->calendar()->term($f->term);
        $saved = $f->calendar()->saveTerm($f->term, null, new AcademicTerm($t['code'], 'Legacy non-standard', 'non_standard', $t['starts_on'], $t['ends_on'], $t['add_drop_ends_on']), $t['revision']);
        self::assertNull($saved['academic_year_id']);
        self::assertSame('non_standard', $saved['kind']);
        $a->type();
        self::assertSame(2027, $a->year(2026)['end_year']);
    }
    public function testUnknownPlaceholdersInvalidDatesMissingTypesAndStaleVersionsAreRejected(): void
    {
        $a = new AcademicCalendarFixture();
        try {
            $a->year(2027);
            self::fail('Year was created with no active types.');

        } catch (ValidationException) {
            self::assertSame([], $a->sis->sql->rows('sis_academic_years'));
        }
        $type = $a->type();
        try {
            new TermType($type->id, 'FA', 'Fall {{EXECUTE}}', $type->codePattern, $type->kind, $type->dates);
            self::fail('Unsupported template syntax accepted.');

        } catch (ValidationException) {
            self::assertCount(1, $a->sis->sql->rows('sis_term_types'));
        }
        $dates = $type->dates;
        $dates['starts_on']['month_day'] = '02-29';
        $dates['ends_on']['month_day'] = '03-15';
        $dates['add_drop_ends_on']['month_day'] = '03-01';
        $leap = new TermType(EntityId::generate(), 'LEAP', 'Leap {{START_YEAR}}', '{{START_YEAR}}.L', 'non_standard', $dates);
        try {
            $leap->generate(2027);
            self::fail('Invalid leap day was rolled into March.');

        } catch (ValidationException) {
            self::assertSame('2028-02-29', $leap->generate(2028)['starts_on']);
        }
        $this->expectException(ConcurrentModificationException::class);
        $a->sis->calendar()->saveType($type, -1 + 2);
    }
    public function testCurrentCatalogPermissionAndSubmittingActorAreRequiredForReceiptAndCalendarMutations(): void
    {
        $a = new AcademicCalendarFixture();
        $a->type();
        $f = $a->sis;
        $r = $f->calendar()->review(2027);
        $id = new EntityId($r['id']);
        $op = new EntityId($r['operation_id']);
        $f->calendar()->create($id, $op, 2027, array_column($r['terms'], 'id', 'term_type_id'), $r['revision']);
        $f->access->actor = $f->user();
        try {
            $f->calendar()->receipt($id, $op);
            self::fail('Another actor recovered a receipt.');

        } catch (AccessDeniedException) {
            self::assertCount(1, $f->sql->rows('sis_academic_year_actions'));
        }
        $f->access->staff = false;
        $f->access->grants = ['sis:registration:manage'];
        foreach ([fn() => $f->calendar()->years(), fn() => $f->calendar()->types(), fn() => $f->calendar()->review(2028), fn() => $f->calendar()->term($f->term)] as $call) {
            try {
                $call();
                self::fail('Registration permission exposed calendar administration.');

            } catch (AccessDeniedException) {
                self::assertCount(1, $f->sql->rows('sis_academic_years'));
            }
        }
    }
    private function calendarMigration(SisFixture $f): CreateSisAcademicCalendarTables
    {
        $migration = new CreateSisAcademicCalendarTables('1');
        $migration->setObjectMap(new ArrayObject([
            'phpmig.adapter' => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($f->connection, 'test_migrations'),
        ]));
        return $migration;
    }

    public function testCalendarSchemaRecreationPreservesUnderlyingCurriculumEnrollmentAndFinancialRecords(): void
    {
        $a = new AcademicCalendarFixture();
        $f = $a->sis;
        $a->type();
        $year = $a->year(2027);
        $student = $this->student($a, $year['terms'][0]['id']);
        $section = $f->section();
        $f->service->register($student->id, $section->id);
        $tables = ['sis_program_catalogs', 'sis_student_programs', 'sis_student_catalogs', 'sis_student_catalog_changes', 'sis_academic_credits', 'sis_enrollments', 'sis_history_events', 'sis_terms'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $f->sql->rows($table);
        }
        $migration = $this->calendarMigration($f);
        $migration->down();
        $migration->up();
        foreach ($tables as $table) {
            self::assertSame($before[$table], $f->sql->rows($table), $table);
        }
        foreach (['sis_term_types', 'sis_academic_years', 'sis_term_calendar', 'sis_academic_year_actions'] as $table) {
            self::assertSame([], $f->sql->rows($table), $table);
        }
        self::assertSame([['id' => '00000000000000000000000001', 'version' => 0]], $f->sql->rows('sis_academic_calendar'));
    }

    public function testInitialCalendarSchemaDoesNotConvertExistingCohortsOrInferTermMembership(): void
    {
        $a = new AcademicCalendarFixture();
        $f = $a->sis;
        $migration = $this->calendarMigration($f);
        $migration->down();
        foreach (['2026', '2027'] as $code) {
            $f->sql->insert('sis_catalog_years', [
            'id' => EntityId::generate()->value, 'code' => $code, 'title' => 'Fictional previous development year',
            'starts_on' => $code . '-01-01', 'ends_on' => $code . '-12-31', 'published' => 0,
            ]);
        }
        $years = $f->sql->rows('sis_catalog_years');
        $terms = $f->sql->rows('sis_terms');
        $migration->up();
        self::assertSame($years, $f->sql->rows('sis_catalog_years'));
        self::assertSame($terms, $f->sql->rows('sis_terms'));
        self::assertSame([], $f->sql->rows('sis_academic_years'));
        self::assertSame([], $f->sql->rows('sis_term_calendar'));
        self::assertSame([], $f->sql->rows('sis_term_types'));
    }

    public function testGlobalStandardOverlapInactiveTypesAndIncompleteReviewCannotCreatePartialYears(): void
    {
        $a = new AcademicCalendarFixture();
        $f = $a->sis;
        $a->type();
        $inactive = $a->type('IGNORED');
        $f->calendar()->saveType(new TermType($inactive->id, $inactive->code, $inactive->namePattern, $inactive->codePattern, 'standard', $inactive->dates, false, 1), 0);
        $year = $a->year(2027);
        self::assertCount(1, $year['terms']);
        $id = EntityId::generate();
        $manual = new AcademicTerm('LONG', 'Cross-year standard', 'standard', '2028-06-01', '2028-09-01', '2028-06-10');
        $f->calendar()->saveTerm($id, new EntityId($year['id']), $manual, null);
        self::assertSame('2028-09-01', $f->calendar()->years()['items'][0]['ends_on']);
        try {
            $a->year(2028);
            self::fail('Standard terms overlapped across academic years.');
        } catch (ValidationException) {
            self::assertCount(1, $f->sql->rows('sis_academic_years'));
        }
        $f->calendar()->saveTerm($id, new EntityId($year['id']), new AcademicTerm('LONG', 'Cross-year session', 'non_standard', $manual->startsOn, $manual->endsOn, $manual->addDropEndsOn), $f->calendar()->term($id)['revision']);
        $review = $f->calendar()->review(2028);
        try {
            $f->calendar()->create(new EntityId($review['id']), new EntityId($review['operation_id']), 2028, [], $review['revision']);
            self::fail('Missing references accepted.');
        } catch (ValidationException) {
            self::assertCount(1, $f->sql->rows('sis_academic_years'));
        }
    }

    public function testLinkedTermCodeConflictNamesTheTemplateAndAcademicYearWithoutChangingExistingRecords(): void
    {
        $a = new AcademicCalendarFixture();
        $f = $a->sis;
        $type = $a->type();
        $a->year(2027);
        $fixed = new TermType($type->id, 'FA', 'Fall {{START_YEAR}}', '27/FA', 'standard', $type->dates, true, 1);
        $f->calendar()->saveType($fixed, 0);
        $terms = $f->sql->rows('sis_terms');
        $metadata = $f->sql->rows('sis_term_calendar');
        try {
            $f->calendar()->review(2028);
            self::fail('A linked term was reused.');
        } catch (ValidationException $e) {
            self::assertSame('terms', $e->errors[0]['field']);
            self::assertStringContainsString('Term type FA generates code 27/FA for academic year 2028-2029', $e->errors[0]['message']);
            self::assertStringContainsString('already belongs to academic year 2027-2028', $e->errors[0]['message']);
            self::assertStringContainsString('{{START_YEAR_SHORT}}/FA', $e->errors[0]['message']);
        }
        self::assertSame($terms, $f->sql->rows('sis_terms'));
        self::assertSame($metadata, $f->sql->rows('sis_term_calendar'));
        self::assertCount(1, $f->sql->rows('sis_academic_years'));
    }

    public function testUnlinkedTermCodeConflictExplainsEveryDifferentDateAndAReusedFixedCode(): void
    {
        $a = new AcademicCalendarFixture();
        $f = $a->sis;
        $original = $f->sql->rows('sis_terms');
        $base = $a->type();
        $f->calendar()->saveType(new TermType($base->id, 'FA', 'Fall {{START_YEAR}}', '2026FA', 'standard', $base->dates, true, 1), 0);
        try {
            $f->calendar()->review(2027);
            self::fail('An existing term date was overwritten.');
        } catch (ValidationException $e) {
            $message = $e->errors[0]['message'];
            self::assertStringContainsString('Term type FA generates code 2026FA for academic year 2027-2028', $message);
            self::assertStringContainsString('first day: existing 2026-08-01, generated 2027-08-15', $message);
            self::assertStringContainsString('last day: existing 2026-12-20, generated 2027-12-15', $message);
            self::assertStringContainsString('drop/add deadline: existing 2026-08-15, generated 2027-08-29', $message);
            self::assertStringContainsString('same code every year', $message);
        }
        self::assertSame($original, $f->sql->rows('sis_terms'));
        self::assertSame([], $f->sql->rows('sis_academic_years'));
        $f->calendar()->saveType(new TermType($base->id, 'FA', 'Fall {{START_YEAR}}', '{{START_YEAR_SHORT}}/FA', 'standard', $base->dates, true, 2), 1);
        self::assertSame('27/FA', $a->year(2027)['terms'][0]['code']);
    }

    public function testFallSpringAndSummerUseTheCorrectCalendarYearsAndChronologicalCoverage(): void
    {
        $a = new AcademicCalendarFixture();
        $f = $a->sis;
        // Configure Spring before Fall to verify display order follows dates rather than creation order.
        $a->type('SP', 'standard', '01-04', '05-31', '01-18', 'END_YEAR');
        $a->type('FA', 'standard', '08-24', '12-18', '09-07', 'START_YEAR');
        $a->type('SU', 'non_standard', '06-01', '08-30', '06-08', 'END_YEAR');
        $before = $f->sql->rows('sis_terms');
        $r = $f->calendar()->review(2028);
        self::assertSame(['28/FA', '29/SP', '29/SU'], array_column($r['terms'], 'code'));
        self::assertSame(['2028-08-24', '2029-01-04', '2029-06-01'], array_column($r['terms'], 'starts_on'));
        self::assertSame(['2028-12-18', '2029-05-31', '2029-08-30'], array_column($r['terms'], 'ends_on'));
        self::assertSame('2028-08-24', $r['starts_on']);
        self::assertSame('2029-08-30', $r['ends_on']);
        self::assertSame($before, $f->sql->rows('sis_terms'));
        $saved = $a->year(2028);
        self::assertSame($r['starts_on'], $saved['starts_on']);
        self::assertSame($r['ends_on'], $saved['ends_on']);
        $summer = $f->calendar()->types()['items'][2];
        $f->calendar()->saveType(new TermType(new EntityId($summer['id']), 'SU', $summer['name_pattern'], $summer['code_pattern'], 'non_standard', $summer['dates'], false, 1), 0);
        $next = $f->calendar()->review(2029);
        self::assertSame(['29/FA', '30/SP'], array_column($next['terms'], 'code'));
        self::assertSame('2030-05-31', $next['ends_on']);
    }

    public function testTemplatePreviewUsesGenerationRulesWithoutSavingAndRequiresCurrentCatalogPermission(): void
    {
        $a = new AcademicCalendarFixture();
        $f = $a->sis;
        $controller = new \Application\Http\Controller\SisAcademicCalendarController($f->calendar());
        $input = ['version' => -1, 'code' => 'SP', 'name_pattern' => 'Spring {{END_YEAR}}', 'code_pattern' => '{{END_YEAR_SHORT}}/SP',
            'kind' => 'standard', 'active' => true, 'start_year' => 2028,
            'starts_on_year' => 'END_YEAR', 'starts_on_month_day' => '01-04', 'ends_on_year' => 'END_YEAR', 'ends_on_month_day' => '05-31',
            'add_drop_ends_on_year' => 'END_YEAR', 'add_drop_ends_on_month_day' => '01-18'];
        $request = new \Qubus\Http\ServerRequest()->withParsedBody($input);
        $id = EntityId::generate()->value;
        $response = $controller->previewType($request, $id);
        $result = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('29/SP', $result['code']);
        self::assertSame('Spring 2029', $result['name']);
        self::assertSame('2029-01-04', $result['starts_on']);
        self::assertSame('2029-05-31', $result['ends_on']);
        self::assertSame([], $f->sql->rows('sis_term_types'));
        self::assertSame([], $f->sql->rows('sis_academic_years'));
        self::assertCount(1, $f->sql->rows('sis_terms'));
        $f->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $controller->previewType($request, $id);
    }

    public function testOptionalJanuaryAndTwoSummerSessionsGenerateOnlyConfiguredActiveTypes(): void
    {
        $a = new AcademicCalendarFixture();
        $f = $a->sis;
        $a->type('FA', 'standard', '08-24', '12-18', '09-07');
        $a->type('J', 'standard', '01-04', '01-31', '01-10', 'END_YEAR');
        $a->type('SP', 'standard', '02-01', '05-31', '02-15', 'END_YEAR');
        $a->type('S1', 'non_standard', '06-01', '07-15', '06-08', 'END_YEAR');
        $a->type('S2', 'non_standard', '07-16', '08-30', '07-23', 'END_YEAR');
        $single = $a->type('SU', 'non_standard', '06-01', '08-30', '06-08', 'END_YEAR');
        $f->calendar()->saveType(new TermType($single->id, 'SU', $single->namePattern, $single->codePattern, $single->kind, $single->dates, false, 1), 0);
        $r = $a->year(2028);
        self::assertSame(['28/FA', '29/J', '29/SP', '29/S1', '29/S2'], array_column($r['terms'], 'code'));
        self::assertSame('2029-02-01', $r['terms'][2]['starts_on']);
        self::assertSame('2029-08-30', $r['ends_on']);
        $spring = $f->calendar()->types()['items'][2];
        $dates = $spring['dates'];
        $dates['starts_on']['month_day'] = '01-20';
        $f->calendar()->saveType(new TermType(new EntityId($spring['id']), 'SP', $spring['name_pattern'], $spring['code_pattern'], 'standard', $dates, true, 1), 0);
        $this->expectException(ValidationException::class);
        $f->calendar()->review(2029);
    }
}
