<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Scheduling\Room;
use Domain\Scheduling\Service\BuildingService;
use Domain\Scheduling\Service\RoomService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlBookingRepository;
use Infrastructure\Persistence\Repository\SqlBuildingRepository;
use Infrastructure\Persistence\Repository\SqlRoomRepository;
use Infrastructure\Persistence\Repository\SqlWorkspaceRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SisFixture;

final class FacilitiesDefinitionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function buildings(SisFixture $f, ?AuditTrail $audit = null): BuildingService
    {
        return new BuildingService(
            new SqlBuildingRepository($f->sql),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit
        );
    }

    private function rooms(SisFixture $f, ?AuditTrail $audit = null): RoomService
    {
        return new RoomService(
            new SqlRoomRepository($f->sql),
            new SqlBookingRepository($f->sql),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $f->clock,
            new SqlBuildingRepository($f->sql)
        );
    }

    public function testNamesAndBuildingLinksAreDistinctAndSearchableWithoutChangingIdentity(): void
    {
        $f = new SisFixture();
        $buildings = $this->buildings($f);
        $rooms = $this->rooms($f);
        $library = $buildings->create(' LIB ', ' Campus library ');
        $science = $buildings->create('SCI', 'Science building');
        $id = $rooms->create('LIB.101', 'Quiet study room', $library, 20);
        $before = $rooms->view($id);
        self::assertSame('LIB.101', $before['code']);
        self::assertSame('Quiet study room', $before['name']);
        self::assertSame('Campus library', $before['building']['name']);
        $directory = new SqlWorkspaceRepository($f->sql, $f->gradeCatalog(), $f->clock);
        foreach (['LIB.101', 'quiet study', 'Campus library'] as $term) {
            $matches = $directory->records('rooms', null, $term, null)['items'];
            self::assertSame([$id->value], array_column($matches, 'id'));
        }
        $matches = $directory->records('buildings', null, 'science', null)['items'];
        self::assertSame([$science->value], array_column($matches, 'id'));
        $rooms->edit($id, 'SCI.101', 'Teaching laboratory', $science, 24, $before['revision']);
        $current = $rooms->view($id);
        self::assertSame($id->value, $current['id']);
        self::assertSame('SCI.101', $current['code']);
        self::assertSame('Teaching laboratory', $current['name']);
        self::assertSame($science->value, $current['building_id']);
        try {
            $rooms->edit($id, 'LIB.101', 'Stale room', $library, 20, $before['revision']);
            self::fail('A stale room edit was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame('Teaching laboratory', $rooms->view($id)['name']);
        }
        $building = $buildings->view($science);
        $buildings->edit($science, 'SCIENCE', 'Science and technology', $building['revision']);
        self::assertSame('Science and technology', $rooms->view($id)['building']['name']);
        self::assertSame($science->value, $rooms->view($id)['building_id']);
        try {
            $buildings->edit($science, 'STALE', 'Earlier name', $building['revision']);
            self::fail('A stale building edit was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame('SCIENCE', $buildings->view($science)['code']);
        }
        self::assertSame([], $f->sql->rows('sis_history_events'));
        $audit = json_encode($f->sql->rows('sis_audit'), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Quiet study', $audit);
        self::assertStringNotContainsString('Science and technology', $audit);
    }

    public function testEarlierUnspecifiedMetadataStaysUnknownUntilStaffCompleteIt(): void
    {
        $f = new SisFixture();
        $id = EntityId::generate();
        new SqlRoomRepository($f->sql)->add(new Room($id, 'EARLIER.1', 20, null, null));
        $rooms = $this->rooms($f);
        $before = $rooms->view($id);
        self::assertNull($before['name']);
        self::assertNull($before['building']);
        $rooms->edit($id, 'EARLIER.1', 'Seminar room', $f->building(), 20, $before['revision']);
        self::assertSame('Seminar room', $rooms->view($id)['name']);
    }

    public function testNewRoomsRequireNamesAndExistingBuildingsAndCodesRemainUnique(): void
    {
        $f = new SisFixture();
        $rooms = $this->rooms($f);
        $buildings = $this->buildings($f);
        $building = $f->building();
        foreach (['', "Room\nname", str_repeat('a', 192)] as $name) {
            try {
                $rooms->create('ROOM.1', $name, $building, 20);
                self::fail('An invalid room name was accepted.');
            } catch (ValidationException $error) {
                self::assertSame('name', $error->errors[0]['field']);
            }
        }
        try {
            $rooms->create('ROOM.1', 'Seminar room', EntityId::generate(), 20);
            self::fail('An unknown building was accepted.');
        } catch (RecordNotFoundException) {
            self::assertSame([], $f->sql->rows('sis_rooms'));
        }
        $rooms->create('ROOM.1', 'Seminar room', $building, 20);
        foreach (
            [fn () => $rooms->create('ROOM.1', 'Different room', $building, 10),
            fn () => $buildings->create('FIXTURE', 'Duplicate building')] as $duplicate
        ) {
            try {
                $duplicate();
                self::fail('A duplicate code was accepted.');
            } catch (ValidationException $error) {
                self::assertSame('code', $error->errors[0]['field']);
            }
        }
    }

    public function testMissingAuthorityAndAuditFailureCannotCreateOrChangeFacilities(): void
    {
        $f = new SisFixture();
        $building = $f->building();
        $id = $f->room('ROOM.1', 20);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willThrowException(new RuntimeException('Fictional audit failure'));
        foreach (
            [fn () => $this->buildings($f, $audit)->create('NEW', 'New building'),
            fn () => $this->rooms($f, $audit)->create('ROOM.2', 'New room', $building, 10),
            fn () => $this->rooms($f, $audit)->edit(
                $id,
                'ROOM.1',
                'Changed name',
                $building,
                10,
                $this->rooms($f)->view($id)['revision']
            )] as $write
        ) {
            try {
                $write();
                self::fail('A facility write bypassed required audit.');
            } catch (RuntimeException) {
                self::assertCount(1, $f->sql->rows('sis_buildings'));
                self::assertSame('Fictional room', $this->rooms($f)->view($id)['name']);
            }
        }
        $f->access->staff = false;
        foreach (
            [fn () => $this->buildings($f)->view($building),
            fn () => $this->buildings($f)->create('NEW', 'New building'),
            fn () => $this->rooms($f)->create('NEW', 'New room', $building, 10)] as $operation
        ) {
            try {
                $operation();
                self::fail('A facility operation bypassed scheduling authority.');
            } catch (AccessDeniedException) {
                self::assertCount(1, $f->sql->rows('sis_rooms'));
            }
        }
    }
}
