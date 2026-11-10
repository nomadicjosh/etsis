<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/SisFixture.php';

use Domain\Admissions\Service\AdmissionsService;
use Domain\Finance\Service\StudentAccountService;
use Domain\HumanResources\Service\TimesheetService;
use Domain\Person\Exception\InstitutionalIdUnavailableException;
use Domain\Person\Service\InstitutionalIdGenerator;
use Domain\Person\ValueObject\InstitutionalId;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Persistence\Repository\SqlTimeRepository;
use Infrastructure\Persistence\Repository\SqlTransferRepository;
use PHPUnit\Framework\TestCase;

final class PersonIdentityTest extends TestCase
{
    private function service(SisFixture $f, ?InstitutionalIdGenerator $generator = null): AdmissionsService
    {
        return new AdmissionsService(
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->audit,
            $generator ?? new RandomInstitutionalIdGenerator(),
        );
    }

    public function testBlankIdsAreGeneratedAndManualIdsPreserveLeadingZeros(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $first = $service->createPerson(null, 'Alex', 'Example');
        $second = $service->createPerson(null, 'Jamie', 'Example', '  ');
        $manual = $service->createPerson(null, 'Morgan', 'Example', '  00001234  ');
        self::assertMatchesRegularExpression('/^P-[A-F0-9]{12}$/D', $first->institutionalId->value);
        self::assertNotSame($first->institutionalId->value, $second->institutionalId->value);
        self::assertSame('00001234', $service->person($manual->id)->institutionalId->value);
        self::assertNotSame($manual->id->value, $manual->institutionalId->value);
    }

    public function testDuplicateIdRejectsCreationWithoutLeavingAnOrphanPerson(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $service->createPerson(null, 'Alex', 'Example', 'AbC-100');
        $beforeAudit = count($f->sql->rows('sis_audit'));
        try {
            $service->createPerson(null, 'Jamie', 'Example', 'abc-100');
            self::fail('A duplicate institutional ID was accepted.');
        } catch (InstitutionalIdUnavailableException) {
            self::assertCount(1, $f->sql->rows('sis_persons'));
            self::assertCount(1, $f->sql->rows('sis_person_identifiers'));
            self::assertCount($beforeAudit, $f->sql->rows('sis_audit'));
        }
    }

    public function testGeneratedCollisionRetriesInAFreshTransaction(): void
    {
        $f = new SisFixture();
        $this->service($f)->createPerson(null, 'Alex', 'Example', 'P-COLLISION');
        $generator = new class implements InstitutionalIdGenerator {
            public int $calls = 0;

            public function next(): InstitutionalId
            {
                return new InstitutionalId(++$this->calls === 1 ? 'P-COLLISION' : 'P-FRESH');
            }
        };
        $person = $this->service($f, $generator)->createPerson(null, 'Jamie', 'Example');
        self::assertSame(2, $generator->calls);
        self::assertSame('P-FRESH', $person->institutionalId->value);
        self::assertCount(2, $f->sql->rows('sis_persons'));
    }

    public function testIdChangesReflectAcrossStudentStaffFinancialAndAcademicReadsWithoutRewritingKeys(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $student = $f->student();
        $section = $f->section();
        $f->service->register($student->id, $section->id);
        $hr = new TimesheetService(new SqlTimeRepository($f->sql), $f->admissions, $f->access, $f->transactions, $f->audit);
        $employee = $hr->addEmployee($student->personId, 'Teaching Assistant');
        $locator = $f->sql->one('sis_student_accounts', ['student_id' => $student->id->value]);
        $finance = new StudentAccountService($f->accounts, $f->admissions, $f->access);
        $events = $f->sql->rows('sis_history_events');
        $enrollment = $f->sql->rows('sis_enrollments');
        $person = $service->updateInstitutionalId($student->personId, '00009001', 0);
        self::assertSame('00009001', $service->studentProfile($student->id)->person->institutionalId->value);
        self::assertSame('00009001', $hr->employeeProfile($employee)->person->institutionalId->value);
        $account = $finance->accountProfile(new \Domain\Finance\ValueObject\AccountId($locator['id']));
        self::assertSame('00009001', $account->person->institutionalId->value);
        self::assertSame('00009001', new SqlTransferRepository($f->sql, $f->gradeCatalog())->transcript($student->id)[0]['institutional_id']);
        $report = new \Domain\Reporting\StudentReport(EntityId::generate(), $f->access->actor, 'Students');
        self::assertSame('00009001', new SqlReportRepository($f->sql)->run($report, null, 50)[0]['institutional_id']);
        self::assertSame($student->personId->value, $person->id->value);
        self::assertSame($enrollment, $f->sql->rows('sis_enrollments'));
        self::assertSame($events, $f->sql->rows('sis_history_events'));
        self::assertStringNotContainsString('00009001', json_encode($f->sql->rows('sis_audit'), JSON_THROW_ON_ERROR));
    }

    public function testDuplicateEditAndStaleEditDoNotChangeEitherIdentity(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $first = $service->createPerson(null, 'Alex', 'Example', '00000001');
        $second = $service->createPerson(null, 'Jamie', 'Example', '00000002');
        try {
            $service->updateInstitutionalId($first->id, '00000002', 0);
            self::fail('A duplicate reassignment was accepted.');
        } catch (InstitutionalIdUnavailableException) {
            self::assertSame('00000001', $service->person($first->id)->institutionalId->value);
            self::assertSame('00000002', $service->person($second->id)->institutionalId->value);
        }
        $service->updateInstitutionalId($first->id, '00000003', 0);
        $this->expectException(ConcurrentModificationException::class);
        $service->updateInstitutionalId($first->id, '00000004', 0);
    }

    public function testOwnerCannotChangeTheirOwnIdWithoutAssignmentPermission(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $person = $service->createPerson($f->access->actor, 'Alex', 'Example');
        $f->access->staff = false;
        self::assertSame($person->institutionalId->value, $service->person($person->id)->institutionalId->value);
        $this->expectException(AccessDeniedException::class);
        $service->updateInstitutionalId($person->id, '00009001', 0);
    }

    public function testCreationPermissionAloneAllowsAutomaticButNotManualAssignment(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $f->access->staff = false;
        $f->access->grants = ['sis:person:manage'];
        $automatic = $service->createPerson(null, 'Alex', 'Example');
        self::assertNotSame('', $automatic->institutionalId->value);
        $this->expectException(AccessDeniedException::class);
        $service->createPerson(null, 'Jamie', 'Example', '00000123');
    }

    public function testSameIdDoesNotAdvanceVersionAndBlankEditGeneratesAnId(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $person = $service->createPerson(null, 'Alex', 'Example', 'STAFF-1');
        self::assertSame(0, $service->updateInstitutionalId($person->id, ' staff-1 ', 0)->identityVersion);
        $changed = $service->updateInstitutionalId($person->id, '  ', 0);
        self::assertSame(1, $changed->identityVersion);
        self::assertMatchesRegularExpression('/^P-[A-F0-9]{12}$/D', $changed->institutionalId->value);
    }

    public function testMigrationBackfillsExistingPeopleAndPreservesIdsOnRerun(): void
    {
        $f = new SisFixture();
        $personId = EntityId::generate();
        $f->sql->insert('sis_persons', ['id' => $personId->value, 'owner_user_id' => null, 'given_name' => 'Alex',
            'family_name' => 'Example', 'directory_restricted' => 1]);
        $migration = new CreateSisPersonIdentifiersTable('5');
        $migration->setObjectMap(new ArrayObject([
            'phpmig.adapter' => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($f->connection, 'test_migrations'),
        ]));
        $migration->up();
        $identity = $f->sql->one('sis_person_identifiers', ['person_id' => $personId->value]);
        self::assertNotSame('', $identity['institutional_id']);
        $this->service($f)->updateInstitutionalId($personId, 'RETAIN-0001', 0);
        $assigned = $f->sql->one('sis_person_identifiers', ['person_id' => $personId->value]);
        $migration->up();
        self::assertSame($assigned, $f->sql->one('sis_person_identifiers', ['person_id' => $personId->value]));
        self::assertSame($personId->value, $f->admissions->person($personId)->id->value);
    }

    public function testHttpCreateEditLookupAndOwnerReadUseTheDedicatedField(): void
    {
        $f = new SisFixture();
        $container = new \Qubus\Injector\Psr11\Container(\Qubus\Injector\Config\InjectorFactory::create([
            \Qubus\Injector\Injector::STANDARD_ALIASES => [
                \Psr\Http\Message\ResponseFactoryInterface::class => \Qubus\Routing\Factories\ResponseFactory::class,
                \Qubus\Routing\Interfaces\MiddlewareResolver::class => \Qubus\Routing\Route\InjectorMiddlewareResolver::class,
            ],
        ]));
        $container->share(new \Application\Http\Controller\SisAdmissionsController($this->service($f)));
        $router = new \Qubus\Routing\Router(new \Qubus\Routing\Route\RouteCollector(), $container);
        $register = require dirname(__DIR__, 2) . '/routes/web/sis.php';
        $register($router);
        $create = new \Qubus\Http\ServerRequest([], [], '/sis/persons/', 'POST');
        $response = $router->match($create->withParsedBody(['given_name' => 'Alex', 'family_name' => 'Example',
            'owner_user_id' => $f->access->actor->value, 'institutional_id' => '00001001']));
        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('00001001', $data['institutional_id']);
        $id = $data['id'];
        $edit = new \Qubus\Http\ServerRequest([], [], '/sis/persons/' . $id . '/institutional-id/', 'POST');
        $response = $router->match($edit->withParsedBody(['institutional_id' => 'staff-2001', 'identity_version' => '0']));
        self::assertSame(200, $response->getStatusCode());
        $edited = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('STAFF-2001', $edited['institutional_id']);
        self::assertSame(1, $edited['identity_version']);
        $lookup = new \Qubus\Http\ServerRequest([], [], '/sis/persons/lookup/', 'POST');
        $response = $router->match($lookup->withParsedBody(['institutional_id' => 'staff-2001']));
        self::assertSame($id, json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['id']);
        $f->access->staff = false;
        $get = new \Qubus\Http\ServerRequest([], [], '/sis/persons/' . $id . '/', 'GET');
        self::assertSame('STAFF-2001', json_decode(
            (string) $router->match($get)->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR
        )['institutional_id']);
        $handler = new \Qubus\Routing\Handlers\CallableRequestHandler($router->match(...));
        $denied = new \Application\Http\Middleware\SisPrivacyMiddleware()->process(
            $edit->withParsedBody(['institutional_id' => '00009999', 'identity_version' => '1']),
            $handler,
        );
        self::assertSame(403, $denied->getStatusCode());
        self::assertStringContainsString('no-store', $denied->getHeaderLine('Cache-Control'));
        self::assertSame('STAFF-2001', $f->admissions->person(new EntityId($id))->institutionalId->value);
    }
}
