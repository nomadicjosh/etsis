<?php

declare(strict_types=1);

namespace Tests\Integration;

use Application\Http\Controller\SisFormBuilderController;
use Domain\Admissions\Service\ApplicationFormService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlApplicationFormRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Qubus\Http\ServerRequest;
use SisFixture;

final class ApplicationFormLibraryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function service(SisFixture $fixture): ApplicationFormService
    {
        return new ApplicationFormService(
            new SqlApplicationFormRepository($fixture->sql),
            $fixture->admissionsService(),
            $fixture->access,
            $fixture->transactions,
            $fixture->audit,
            $fixture->submissionService()
        );
    }

    private function draft(SisFixture $fixture, string $name): EntityId
    {
        $id = EntityId::generate();
        $fixture->sql->insert('sis_application_forms', ['id' => $id->value,
            'program_id' => $fixture->program->value, 'academic_level_id' => null,
            'name' => $name, 'fields_json' => 'Fictional definition not needed by a metadata read',
            'layout_json' => null, 'status' => 'draft', 'version' => 0]);
        return $id;
    }

    public function testEveryFormCanBeReachedBeyondTheOldCeilingWithoutReturningDefinitions(): void
    {
        $fixture = new SisFixture();
        $service = $this->service($fixture);
        $ids = [];
        for ($index = 0; $index < 133; $index++) {
            $ids[] = $this->draft($fixture, 'Fictional form ' . $index)->value;
        }
        rsort($ids, SORT_STRING);
        $received = [];
        $after = null;
        $pages = 0;
        do {
            $page = $service->listing(after: $after);
            self::assertLessThanOrEqual(20, count($page['items']));
            foreach ($page['items'] as $form) {
                self::assertArrayNotHasKey('fields_json', $form);
                self::assertArrayNotHasKey('layout_json', $form);
                self::assertArrayNotHasKey('answers', $form);
                $received[] = $form['id'];
            }
            $after = $page['next_after'] === null ? null : new EntityId($page['next_after']);
            self::assertLessThanOrEqual(7, ++$pages);
        } while ($after !== null);
        self::assertSame($ids, $received);
        self::assertSame(7, $pages);
        self::assertCount(0, $fixture->sql->rows('sis_history_events'));
        self::assertCount(0, $fixture->sql->rows('sis_audit'));
    }

    public function testSearchEscapesWildcardsAndFiltersBeforeThePageLimit(): void
    {
        $fixture = new SisFixture();
        $service = $this->service($fixture);
        $target = $this->draft($fixture, 'Fictional 100%_! application');
        $this->draft($fixture, 'Fictional 100xx application');
        for ($index = 0; $index < 101; $index++) {
            $this->draft($fixture, 'Recent ordinary form ' . $index);
        }
        self::assertSame([$target->value], array_column($service->listing('100%_!')['items'], 'id'));
        self::assertSame([], $service->listing("' OR 1=1 --")['items']);
        self::assertSame([], $service->listing('No such form')['items']);
        self::assertCount(20, $service->listing('recent', 'draft')['items']);
        self::assertSame([], $service->listing('recent', 'published')['items']);
    }

    public function testStatusProgramAndInstitutionLevelLabelsHaveIndependentCurrentFilters(): void
    {
        $fixture = new SisFixture();
        $service = $this->service($fixture);
        $level = EntityId::generate();
        $fixture->sql->insert('sis_catalog_classifications', ['id' => $level->value, 'kind' => 'academic_level',
            'code' => 'DIP', 'title' => 'Professional diploma', 'active' => 1]);
        $fields = [['key' => 'statement', 'type' => 'text', 'label' => 'Statement']];
        $shared = $service->save(null, null, 'Shared application', $fields, null, $level);
        $service->publish($shared->id, 0);
        $old = $service->save(null, $fixture->program, 'Earlier application', $fields, null);
        $service->publish($old->id, 0);
        $current = $service->save(null, $fixture->program, 'Current application', $fields, null);
        $service->publish($current->id, 0);
        $draft = $service->save(null, $fixture->program, 'Current draft', $fields, null);
        self::assertSame([$draft->id->value], array_column($service->listing(state: 'draft')['items'], 'id'));
        self::assertCount(3, $service->listing(state: 'published')['items']);
        self::assertCount(2, $service->listing(state: 'enabled')['items']);
        self::assertSame([$shared->id->value], array_column($service->listing('DIP', 'enabled')['items'], 'id'));
        self::assertSame([$shared->id->value], array_column($service->listing('professional')['items'], 'id'));
        $program = $fixture->sql->one('sis_programs', ['id' => $fixture->program->value]);
        self::assertSame(
            [$current->id->value],
            array_column($service->listing($program['title'], 'enabled')['items'], 'id')
        );
        $service->disable($fixture->program);
        self::assertSame([$shared->id->value], array_column($service->listing(state: 'enabled')['items'], 'id'));
        self::assertCount(3, $service->listing(state: 'published')['items']);
    }

    public function testQueryParsingAndCurrentPermissionDoNotRequireCatalogAccess(): void
    {
        $fixture = new SisFixture();
        $target = $this->draft($fixture, 'Fictional target');
        $fixture->access->staff = false;
        $fixture->access->grants = ['sis:forms:manage'];
        $controller = new SisFormBuilderController($this->service($fixture));
        $request = new ServerRequest()->withQueryParams(['search' => 'target', 'state' => 'draft']);
        $response = $controller->listing($request);
        $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([$target->value], array_column($data['items'], 'id'));
        self::assertNull($data['next_after']);
        $fixture->access->grants = ['sis:forms:publish'];
        $this->expectException(AccessDeniedException::class);
        $controller->listing(new ServerRequest());
    }

    public function testInvalidSearchStateOrCursorIsRejected(): void
    {
        $fixture = new SisFixture();
        $controller = new SisFormBuilderController($this->service($fixture));
        foreach (
            [['search' => str_repeat('x', 81)], ['search' => "invalid\0search"], ['state' => 'arbitrary'],
            ['search' => ['invalid']], ['after' => 'not-an-id']] as $query
        ) {
            try {
                $controller->listing(new ServerRequest()->withQueryParams($query));
                self::fail('An invalid library query was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
