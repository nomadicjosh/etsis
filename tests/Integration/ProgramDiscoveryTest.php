<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProgramDiscoveryFixture;

final class ProgramDiscoveryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/ProgramDiscoveryFixture.php';
    }

    public function testAcademicPublicationAloneDoesNotPublishWebsiteContentAndExplicitChoiceStaysPinned(): void
    {
        $f = new ProgramDiscoveryFixture();
        $catalog = $f->publish();
        $service = $f->service();
        self::assertSame([], $service->page()['items']);
        $events = $f->sis->sql->rows('sis_history_events');
        $plan = $service->review($f->sis->program, $catalog, true, 0);
        $saved = $f->commit($plan);
        self::assertTrue($saved['visible']);
        self::assertSame($saved, $f->commit($plan));
        self::assertSame('Fictional Arts program', $service->detail($f->sis->program)['title']);
        self::assertSame($events, $f->sis->sql->rows('sis_history_events'));
        $newer = $f->publish(title: 'Fictional later program');
        self::assertNotSame($catalog->value, $newer->value);
        self::assertSame('Fictional Arts program', $service->detail($f->sis->program)['title']);
        self::assertCount(1, $service->page('arts')['items']);
        self::assertSame([], $service->page('later')['items']);
        $hide = $service->review($f->sis->program, $catalog, false, 1);
        $f->commit($hide);
        self::assertSame([], $service->page()['items']);
        $this->expectException(RecordNotFoundException::class);
        $service->detail($f->sis->program);
    }

    public function testAuthorityIsConjunctiveAndPublicReadsNeedNoStaffOrAccount(): void
    {
        $f = new ProgramDiscoveryFixture();
        $catalog = $f->publish();
        $service = $f->service();
        foreach (['sis:catalog:manage', 'sis:portal:manage', 'sis:portal:publish'] as $missing) {
            $f->sis->access->staff = false;
            $f->sis->access->grants = array_values(array_diff(
                ['sis:catalog:manage', 'sis:portal:manage', 'sis:portal:publish'],
                [$missing],
            ));
            try {
                $service->review($f->sis->program, $catalog, true, 0);
                self::fail('A missing independent publishing duty was ignored.');
            } catch (AccessDeniedException) {
                self::assertSame([], $service->page()['items']);
            }
        }
        $f->sis->access->staff = true;
        $f->commit($service->review($f->sis->program, $catalog, true, 0));
        $f->sis->access->staff = false;
        $f->sis->access->grants = [];
        $detail = $service->detail($f->sis->program);
        self::assertArrayNotHasKey('specializations', $detail);
        self::assertArrayNotHasKey('actor_id', $detail);
        self::assertArrayNotHasKey('publication_hash', $detail);
        self::assertSame([], $detail['components']);
        self::assertFalse($detail['application_available']);
    }

    public function testExpiryStaleStateAndOldRetriesCannotRestoreAnEarlierVisibleVersion(): void
    {
        $f = new ProgramDiscoveryFixture();
        $catalog = $f->publish();
        $service = $f->service();
        $expired = $service->review($f->sis->program, $catalog, true, 0);
        $f->sis->clock->time = $f->sis->clock->time->modify('+21 minutes');
        try {
            $f->commit($expired);
            self::fail('An expired website review was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $service->page()['items']);
        }
        $plan = $service->review($f->sis->program, $catalog, true, 0);
        $f->commit($plan);
        $f->commit($service->review($f->sis->program, $catalog, false, 1));
        try {
            $f->commit($plan);
            self::fail('An earlier retry restored a withdrawn listing.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $service->page()['items']);
        }
    }

    public function testAuditAuthorityAndSourceChangesRollBackTheWholeWebsitePublication(): void
    {
        foreach (['audit', 'authority', 'source'] as $failure) {
            $f = new ProgramDiscoveryFixture();
            $catalog = $f->publish();
            $events = $f->sis->sql->rows('sis_history_events');
            $source = $f->sis->sql->rows('sis_program_catalog_details');
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($f, $failure, $catalog): void {
                if ($failure === 'audit') {
                    throw new \RuntimeException('Fictional audit failure.');
                }
                if ($failure === 'authority') {
                    $f->sis->access->staff = false;
                } else {
                    $f->sis->sql->update(
                        'sis_program_catalog_details',
                        ['description' => 'Fictional tampered source.'],
                        ['id' => $catalog->value]
                    );
                }
            });
            $service = $f->service($audit);
            $plan = $service->review($f->sis->program, $catalog, true, 0);
            try {
                $f->commit($plan, $service);
                self::fail('An unaudited, unauthorized or tampered website source committed.');
            } catch (\RuntimeException | \DomainException $error) {
                self::assertNotSame('', $error->getMessage());
                self::assertSame([], $f->sis->sql->rows('sis_program_discovery'));
                self::assertSame($source, $f->sis->sql->rows('sis_program_catalog_details'));
                self::assertSame($events, $f->sis->sql->rows('sis_history_events'));
            }
        }
    }

    public function testPublicDetailFailsClosedForProjectionChangesAndForeignOrUnknownCatalogs(): void
    {
        $f = new ProgramDiscoveryFixture();
        $catalog = $f->publish();
        $service = $f->service();
        $f->commit($service->review($f->sis->program, $catalog, true, 0));
        $f->sis->sql->update(
            'sis_program_catalog_details',
            ['description' => 'Fictional changed public wording.'],
            ['id' => $catalog->value]
        );
        try {
            $service->detail($f->sis->program);
            self::fail('An altered retained projection was exposed as reviewed wording.');
        } catch (RecordNotFoundException) {
            self::assertCount(1, $f->sis->sql->rows('sis_program_discovery'));
        }
        $this->expectException(RecordNotFoundException::class);
        $service->review($f->sis->program, EntityId::generate(), true, 1);
    }

    public function testPagesFilterBeforeTheBoundedCursorAndNeverExposeWorkingDescriptions(): void
    {
        $f = new ProgramDiscoveryFixture();
        $service = $f->service();
        for ($i = 0; $i < 13; ++$i) {
            $program = $i === 0 ? $f->sis->program : EntityId::generate();
            if ($i > 0) {
                $f->sis->sql->insert('sis_programs', ['id' => $program->value, 'code' => 'FICTIONAL.' . $i,
                    'title' => 'Private working label ' . $i]);
            }
            $catalog = $f->publish($program, 'Fictional public program ' . $i);
            $plan = $service->review($program, $catalog, true, 0);
            $service->commit(
                $program,
                $catalog,
                true,
                0,
                new EntityId($plan['operation_id']),
                $plan['revision'],
                $plan['review_token']
            );
        }
        $first = $service->page('public');
        self::assertCount(12, $first['items']);
        self::assertNotNull($first['next_after']);
        self::assertCount(1, $service->page('public', new EntityId($first['next_after']))['items']);
        self::assertSame([], $service->page('Private working')['items']);
        self::assertSame([], $service->page('%')['items']);
        self::assertStringNotContainsString('description', json_encode($first, JSON_THROW_ON_ERROR));
    }
}
