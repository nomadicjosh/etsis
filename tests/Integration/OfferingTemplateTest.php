<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/CatalogWorkflowFixture.php';

use Domain\Catalog\ValueObject\OfferingRecipe;
use Domain\Catalog\ValueObject\SectionBlueprint;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Enum\DeliveryMode;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class OfferingTemplateTest extends TestCase
{
    private function recipe(CatalogWorkflowFixture $c): OfferingRecipe
    {
        $course = $c->sis->section()->courseId;
        return new OfferingRecipe(new SectionBlueprint($course, 'MATH.101.A', 30, null, new Money(12500, 'USD'), DeliveryMode::Hybrid, true));
    }
    private function save(CatalogWorkflowFixture $c, OfferingRecipe $recipe, string $name = 'Fictional fall offerings'): array
    {
        $review = $c->templates()->review(null, -1, $name, $recipe);
        return $c->templates()->save(new EntityId($review['id']), new EntityId($review['operation_id']), -1, $name, $recipe, $review['revision']);
    }
    public function testTemplateCapturesOnlyTermNeutralDefaultsAndEachPreparationHasFreshSectionReferences(): void
    {
        $c = new CatalogWorkflowFixture();
        $f = $c->sis;
        $student = $f->student();
        $recipe = $this->recipe($c);
        $before = [$f->sql->rows('sis_student_catalogs'), $f->sql->rows('sis_student_programs'), $f->sql->rows('sis_academic_credits'), $f->sql->rows('sis_history_events')];
        $result = $this->save($c, $recipe);
        $id = new EntityId($result['template_id']);
        $stored = $f->sql->one('sis_offering_templates', ['id' => $id->value]);
        $plain = json_decode($c->fixture->cipher->open($stored['encrypted_content']), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($recipe->describe(), $plain);
        self::assertArrayNotHasKey('id', $plain[0]);
        self::assertArrayNotHasKey('term_id', $plain[0]);
        self::assertStringNotContainsString('MATH.101.A', $stored['encrypted_content']);
        $a = $c->templates()->prepare($id, 0, $f->term);
        $b = $c->templates()->prepare($id, 0, $f->term);
        self::assertNotSame($a['sections'][0]['id'], $b['sections'][0]['id']);
        self::assertSame($f->term->value, $a['sections'][0]['term_id']);
        self::assertSame('hybrid', $a['sections'][0]['delivery_mode']);
        self::assertSame(12500, $a['sections'][0]['fee_minor']);
        self::assertTrue($a['sections'][0]['web_registration']);
        self::assertCount(1, $f->sql->rows('sis_sections')); // Only the preexisting fixture section exists.
        self::assertSame($before, [$f->sql->rows('sis_student_catalogs'), $f->sql->rows('sis_student_programs'), $f->sql->rows('sis_academic_credits'), $f->sql->rows('sis_history_events')]);
        self::assertSame($student->id->value, $f->sql->rows('sis_students')[0]['id']);
    }
    public function testCopiedDefaultsPassThroughOrdinaryOfferingReviewDraftSaveAndSeparatePublication(): void
    {
        $c = new CatalogWorkflowFixture();
        $f = $c->sis;
        $recipe = $this->recipe($c);
        $saved = $this->save($c, $recipe);
        $id = new EntityId($saved['template_id']);
        $other = EntityId::generate();
        $f->sql->insert('sis_terms', ['id' => $other->value, 'code' => '27/FA', 'starts_on' => '2027-08-01', 'ends_on' => '2027-12-31', 'add_drop_ends_on' => '2027-08-15']);
        $prepared = $c->templates()->prepare($id, 0, $other);
        self::assertSame($other->value, $prepared['sections'][0]['term_id']);
        $plan = $recipe->instantiate($other);
        $review = $c->offerings()->review($plan);
        $receipt = $c->offerings()->save(new EntityId($review['operation_id']), $plan, $review['revision']);
        $section = $f->registration->section($plan->sections[0]->id);
        self::assertFalse($section->active);
        self::assertFalse($section->webRegistration);
        $publication = $c->publicationReview($c->offerings(), new EntityId($receipt['operation_id']));
        $c->offerings()->publish(new EntityId($receipt['operation_id']), $publication['review_token']);
        self::assertTrue($f->registration->section($section->id)->active);
        self::assertTrue($f->registration->section($section->id)->webRegistration);
        self::assertSame([], $f->sql->rows('sis_student_accounts'));
        self::assertSame([], $f->sql->rows('sis_bookings'));
    }
    public function testTemplateRevisionCannotRewriteAnAlreadyPreparedPlanOrPublishedSections(): void
    {
        $c = new CatalogWorkflowFixture();
        $f = $c->sis;
        $recipe = $this->recipe($c);
        $saved = $this->save($c, $recipe);
        $id = new EntityId($saved['template_id']);
        $prepared = $c->templates()->prepare($id, 0, $f->term);
        $original = $recipe->sections[0];
        $changed = new OfferingRecipe(new SectionBlueprint($original->course, 'MATH.101.B', 50, null, new Money(500, 'USD'), DeliveryMode::Online, false));
        $review = $c->templates()->review($id, 0, 'Revised defaults', $changed);
        $c->templates()->save($id, new EntityId($review['operation_id']), 0, 'Revised defaults', $changed, $review['revision']);
        self::assertSame('MATH.101.A', $prepared['sections'][0]['code']);
        self::assertSame(12500, $prepared['sections'][0]['fee_minor']);
        self::assertSame('MATH.101.B', $c->templates()->prepare($id, 1, $f->term)['sections'][0]['code']);
        self::assertCount(1, $f->sql->rows('sis_sections'));
        self::assertCount(2, $c->templates()->history($id)['items']);
    }
    public function testCatalogChangesInvalidateTheReviewedTemplateAndDoNotLeavePartialHistory(): void
    {
        $c = new CatalogWorkflowFixture();
        $recipe = $this->recipe($c);
        $review = $c->templates()->review(null, -1, 'Fictional template', $recipe);
        $c->sis->sql->update('sis_courses', ['title' => 'Updated title'], ['id' => $recipe->sections[0]->course->value]);
        try {
            $c->templates()->save(new EntityId($review['id']), new EntityId($review['operation_id']), -1, 'Fictional template', $recipe, $review['revision']);
            self::fail('Stale catalog review succeeded.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $c->sis->sql->rows('sis_offering_templates'));
            self::assertSame([], $c->sis->sql->rows('sis_offering_template_changes'));
        }
    }
    public function testRetirementBlocksFutureSelectionsAndRestorationUsesVersionedReasonedHistory(): void
    {
        $c = new CatalogWorkflowFixture();
        $recipe = $this->recipe($c);
        $saved = $this->save($c, $recipe);
        $id = new EntityId($saved['template_id']);
        $prepared = $c->templates()->prepare($id, 0, $c->sis->term);
        $op = EntityId::generate();
        $retired = $c->templates()->status($id, $op, 0, false, 'Fictional template superseded.');
        self::assertSame($retired, $c->templates()->status($id, $op, 0, false, 'Fictional template superseded.'));
        self::assertSame([], $c->templates()->page(true)['items']);
        self::assertCount(1, $c->templates()->page(false)['items']);
        try {
            $c->templates()->prepare($id, 0, $c->sis->term);
            self::fail('Retired template was used.');

        } catch (ConcurrentModificationException) {
            self::assertSame('MATH.101.A', $prepared['sections'][0]['code']);
        }
        $c->templates()->status($id, EntityId::generate(), 1, true, 'Reviewed for reuse.');
        self::assertSame(2, $c->templates()->view($id)['version']);
        self::assertSame(['created', 'retired', 'restored'], array_column($c->templates()->history($id)['items'], 'action'));
        $this->expectException(ConcurrentModificationException::class);
        $c->templates()->prepare($id, 0, $c->sis->term);
    }
    public function testTemplateSaveRetryIsActorAndPayloadBoundAndDoesNotRevertLaterRevisions(): void
    {
        $c = new CatalogWorkflowFixture();
        $recipe = $this->recipe($c);
        $review = $c->templates()->review(null, -1, 'Fictional template', $recipe);
        $id = new EntityId($review['id']);
        $op = new EntityId($review['operation_id']);
        $receipt = $c->templates()->save($id, $op, -1, 'Fictional template', $recipe, $review['revision']);
        $c->templates()->status($id, EntityId::generate(), 0, false, 'Retire after first use.');
        self::assertSame($receipt, $c->templates()->save($id, $op, -1, 'Fictional template', $recipe, $review['revision']));
        self::assertFalse($c->templates()->view($id)['active']);
        self::assertSame($receipt, $c->templates()->receipt($id, $op));
        try {
            $c->templates()->save($id, $op, -1, 'Changed payload', $recipe, $review['revision']);
            self::fail('Operation reference was reused.');

        } catch (ConcurrentModificationException) {
            self::assertCount(2, $c->templates()->history($id)['items']);
        }
        $c->sis->access->actor = $c->sis->user();
        $this->expectException(AccessDeniedException::class);
        $c->templates()->receipt($id, $op);
    }
    public function testCurrentCatalogPermissionIsRequiredForEveryPrivateReadAndMutation(): void
    {
        $c = new CatalogWorkflowFixture();
        $recipe = $this->recipe($c);
        $saved = $this->save($c, $recipe);
        $id = new EntityId($saved['template_id']);
        $c->sis->access->staff = false;
        $c->sis->access->grants = ['sis:registration:manage'];
        foreach (
            [fn() => $c->templates()->view($id), fn() => $c->templates()->page(true), fn() => $c->templates()->history($id),
            fn() => $c->templates()->prepare($id, 0, $c->sis->term), fn() => $c->templates()->review($id, 0, 'Changed', $recipe),
            fn() => $c->templates()->receipt($id, new EntityId($saved['operation_id'])), fn() => $c->templates()->status($id, EntityId::generate(), 0, false, 'No authority')] as $call
        ) {
            try {
                $call();
                self::fail('A registration grant provided template administration.');

            } catch (AccessDeniedException) {
                self::assertCount(1, $c->sis->sql->rows('sis_offering_templates'));
            }
        }
    }
    public function testAuditFailureRollsBackTemplateAndHistoryAndAnUnchangedRetryCanSucceed(): void
    {
        $c = new CatalogWorkflowFixture();
        $recipe = $this->recipe($c);
        $failure = new class implements \Domain\Audit\Repository\AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                throw new RuntimeException('Fictional audit failure.');
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        $service = $c->templates($failure);
        $review = $service->review(null, -1, 'Fictional template', $recipe);
        $id = new EntityId($review['id']);
        $op = new EntityId($review['operation_id']);
        try {
            $service->save($id, $op, -1, 'Fictional template', $recipe, $review['revision']);
            self::fail('Audit failure committed.');
        } catch (RuntimeException $e) {
            self::assertSame('Fictional audit failure.', $e->getMessage());
        }
        self::assertSame([], $c->sis->sql->rows('sis_offering_templates'));
        self::assertSame([], $c->sis->sql->rows('sis_offering_template_changes'));
        self::assertSame(0, $c->templates()->save($id, $op, -1, 'Fictional template', $recipe, $review['revision'])['version']);
    }
    public function testInactiveCourseDefaultsRemainVisibleButMustBeFixedBeforeSavingAnOffering(): void
    {
        $c = new CatalogWorkflowFixture();
        $recipe = $this->recipe($c);
        $saved = $this->save($c, $recipe);
        $id = new EntityId($saved['template_id']);
        $c->sis->sql->update('sis_courses', ['active' => 0], ['id' => $recipe->sections[0]->course->value]);
        self::assertNotEmpty($c->templates()->view($id)['sections'][0]['warnings']);
        self::assertNotEmpty($c->templates()->prepare($id, 0, $c->sis->term)['defaults'][0]['warnings']);
        $this->expectException(ValidationException::class);
        $c->offerings()->review($recipe->instantiate($c->sis->term));
    }
    public function testSearchEscapesWildcardsAndTemplatePagesAreBounded(): void
    {
        $c = new CatalogWorkflowFixture();
        $recipe = $this->recipe($c);
        $this->save($c, $recipe, 'Fictional 100%_!');
        for ($i = 0; $i < 50; $i++) {
            $this->save($c, $recipe, 'Fictional defaults ' . $i);
        }
        self::assertCount(1, $c->templates()->page(true, '%_!')['items']);
        $page = $c->templates()->page(true);
        self::assertCount(50, $page['items']);
        self::assertCount(1, $c->templates()->page(true, '', new EntityId($page['next_after']))['items']);
        self::assertArrayNotHasKey('encrypted_content', $page['items'][0]);
    }
    public function testRecipeRejectsDuplicateCodesInvalidLimitsAndNegativeFees(): void
    {
        $c = new CatalogWorkflowFixture();
        $recipe = $this->recipe($c);
        $s = $recipe->sections[0];
        foreach (
            [fn() => new OfferingRecipe(), fn() => new OfferingRecipe($s, $s), fn() => new OfferingRecipe(...array_fill(0, 26, $s)),
            fn() => new SectionBlueprint($s->course, 'Bad', 0, null, $s->fee, $s->mode, true),
            fn() => new SectionBlueprint($s->course, 'Bad', 10, null, new Money(-1, 'USD'), $s->mode, true)] as $call
        ) {
            try {
                $call();
                self::fail('Invalid blueprint accepted.');

            } catch (ValidationException) {
                self::assertSame([], $c->sis->sql->rows('sis_offering_templates'));
            }
        }
    }
    public function testFacultyContextIsReviewedAndLongHistoryUsesBoundedVersionPages(): void
    {
        $c = new CatalogWorkflowFixture();
        $f = $c->sis;
        $student = $f->student();
        $base = $this->recipe($c)->sections[0];
        $recipe = new OfferingRecipe(new SectionBlueprint($base->course, $base->code, 30, $f->access->actor, $base->fee, DeliveryMode::Online, false));
        $plan = $c->templates()->review(null, -1, 'Fictional faculty defaults', $recipe);
        $f->sql->update('sis_persons', ['given_name' => 'Taylor'], ['id' => $student->personId->value]);
        try {
            $c->templates()->save(new EntityId($plan['id']), new EntityId($plan['operation_id']), -1, 'Fictional faculty defaults', $recipe, $plan['revision']);
            self::fail('Changed faculty context was ignored.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows('sis_offering_templates'));
        }
        $saved = $this->save($c, $recipe);
        $id = new EntityId($saved['template_id']);
        self::assertSame('Taylor Example', $c->templates()->view($id)['sections'][0]['faculty_name']);
        for ($v = 0; $v < 51; $v++) {
            $c->templates()->status($id, EntityId::generate(), $v, $v % 2 !== 0, 'Fictional availability change ' . $v);
        }
        $first = $c->templates()->history($id);
        self::assertCount(50, $first['items']);
        self::assertSame(49, $first['next_after_version']);
        $last = $c->templates()->history($id, 49);
        self::assertCount(2, $last['items']);
        self::assertNull($last['next_after_version']);
    }
}
