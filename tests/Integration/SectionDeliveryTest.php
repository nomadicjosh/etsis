<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/CatalogWorkflowFixture.php';

use Domain\Catalog\Service\CatalogEditor;
use Domain\Catalog\ValueObject\OfferingPlan;
use Domain\Registration\Enum\DeliveryMode;
use Domain\Registration\Section;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCampusRepository;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use PHPUnit\Framework\TestCase;

final class SectionDeliveryTest extends TestCase
{
    public function testInPersonOnlineAndHybridSectionsCanRegisterWithoutAnyRoomReservation(): void
    {
        $c = new CatalogWorkflowFixture();
        $f = $c->sis;
        $existing = $f->section(20, 0);
        $student = $f->student();
        foreach ([DeliveryMode::InPerson, DeliveryMode::Online, DeliveryMode::Hybrid] as $mode) {
            $section = new Section(
                EntityId::generate(),
                $existing->courseId,
                $f->term,
                null,
                'MATH.101.' . $mode->value,
                20,
                true,
                true,
                $existing->fee,
                $mode
            );
            $f->registration->addSection($section);
            self::assertSame($mode, $f->registration->section($section->id)->deliveryMode);
            $f->service->register($student->id, $section->id);
        }
        self::assertSame([], $f->sql->rows('sis_bookings'));
        $rows = new SqlCampusRepository($f->sql)->sections($f->term, '', null)['items'];
        self::assertContains('Online', array_column($rows, 'delivery_label'));
        self::assertContains('Hybrid', array_column($rows, 'delivery_label'));
    }

    public function testDraftOfferingRetainsModeAndAChangeInvalidatesPublicationReview(): void
    {
        $c = new CatalogWorkflowFixture();
        $f = $c->sis;
        $existing = $f->section(20, 0);
        $draft = new Section(
            EntityId::generate(),
            $existing->courseId,
            $f->term,
            null,
            'MATH.101.ONLINE',
            20,
            false,
            true,
            $existing->fee,
            DeliveryMode::Online
        );
        $plan = new OfferingPlan($f->term, $draft);
        $service = $c->offerings();
        $review = $service->review($plan);
        $id = new EntityId($review['operation_id']);
        $service->save($id, $plan, $review['revision']);
        self::assertSame(DeliveryMode::Online, $f->registration->section($draft->id)->deliveryMode);
        self::assertSame('online', $service->saved($id)['review']['sections'][0]['delivery_mode']);
        $publication = $c->publicationReview($service, $id);
        $editor = new CatalogEditor(new SqlCatalogRevisionRepository($f->sql), $f->registration, $f->access, $f->transactions, $f->audit);
        $current = $editor->view('sections', $draft->id);
        $editor->section(new Section(
            $draft->id,
            $draft->courseId,
            $f->term,
            null,
            $draft->code,
            20,
            false,
            false,
            $draft->fee,
            DeliveryMode::Hybrid
        ), $current['revision']);
        try {
            $service->publish($id, $publication['review_token']);
            self::fail('An unreviewed delivery mode was published.');
        } catch (ConcurrentModificationException) {
            self::assertFalse($f->registration->section($draft->id)->active);
        }
        $fresh = $c->publicationReview($service, $id);
        $service->publish($id, $fresh['review_token']);
        self::assertSame(DeliveryMode::Hybrid, $f->registration->section($draft->id)->deliveryMode);
    }

    public function testSectionAndItsDeliveryModeAreInsertedAtomically(): void
    {
        $c = new CatalogWorkflowFixture();
        $f = $c->sis;
        $existing = $f->section(20, 0);
        $draft = new Section(
            EntityId::generate(),
            $existing->courseId,
            $f->term,
            null,
            'MATH.FAIL',
            20,
            false,
            true,
            $existing->fee,
            DeliveryMode::Online
        );
        $f->connection->pdo->exec("CREATE TRIGGER fictional_delivery_failure BEFORE INSERT ON sis_section_delivery BEGIN SELECT RAISE(ABORT, 'Fictional delivery failure'); END");
        try {
            $f->registration->addSection($draft);
            self::fail('A partial section was saved.');
        } catch (PDOException) {
            self::assertSame([], $f->sql->rows('sis_sections', ['id' => $draft->id->value]));
        }
    }
}
