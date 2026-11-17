<?php

declare(strict_types=1);

namespace Tests\Integration;

use CampusFixture;
use Domain\Admissions\Service\ApplicationFormService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlApplicationFormRepository;
use PHPUnit\Framework\TestCase;

final class PortalApplicationAnswersTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CampusFixture.php';
    }

    private function forms(CampusFixture $campus): ApplicationFormService
    {
        $f = $campus->sis;

        return new ApplicationFormService(
            new SqlApplicationFormRepository($f->sql),
            $f->admissionsService(),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->submissionService(),
        );
    }

    public function testApplicationContextProvidesOnlyTheOwnerReferenceAndMetadataEvenBeforeTheFirstApplication(): void
    {
        $c = new CampusFixture();
        $f = $c->sis;
        $person = $f->legacyPerson($f->access->actor, 'Fictional', 'First application');
        $f->access->staff = false;

        self::assertSame(['person_id' => $person->id->value, 'items' => []], $c->service->applications());
        self::assertSame([], $f->sql->rows('sis_history_events'));
    }

    public function testOwnerDiscoversStaffEnteredAnswersWithoutLoadingThemIntoTheOverview(): void
    {
        $c = new CampusFixture();
        $f = $c->sis;
        $owner = $f->access->actor;
        $person = $f->legacyPerson($owner, 'Fictional', 'Applicant');
        $forms = $this->forms($c);
        $fields = [['key' => 'statement', 'label' => 'Original question', 'type' => 'textarea', 'required' => true]];
        $layout = [['key' => 'plans', 'label' => 'Original section', 'help' => '', 'columns' => [['statement']]]];
        $form = $forms->save(null, $f->program, 'Original application', $fields, null, layout: $layout);
        $forms->publish($form->id, 0);
        $receipt = EntityId::generate();
        $f->access->actor = $f->user();
        $application = $forms->submit(
            $receipt,
            $form->id,
            $person->id,
            $f->term,
            ['statement' => 'Fictional private statement'],
        );
        $forms->disable($f->program, $form->id);
        $f->sql->update('sis_programs', ['title' => 'Current renamed program'], ['id' => $f->program->value]);
        $f->access->actor = $owner;
        $f->access->staff = false;
        $events = $f->sql->rows('sis_history_events');

        $overview = $c->service->me();
        self::assertSame($person->id->value, $c->service->applications()['person_id']);
        self::assertSame($overview['applications'], $c->service->applications()['items']);
        self::assertCount(1, $overview['applications']);
        $row = $overview['applications'][0];
        self::assertSame($application->value, $row['id']);
        self::assertSame($receipt->value, $row['submission_id']);
        self::assertSame('Current renamed program', $row['program_title']);
        self::assertNotEmpty($row['program_code']);
        self::assertNotEmpty($row['term_code']);
        foreach (['form_id', 'fields_json', 'layout_json', 'encrypted_answers', 'fingerprint', 'answers'] as $key) {
            self::assertArrayNotHasKey($key, $row);
        }
        self::assertStringNotContainsString('Fictional private statement', json_encode($overview, JSON_THROW_ON_ERROR));
        $read = $forms->submission($receipt);
        self::assertSame($application->value, $read['application_id']);
        self::assertSame('Original application', $read['form']->name);
        self::assertSame($layout, $read['form']->layout);
        self::assertSame(['statement' => 'Fictional private statement'], $read['answers']);
        self::assertSame($events, $f->sql->rows('sis_history_events'));
    }

    public function testOverviewAndAnswerReadsFollowCurrentPersonOwnershipIndependentlyOfStaffRoles(): void
    {
        $c = new CampusFixture();
        $f = $c->sis;
        $owner = $f->access->actor;
        $person = $f->legacyPerson($owner, 'Fictional', 'First');
        $forms = $this->forms($c);
        $form = $forms->save(null, $f->program, 'Fictional form', [
            ['key' => 'statement', 'label' => 'Statement', 'type' => 'text', 'required' => true],
        ], null);
        $forms->publish($form->id, 0);
        $receipt = EntityId::generate();
        $application = $forms->submit(
            $receipt,
            $form->id,
            $person->id,
            $f->term,
            ['statement' => 'Private first answer'],
        );
        $other = $f->user();
        $otherPerson = $f->legacyPerson($other, 'Fictional', 'Second');
        $otherApplication = $f->legacyApplication($otherPerson->id, $f->program, $f->term);
        $f->access->actor = $other;
        $f->access->staff = false;
        $f->access->grants = ['sis:person:manage', 'sis:student:read'];
        self::assertSame([$otherApplication->id->value], array_column($c->service->me()['applications'], 'id'));
        self::assertSame([$otherApplication->id->value], array_column($c->service->applications()['items'], 'id'));
        self::assertNull($c->service->me()['applications'][0]['submission_id']);
        try {
            $forms->submission($receipt);
            self::fail('Directory/student authority exposed another person\'s answers.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $f->access->staff = true;
        self::assertSame([$otherApplication->id->value], array_column($c->service->me()['applications'], 'id'));
        self::assertSame([$otherApplication->id->value], array_column($c->service->applications()['items'], 'id'));
        $replacementOwner = $f->user();
        $f->sql->update('sis_persons', ['owner_user_id' => $replacementOwner->value], ['id' => $person->id->value]);
        $f->access->staff = false;
        $f->access->grants = [];
        $f->access->actor = $replacementOwner;
        self::assertSame($person->id->value, $c->service->applications()['person_id']);
        self::assertSame([$application->value], array_column($c->service->me()['applications'], 'id'));
        self::assertSame([$application->value], array_column($c->service->applications()['items'], 'id'));
        self::assertSame($application->value, $forms->submission($receipt)['application_id']);
        $f->access->actor = $owner;
        $this->expectException(AccessDeniedException::class);
        $forms->submission($receipt);
    }

    public function testOverviewKeepsItsOwnerBoundAndFiftyRecordLimitWithoutInventingSubmissionReferences(): void
    {
        $c = new CampusFixture();
        $f = $c->sis;
        $person = $f->legacyPerson($f->access->actor, 'Fictional', 'Applicant');
        $other = $f->legacyPerson($f->user(), 'Fictional', 'Other');
        $ids = [];
        for ($index = 0; $index < 55; $index++) {
            $ids[] = $f->legacyApplication($person->id, $f->program, $f->term)->id->value;
            $f->legacyApplication($other->id, $f->program, $f->term);
        }
        rsort($ids, SORT_STRING);
        $rows = $c->repository->overview($f->access->actor)['applications'];
        self::assertSame($rows, $c->service->applications()['items']);
        self::assertCount(50, $rows);
        self::assertSame(array_slice($ids, 0, 50), array_column($rows, 'id'));
        foreach ($rows as $row) {
            self::assertSame($person->id->value, $row['person_id']);
            self::assertNull($row['submission_id']);
        }
        self::assertSame([], $f->sql->rows('sis_history_events'));
    }
}
