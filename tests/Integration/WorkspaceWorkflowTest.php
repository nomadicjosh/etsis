<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/SisFixture.php';

use Defuse\Crypto\Key;
use Domain\Admissions\Service\AdmissionsService;
use Domain\Admissions\Service\ApplicationFormService;
use Domain\Import\Service\CatalogImportService;
use Domain\Import\Service\MappedImportService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Communications\DefuseMessageCipher;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Import\LeagueCourseCsvMapper;
use Infrastructure\Persistence\Repository\SqlApplicationFormRepository;
use Infrastructure\Persistence\Repository\SqlCatalogImportRepository;
use PHPUnit\Framework\TestCase;
use Qubus\Config\ConfigContainer;

final class WorkspaceWorkflowTest extends TestCase
{
    private function forms(SisFixture $fixture): ApplicationFormService
    {
        $config = $this->createStub(ConfigContainer::class);
        $config->method('getConfigKey')->willReturn(Key::createNewRandomKey()->saveToAsciiSafeString());

        return new ApplicationFormService(
            new SqlApplicationFormRepository($fixture->sql),
            new AdmissionsService(
                $fixture->admissions,
                $fixture->access,
                $fixture->transactions,
                $fixture->audit,
                new RandomInstitutionalIdGenerator(),
            ),
            $fixture->access,
            $fixture->transactions,
            $fixture->audit,
            new DefuseMessageCipher($config)
        );
    }

    private function fields(): array
    {
        return [
            ['key' => 'transfer', 'label' => 'Transfer applicant?', 'type' => 'checkbox'],
            ['key' => 'college', 'label' => 'Previous college', 'type' => 'text', 'required' => true,
                'show_when' => ['condition' => 'AND', 'rules' => [
                    ['id' => 'transfer', 'operator' => 'equal', 'value' => true],
                ]]],
        ];
    }

    public function testPublishedFormsAreImmutableAndDraftWritesCheckVersion(): void
    {
        $fixture = new SisFixture();
        $service = $this->forms($fixture);
        $draft = $service->save(null, $fixture->program, 'Transfer application', $this->fields(), null);
        $updated = $service->save($draft->id, $fixture->program, 'Updated application', $this->fields(), 0);
        self::assertSame(1, $updated->version);
        try {
            $service->save($draft->id, $fixture->program, 'Stale change', $this->fields(), 0);
            self::fail('A stale editor overwrote a draft.');
        } catch (ConcurrentModificationException) {
            self::assertSame('Updated application', $service->get($draft->id)->name);
        }
        $service->publish($updated->id, 1);
        self::assertSame('published', $service->active($fixture->program)->status);
        $this->expectException(ConcurrentModificationException::class);
        $service->save($draft->id, $fixture->program, 'Changed publication', $this->fields(), 2);
    }

    public function testConditionalAnswersAreEncryptedAndRetriesAreIdempotent(): void
    {
        $fixture = new SisFixture();
        $person = new AdmissionsService(
            $fixture->admissions,
            $fixture->access,
            $fixture->transactions,
            $fixture->audit,
            new RandomInstitutionalIdGenerator(),
        );
        $record = $person->createPerson($fixture->access->actor, 'Taylor', 'Example');
        $service = $this->forms($fixture);
        $form = $service->save(null, $fixture->program, 'Application', $this->fields(), null);
        $service->publish($form->id, 0);
        $fixture->access->staff = false;
        $submission = EntityId::generate();
        $answers = ['transfer' => true, 'college' => 'Fictional Example College'];
        $application = $service->submit($submission, $form->id, $record->id, $fixture->term, $answers);
        self::assertSame(
            $application->value,
            $service->submit($submission, $form->id, $record->id, $fixture->term, $answers)->value
        );
        self::assertCount(1, $fixture->sql->rows('sis_applications'));
        self::assertSame($answers, $service->submission($submission)['answers']);
        self::assertStringNotContainsString(
            'Fictional Example College',
            json_encode($fixture->sql->rows('sis_form_submissions'), JSON_THROW_ON_ERROR)
        );
        self::assertStringNotContainsString(
            'Fictional Example College',
            json_encode($fixture->sql->rows('sis_audit'), JSON_THROW_ON_ERROR)
        );
        $fixture->access->actor = $fixture->user();
        $this->expectException(AccessDeniedException::class);
        $service->submission($submission);
    }

    public function testInvalidConditionalAnswerDoesNotCreateApplication(): void
    {
        $fixture = new SisFixture();
        $admissions = new AdmissionsService(
            $fixture->admissions,
            $fixture->access,
            $fixture->transactions,
            $fixture->audit,
            new RandomInstitutionalIdGenerator(),
        );
        $person = $admissions->createPerson($fixture->access->actor, 'Taylor', 'Example');
        $service = $this->forms($fixture);
        $form = $service->save(null, $fixture->program, 'Application', $this->fields(), null);
        $service->publish($form->id, 0);
        try {
            $service->submit(EntityId::generate(), $form->id, $person->id, $fixture->term, ['transfer' => true]);
            self::fail('Required conditional answer was omitted.');
        } catch (ValidationException $exception) {
            self::assertCount(0, $fixture->sql->rows('sis_applications'));
        }
        $service->disable($fixture->program);
        $this->expectException(\Domain\Shared\Exception\RecordNotFoundException::class);
        $service->submit(EntityId::generate(), $form->id, $person->id, $fixture->term, ['transfer' => false]);
    }

    public function testMappedImportPreviewDoesNotWriteAndCommitRechecksContent(): void
    {
        $fixture = new SisFixture();
        $service = new MappedImportService(
            new LeagueCourseCsvMapper(),
            new CatalogImportService(
                new SqlCatalogImportRepository($fixture->sql),
                $fixture->access,
                $fixture->transactions,
                $fixture->audit
            ),
            $fixture->access
        );
        $csv = "Name,Credits,Source,Code\nExample Computing,3.125,example-1,CS-101\n";
        $mapping = ['title' => 0, 'credits' => 1, 'external_id' => 2, 'code' => 3];
        $preview = $service->preview($csv, $mapping);
        self::assertTrue($preview['ready']);
        self::assertArrayNotHasKey('canonical_csv', $preview);
        self::assertCount(0, $fixture->sql->rows('sis_courses'));
        try {
            $service->commit('example', str_replace('3.125', '4', $csv), $mapping, $preview['fingerprint']);
            self::fail('Unreviewed content was imported.');
        } catch (InvalidArgumentException) {
            self::assertCount(0, $fixture->sql->rows('sis_courses'));
        }
        self::assertSame(1, $service->commit('example', $csv, $mapping, $preview['fingerprint']));
        self::assertSame(0, $service->commit('example', $csv, $mapping, $preview['fingerprint']));
        self::assertSame(3125, (int) $fixture->sql->rows('sis_courses')[0]['credits_milli']);
        $invalid = str_replace('3.125', 'not-a-number', $csv);
        $preview = $service->preview($invalid, $mapping);
        self::assertFalse($preview['ready']);
        $this->expectException(ValidationException::class);
        $service->commit('example', $invalid, $mapping, $preview['fingerprint']);
    }

    public function testDirectoryPermissionDoesNotExposeOtherPersonAcademicOrFinancialData(): void
    {
        $fixture = new SisFixture();
        $student = $fixture->student($fixture->user());
        $admissions = new AdmissionsService(
            $fixture->admissions,
            $fixture->access,
            $fixture->transactions,
            $fixture->audit,
            new RandomInstitutionalIdGenerator(),
        );
        $service = new \Domain\Workspace\Service\WorkspaceService(
            new \Infrastructure\Persistence\Repository\SqlWorkspaceRepository($fixture->sql, $fixture->gradeCatalog()),
            $fixture->access,
            new \Infrastructure\Persistence\Repository\SqlAccessRepository($fixture->sql),
            $admissions
        );
        self::assertSame(['person'], array_keys($service->person($student->personId)));
        self::assertSame(['cards' => [], 'intake' => [], 'pipeline' => [], 'queue' => []], $service->dashboard());
        $fixture->access->staff = false;
        $fixture->access->grants = ['admin:dashboard'];
        $this->expectException(AccessDeniedException::class);
        $service->records('students');
    }

    public function testSearchEscapesWildcardsAndCursorPagesDoNotRepeatPeople(): void
    {
        $fixture = new SisFixture();
        $admissions = new AdmissionsService(
            $fixture->admissions,
            $fixture->access,
            $fixture->transactions,
            $fixture->audit,
            new RandomInstitutionalIdGenerator(),
        );
        for ($index = 0; $index < 52; ++$index) {
            $admissions->createPerson(null, 'Example', 'Person ' . $index);
        }
        $repository = new \Infrastructure\Persistence\Repository\SqlWorkspaceRepository($fixture->sql, $fixture->gradeCatalog());
        $first = $repository->records('people', null, '', null);
        $second = $repository->records('people', null, '', new EntityId($first['next_after']));
        self::assertCount(50, $first['items']);
        self::assertCount(2, $second['items']);
        self::assertNull($second['next_after']);
        self::assertSame([], array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')));
        self::assertSame([], $repository->records('people', null, '%', null)['items']);
        self::assertSame([], $repository->records('people', null, "' OR 1=1 --", null)['items']);
    }

    public function testExistingPersonGetsOptionalAccountWithoutDuplicatingProfile(): void
    {
        $fixture = new SisFixture();
        $admissions = new AdmissionsService(
            $fixture->admissions,
            $fixture->access,
            $fixture->transactions,
            $fixture->audit,
            new RandomInstitutionalIdGenerator(),
        );
        $person = $admissions->createPerson(null, 'Alex', 'Example', '00001234');
        $repository = new \Infrastructure\Persistence\Repository\SqlPersonAccountRepository(
            $fixture->sql,
            $fixture->admissions,
            new RandomInstitutionalIdGenerator()
        );
        $account = $fixture->user();
        $linked = $fixture->transactions->run(fn () => $repository->ensure($account, 'Alex', 'Example', $person->id));
        self::assertSame($person->id->value, $linked->id->value);
        self::assertSame('00001234', $linked->institutionalId->value);
        $fixture->transactions->run(fn () => $repository->updateNames($account, 'Alexandra', 'Example'));
        self::assertSame('Alexandra', $admissions->person($person->id)->givenName);
        self::assertCount(1, $fixture->sql->rows('sis_persons'));
        $this->expectException(DomainException::class);
        $fixture->transactions->run(fn () => $repository->ensure($fixture->user(), 'Alex', 'Example', $person->id));
    }

    public function testRulePreviewUsesBoundParametersAndExplicitReadPermission(): void
    {
        $fixture = new SisFixture();
        $student = $fixture->student();
        $service = new \Domain\Rules\Service\RuleService(
            new \Infrastructure\Persistence\Repository\SqlRuleRepository($fixture->sql),
            $fixture->access,
            $fixture->transactions,
            $fixture->audit
        );
        $tree = ['condition' => 'AND', 'rules' => [
            ['id' => 'active', 'operator' => 'equal', 'value' => true],
            ['id' => 'program_id', 'operator' => 'equal', 'value' => $fixture->program->value],
        ]];
        $matches = $service->preview($tree);
        self::assertSame($student->id->value, $matches[0]['id']);
        $tree['rules'][1]['value'] = "' OR 1=1 --";
        self::assertSame([], $service->preview($tree));
        $fixture->access->staff = false;
        $fixture->access->grants = ['sis:rules:run'];
        $this->expectException(AccessDeniedException::class);
        $service->preview($tree);
    }
    public function testAdmissionsReviewUsesOriginalEncryptedSubmissionWithoutBuilderPrivileges(): void
    {
        $f = new SisFixture();
        $forms = $this->forms($f);
        $admissions = new AdmissionsService(
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->audit,
            new RandomInstitutionalIdGenerator(),
        );
        $person = $admissions->createPerson($f->access->actor, 'Alex', 'Example');
        $original = $forms->save(null, $f->program, 'Original application', $this->fields(), null);
        $forms->publish($original->id, 0);
        $submission = EntityId::generate();
        $application = $forms->submit($submission, $original->id, $person->id, $f->term, ['transfer' => false]);
        $replacement = $forms->save(null, $f->program, 'Replacement application', $this->fields(), null);
        $forms->publish($replacement->id, 0);
        $owner = $f->access->actor;
        $f->access->actor = $f->user();
        $f->access->staff = false;
        $f->access->grants = ['sis:admissions:manage'];
        $review = $forms->reviewApplication($application);
        self::assertSame('Original application', $review['submission']['form']->name);
        self::assertFalse($review['submission']['answers']['transfer']);
        self::assertSame('BSCS', $review['labels']['program_code']);
        self::assertStringNotContainsString('transfer', json_encode($f->audit->forSubject($application)));
        $f->access->actor = $owner;
        $f->access->grants = [];
        self::assertFalse($forms->submission($submission)['answers']['transfer']);
        $this->expectException(AccessDeniedException::class);
        $forms->reviewApplication($application);
    }

    public function testDirectApplicationsHaveNoInventedFormAnswers(): void
    {
        $f = new SisFixture();
        $admissions = new AdmissionsService(
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->audit,
            new RandomInstitutionalIdGenerator(),
        );
        $person = $admissions->createPerson(null, 'Jamie', 'Example');
        $application = $admissions->apply($person->id, $f->program, $f->term);
        self::assertNull($this->forms($f)->reviewApplication($application->id)['submission']);
    }
}
