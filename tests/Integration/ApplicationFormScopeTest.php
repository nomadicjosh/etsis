<?php

declare(strict_types=1);

namespace Tests\Integration;

use SisFixture;
use InvalidArgumentException;
use Domain\Admissions\Service\ApplicationFormService;
use Domain\Admissions\ValueObject\SubmissionIntegrity;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlApplicationFormRepository;
use Infrastructure\Persistence\Repository\SqlCampusRepository;
use PHPUnit\Framework\TestCase;

final class ApplicationFormScopeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function forms(SisFixture $f): ApplicationFormService
    {
        return new ApplicationFormService(
            new SqlApplicationFormRepository($f->sql),
            $f->admissionsService(),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->submissionService()
        );
    }

    private function fields(): array
    {
        return [['key' => 'statement', 'label' => 'Statement', 'type' => 'textarea', 'required' => true]];
    }

    private function level(SisFixture $f, string $code = 'DIP'): EntityId
    {
        $id = EntityId::generate();
        $f->sql->insert('sis_catalog_classifications', ['id' => $id->value, 'kind' => 'academic_level',
            'code' => $code, 'title' => 'Fictional ' . $code, 'active' => 1]);
        return $id;
    }

    private function assign(SisFixture $f, EntityId $program, EntityId $level): void
    {
        $f->sql->insert('sis_program_details', ['id' => $program->value, 'academic_level_id' => $level->value]);
    }

    public function testInstitutionDefinedDefaultsProgramOverridesAndRemovalPrecedence(): void
    {
        $f = new SisFixture();
        $s = $this->forms($f);
        self::assertSame([], $s->levels());
        $level = $this->level($f);
        $other = EntityId::generate();
        $f->sql->insert('sis_programs', ['id' => $other->value, 'code' => 'DIP.ART', 'title' => 'Studio Arts']);
        $this->assign($f, $f->program, $level);
        $this->assign($f, $other, $level);
        $shared = $s->save(null, null, 'Shared diploma form', $this->fields(), null, $level);
        $s->publish($shared->id, 0);
        self::assertSame($shared->id->value, $s->active($other)->id->value);
        self::assertSame($shared->id->value, $s->active($f->program)->id->value);
        $override = $s->save(null, $f->program, 'Program override', $this->fields(), null);
        $s->publish($override->id, 0);
        self::assertSame($override->id->value, $s->active($f->program)->id->value);
        $programs = new SqlCampusRepository($f->sql)->programs();
        self::assertSame($override->id->value, array_column($programs, 'form_id', 'id')[$f->program->value]);
        self::assertSame($shared->id->value, array_column($programs, 'form_id', 'id')[$other->value]);
        $s->disable($f->program);
        self::assertSame($shared->id->value, $s->active($f->program)->id->value);
        // Re-publishing uses a new immutable definition.
        $override = $s->save(null, $f->program, 'New program override', $this->fields(), null);
        $s->publish($override->id, 0);
        $s->disable($level, academicLevel: true);
        self::assertSame($override->id->value, $s->active($f->program)->id->value);
        self::assertCount(3, $s->listing());
        $this->expectException(RecordNotFoundException::class);
        $s->active($other);
    }

    public function testSharedSubmissionBindsActualProgramAndRetainsHistoryAfterClassificationChange(): void
    {
        $f = new SisFixture();
        $s = $this->forms($f);
        $level = $this->level($f);
        $this->assign($f, $f->program, $level);
        $person = $f->legacyPerson($f->access->actor, 'Fictional', 'Applicant')->id;
        $form = $s->save(null, null, 'Shared form', $this->fields(), null, $level);
        $s->publish($form->id, 0);
        $submission = EntityId::generate();
        $f->access->staff = false;
        $answers = ['statement' => 'Fictional private statement'];
        $app = $s->submit($submission, $form->id, $person, $f->term, $answers, $f->program);
        self::assertSame($f->program->value, $f->admissions->application($app)->programId->value);
        self::assertSame($answers, $s->submission($submission)['answers']);
        $stored = $f->sql->one('sis_form_submissions', ['id' => $submission->value]);
        self::assertStringNotContainsString($answers['statement'], $stored['encrypted_answers']);
        $before = $f->sql->rows('sis_history_events');
        $f->access->staff = true;
        $s->disable($level, academicLevel: true);
        $f->sql->update('sis_program_details', ['academic_level_id' => null], ['id' => $f->program->value]);
        $f->access->staff = false;
        $retry = $s->submit($submission, $form->id, $person, $f->term, $answers, $f->program);
        self::assertSame($app->value, $retry->value);
        self::assertSame($answers, $s->submission($submission)['answers']);
        self::assertSame($before, $f->sql->rows('sis_history_events'));
        $this->expectException(AccessDeniedException::class);
        $s->levels();
    }

    public function testSharedFormCannotApplyToUnrelatedProgramOrOmitTheProgram(): void
    {
        $f = new SisFixture();
        $s = $this->forms($f);
        $level = $this->level($f);
        $person = $f->legacyPerson($f->access->actor, 'Fictional', 'Applicant')->id;
        $form = $s->save(null, null, 'Shared form', $this->fields(), null, $level);
        $s->publish($form->id, 0);
        try {
            $s->submit(EntityId::generate(), $form->id, $person, $f->term, ['statement' => 'Fictional']);
            self::fail('A shared form requires the actual program.');
        } catch (ValidationException) {
            self::assertCount(0, $f->sql->rows('sis_applications'));
        }
        try {
            $s->submit(EntityId::generate(), $form->id, $person, $f->term, ['statement' => 'Fictional'], $f->program);
            self::fail('An unrelated program used a level form.');
        } catch (RecordNotFoundException) {
            self::assertCount(0, $f->sql->rows('sis_form_submissions'));
            self::assertCount(0, $f->sql->rows('sis_history_events'));
        }
    }

    public function testReceiptCannotBeReusedAcrossProgramsThatShareTheSameForm(): void
    {
        $f = new SisFixture();
        $s = $this->forms($f);
        $level = $this->level($f);
        $other = EntityId::generate();
        $f->sql->insert('sis_programs', ['id' => $other->value, 'code' => 'DIP.ART', 'title' => 'Studio Arts']);
        $this->assign($f, $f->program, $level);
        $this->assign($f, $other, $level);
        $person = $f->legacyPerson($f->access->actor, 'Fictional', 'Applicant')->id;
        $form = $s->save(null, null, 'Shared form', $this->fields(), null, $level);
        $s->publish($form->id, 0);
        $receipt = EntityId::generate();
        $s->submit($receipt, $form->id, $person, $f->term, ['statement' => 'Fictional'], $f->program);
        $this->expectException(ValidationException::class);
        $s->submit($receipt, $form->id, $person, $f->term, ['statement' => 'Fictional'], $other);
    }

    public function testTargetsAreExclusiveAndImmutableAndLevelsNeedNoCatalogPermission(): void
    {
        $f = new SisFixture();
        $s = $this->forms($f);
        $level = $this->level($f);
        $f->access->staff = false;
        $f->access->grants = ['sis:forms:manage'];
        self::assertSame('DIP', $s->levels()[0]['code']);
        $draft = $s->save(null, null, 'Shared form', $this->fields(), null, $level);
        try {
            $s->save(null, $f->program, 'Invalid two targets', $this->fields(), null, $level);
            self::fail('Both targets were accepted.');
        } catch (InvalidArgumentException) {
            self::assertCount(1, $s->listing());
        }
        try {
            $s->save($draft->id, $f->program, 'Changed target', $this->fields(), 0);
            self::fail('The target changed in place.');
        } catch (ConcurrentModificationException) {
            self::assertSame($level->value, $s->get($draft->id)->academicLevelId->value);
        }
        $this->expectException(AccessDeniedException::class);
        $s->publish($draft->id, 0);
    }

    public function testProgramReceiptFingerprintRemainsUnchanged(): void
    {
        $f = new SisFixture();
        $person = EntityId::generate();
        $form = EntityId::generate();
        $answers = ['statement' => 'Fictional'];
        self::assertSame(
            hash('sha256', json_encode([$form->value, $person->value, $f->term->value, $answers], JSON_THROW_ON_ERROR)),
            SubmissionIntegrity::fingerprint($form, $person, $f->term, $answers)
        );
    }

    public function testPublishedLayoutAndSettingsRoundTripAndRemainVerifiedAfterLaterDraftChanges(): void
    {
        $f = new SisFixture();
        $service = $this->forms($f);
        $person = $f->legacyPerson($f->access->actor, 'Fictional', 'Applicant')->id;
        $fields = [['key' => 'statement', 'label' => 'Statement', 'type' => 'textarea', 'required' => true,
            'settings' => ['placeholder' => 'Your goals', 'min_length' => 3, 'max_length' => 20]]];
        $layout = [
            ['key' => 'about', 'label' => 'About you', 'help' => 'Your plans', 'columns' => [[], ['statement']]],
        ];
        $draft = $service->save(null, $f->program, 'Admission', $fields, null, layout: $layout);
        $service->publish($draft->id, 0);
        $receipt = EntityId::generate();
        $service->submit($receipt, $draft->id, $person, $f->term, ['statement' => 'Fictional goals']);
        $retained = $service->submission($receipt)['form'];
        self::assertSame($layout, $retained->layout);
        self::assertSame($fields[0]['settings'], $retained->fields[0]['settings']);
        $layout[0]['columns'] = [['statement']];
        $layout[0]['label'] = 'New presentation';
        $replacement = $service->save(null, $f->program, 'Later admission', $fields, null, layout: $layout);
        $service->publish($replacement->id, 0);
        self::assertSame($retained->layout, $service->submission($receipt)['form']->layout);
        self::assertSame(['Fictional goals'], array_values($service->submission($receipt)['answers']));
        $f->sql->update(
            'sis_application_forms',
            ['layout_json' => json_encode($layout, JSON_THROW_ON_ERROR)],
            ['id' => $draft->id->value]
        );
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $service->submission($receipt);
    }

    public function testValidLargeFormsAndEncryptedAnswersExceedSmallTextStorageWithoutLosingFacts(): void
    {
        $fixture = new SisFixture();
        $service = $this->forms($fixture);
        $person = $fixture->legacyPerson($fixture->access->actor, 'Fictional', 'Applicant')->id;
        $fields = [];
        $answers = [];
        $options = array_map(
            static fn(int $index): string => sprintf('%02d', $index) . str_repeat('x', 118),
            range(1, 30)
        );
        for ($index = 1; $index <= 20; $index++) {
            $key = 'choice_' . $index;
            $fields[] = ['key' => $key, 'label' => 'Fictional choice ' . $index, 'type' => 'select',
                'required' => true, 'options' => $options];
            $answers[$key] = $options[0];
        }
        for ($index = 1; $index <= 10; $index++) {
            $key = 'statement_' . $index;
            $fields[] = ['key' => $key, 'label' => 'Fictional statement ' . $index,
                'type' => 'textarea', 'required' => true];
            $answers[$key] = str_repeat('x', 4000);
        }
        $draft = $service->save(null, $fixture->program, 'Large fictional form', $fields, null);
        $service->publish($draft->id, 0);
        $receipt = EntityId::generate();
        $service->submit($receipt, $draft->id, $person, $fixture->term, $answers);
        self::assertGreaterThan(65535, strlen($fixture->sql->one(
            'sis_application_forms',
            ['id' => $draft->id->value]
        )['fields_json']));
        self::assertGreaterThan(65535, strlen($fixture->sql->one(
            'sis_form_submissions',
            ['id' => $receipt->value]
        )['encrypted_answers']));
        self::assertCount(30, $service->submission($receipt)['answers']);
    }
}
