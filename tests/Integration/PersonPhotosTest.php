<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Access\Enum\GrantKind;
use Domain\Person\Service\PersonPhotoService;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\PhotoFixture;

final class PersonPhotosTest extends TestCase
{
    private function context(): array
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        require_once dirname(__DIR__) . "/Support/PhotoFixture.php";
        $s = new \SisFixture();
        $s->registrationService();
        $s->sql->update(
            "users",
            [
                "token" => "fictional-photo-actor",
                "password" => "fictional-disabled-test-hash",
                "sign_in_enabled" => 1,
            ],
            ["user_id" => $s->access->actor->value],
        );
        $person = $s->legacyPerson($s->user(), "Fictional", "Photo");
        $roles = new SqlAccessRepository($s->sql);
        $duties = [
                "sis:person:manage",
                PersonPhotoService::MANAGE,
                "sis:import:manage",
            ];
        foreach ($duties as $duty) {
            $permission = $s->sql->one("sis_access_permissions", [
                "name" => $duty,
            ]);
            $roles->assign(
                GrantKind::UserPermission,
                $s->access->actor,
                new EntityId($permission["id"]),
                true,
            );
        }
        return [$s, $person, PhotoFixture::service($s), $roles];
    }

    public function testStaffPhotoIsReviewedEncryptedNormalizedAndProtectedFromPersonalReplacement(): void
    {
        [$s, $person, $service] = $this->context();
        $raw = PhotoFixture::image();
        $before = $s->sql->rows("sis_history_events");
        $review = $service->review(
            $person->id,
            true,
            0,
            "camera",
            "Record fictional institution photo.",
            $raw,
            "photo.png",
        );
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        $service->confirm(
            $person->id,
            true,
            new EntityId($review["draft_id"]),
            $review["review_token"],
        );
        $data = $service->view($person->id, true);
        self::assertTrue($data["institutional"]);
        self::assertTrue($data["has_photo"]);
        $bytes = $service->content($person->id, true);
        self::assertSame(
            [512, 512],
            array_slice(getimagesizefromstring($bytes), 0, 2),
        );
        self::assertStringNotContainsString("Fictional metadata", $bytes);
        self::assertStringNotContainsString(
            $raw,
            json_encode($s->sql->rows("sis_photo_payloads")),
        );
        $s->access->actor = $person->ownerUserId;
        $s->sql->update(
            "users",
            [
                "token" => "fictional-photo-owner",
                "password" => "fictional-disabled-test-hash",
                "sign_in_enabled" => 1,
            ],
            ["user_id" => $person->ownerUserId->value],
        );
        $s->access->staff = false;
        self::assertFalse($service->view(null, false)["can_edit"]);
        $this->expectException(
            \Domain\Shared\Exception\ValidationException::class,
        );
        $service->review(
            null,
            false,
            1,
            "upload",
            "Attempt replacement.",
            $raw,
            "photo.png",
        );
    }

    private function reviewed($service, $person, int $version = 0): array
    {
        return $service->review(
            $person->id,
            true,
            $version,
            "upload",
            "Reviewed fictional photo correction.",
            PhotoFixture::image(),
            "photo.png",
        );
    }

    private function accept($service, $person, array $review): array
    {
        return $service->confirm(
            $person->id,
            true,
            new EntityId($review["draft_id"]),
            $review["review_token"],
        );
    }

    public function testAnOlderReviewCannotReplaceANewerAcceptedPhoto(): void
    {
        [$s, $person, $service] = $this->context();
        $older = $this->reviewed($service, $person);
        $newer = $this->reviewed($service, $person);
        $this->accept($service, $person, $newer);
        $before = $s->sql->rows("sis_history_events");
        try {
            $this->accept($service, $person, $older);
            self::fail("An older photo review replaced a later selection.");
        } catch (\Domain\Shared\Exception\ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows("sis_history_events"));
            self::assertSame(1, $service->view($person->id, true)["version"]);
        }
    }

    public function testCurrentPhotoDutyIsRequiredAgainAtConfirmation(): void
    {
        [$s, $person, $service, $roles] = $this->context();
        $review = $this->reviewed($service, $person);
        $permission = new EntityId(
            $s->sql->one("sis_access_permissions", [
                "name" => PersonPhotoService::MANAGE,
            ])["id"],
        );
        $roles->assign(
            GrantKind::UserPermission,
            $s->access->actor,
            $permission,
            false,
        );
        $before = $s->sql->rows("sis_history_events");
        try {
            $this->accept($service, $person, $review);
            self::fail("Revoked photo authority accepted a change.");
        } catch (\Domain\Shared\Exception\AccessDeniedException) {
            self::assertSame($before, $s->sql->rows("sis_history_events"));
        }
    }

    public function testMandatoryAuditFailureRollsBackSelectionAndArtifactStateTogether(): void
    {
        [$s, $person, $service] = $this->context();
        $review = $this->reviewed($service, $person);
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit
            ->method("record")
            ->willThrowException(
                new \RuntimeException("Fictional required audit failure."),
            );
        $before = array_map($s->sql->rows(...), [
            "sis_photo_files",
            "sis_photo_payloads",
            "sis_history_events",
        ]);
        try {
            $this->accept(
                PhotoFixture::service($s, audit: $audit),
                $person,
                $review,
            );
            self::fail("A photo selection escaped its mandatory audit.");
        } catch (\RuntimeException) {
            self::assertSame(
                $before,
                array_map($s->sql->rows(...), [
                    "sis_photo_files",
                    "sis_photo_payloads",
                    "sis_history_events",
                ]),
            );
        }
    }

    public function testExpiredArtifactDisposalPreservesCurrentPhotoAndOriginalAcceptedRetries(): void
    {
        [$s, $person] = $this->context();
        $service = PhotoFixture::service(
            $s,
            policy: new \Domain\Person\Service\PhotoPolicy(true, true, 1, true),
        );
        $first = $this->reviewed($service, $person);
        $this->accept($service, $person, $first);
        $second = $this->reviewed($service, $person, 1);
        $this->accept($service, $person, $second);
        $s->clock->time = $s->clock->time->modify("+2 days");
        self::assertSame(1, $service->purge());
        self::assertSame(
            [],
            $s->sql->rows("sis_photo_payloads", [
                "artifact_id" => $first["draft_id"],
            ]),
        );
        $before = $s->sql->rows("sis_history_events");
        self::assertSame(
            1,
            $this->accept($service, $person, $first)["version"],
        );
        self::assertSame(2, $service->view($person->id, true)["version"]);
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        self::assertNotEmpty($service->content($person->id, true));
    }

    public function testDiscardOnlyRemovesWorkingArtifactsAndCannotDeleteAnAcceptedPhoto(): void
    {
        [$s, $person, $service] = $this->context();
        $review = $this->reviewed($service, $person);
        $service->discard($person->id, true, new EntityId($review["draft_id"]));
        self::assertSame([], $s->sql->rows("sis_photo_files"));
        self::assertSame([], $s->sql->rows("sis_photo_payloads"));
        $review = $this->reviewed($service, $person);
        $this->accept($service, $person, $review);
        $service->discard($person->id, true, new EntityId($review["draft_id"]));
        self::assertTrue($service->view($person->id, true)["has_photo"]);
    }

    public function testDamagedEncryptedPixelsCannotBeDisplayed(): void
    {
        [$s, $person, $service] = $this->context();
        $review = $this->reviewed($service, $person);
        $this->accept($service, $person, $review);
        $s->sql->execute("UPDATE sis_photo_payloads SET part = ?", [3]);
        $this->expectException(
            \Domain\Shared\EventSourcing\CorruptHistoryException::class,
        );
        $service->content($person->id, true);
    }

    public function testAnExpiredProjectionCannotPurgeTheCurrentProtectedPhoto(): void
    {
        [$s, $person, $service] = $this->context();
        $review = $this->reviewed($service, $person);
        $this->accept($service, $person, $review);
        $s->sql->execute("UPDATE sis_photo_files SET expires_at = ?", [
            $service->stamp("-1 day"),
        ]);
        $this->expectException(
            \Domain\Shared\EventSourcing\CorruptHistoryException::class,
        );
        $service->purge();
    }

    public function testGravatarNeedsOwnerConsentAndIsRetrievedOnceThenReadLocally(): void
    {
        [$s, $person] = $this->context();
        $s->access->actor = $person->ownerUserId;
        $s->access->staff = false;
        $s->sql->update(
            "users",
            [
                "token" => "fictional-owner",
                "password" => "fictional-disabled-hash",
                "sign_in_enabled" => 1,
                "email" => "photo@example.invalid",
            ],
            ["user_id" => $person->ownerUserId->value],
        );
        $reader = new class implements \Domain\Person\Service\GravatarReader {
            public int $calls = 0;
            public function image(#[\SensitiveParameter] string $email): string
            {
                $this->calls++;
                return PhotoFixture::image();
            }
        };
        $service = PhotoFixture::service($s, gravatar: $reader);
        try {
            $service->review(
                null,
                false,
                0,
                "gravatar",
                "Fictional owner choice.",
            );
            self::fail("Gravatar was contacted without owner consent.");
        } catch (\Domain\Shared\Exception\AccessDeniedException) {
            self::assertSame(0, $reader->calls);
        }
        $review = $service->review(
            null,
            false,
            0,
            "gravatar",
            "Fictional owner choice.",
            consent: true,
        );
        $service->confirm(
            null,
            false,
            new EntityId($review["draft_id"]),
            $review["review_token"],
        );
        $service->content(null, false);
        $service->content(null, false);
        self::assertSame(1, $reader->calls);
        self::assertFalse($service->view(null, false)["institutional"]);
    }

    public static function rejectedImages(): iterable
    {
        yield "wrong extension" => ["photo.jpg", "png", "clean"];
        yield "unsupported extension" => ["photo.jpeg", "jpeg", "clean"];
        yield "malware" => ["photo.png", "png", "infected"];
        yield "scanner unavailable" => ["photo.png", "png", "unavailable"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider("rejectedImages")]
    public function testUnacceptedImagesLeaveNoWorkingArtifactOrPhotoFact(
        string $filename,
        string $format,
        string $scan,
    ): void {
        [$s, $person] = $this->context();
        $scanner = $this->createStub(
            \Domain\Admissions\Service\AdmissionDocumentScanner::class,
        );
        $scanner->method("scan")->willReturn($scan);
        $before = $s->sql->rows("sis_history_events");
        try {
            PhotoFixture::service($s, scanner: $scanner)->review(
                $person->id,
                true,
                0,
                "upload",
                "Fictional rejected image.",
                PhotoFixture::image($format),
                $filename,
            );
            self::fail(
                "An unacceptable image reached the private photo store.",
            );
        } catch (\Domain\Shared\Exception\ValidationException) {
            self::assertSame([], $s->sql->rows("sis_photo_files"));
            self::assertSame($before, $s->sql->rows("sis_history_events"));
        }
    }

    public function testBackgroundZipImportRunsOnePhotoPerStepAndPublishesOnlyItsFinalNotice(): void
    {
        [$s, $person, $service] = $this->context();
        $other = $s->legacyPerson($s->user(), "Fictional", "Second");
        $zip = PhotoFixture::zip([
            $person->id->value . ".png" => PhotoFixture::image(),
            $other->institutionalId->value . ".jpg" => PhotoFixture::image(
                "jpeg",
            ),
        ]);
        $before = $s->sql->rows("sis_history_events");
        $review = PhotoFixture::imports($s)->review(
            $zip,
            "photos.zip",
            "id",
            "Import fictional institution photos.",
        );
        self::assertTrue($review["ready"]);
        $queue = PhotoFixture::queue($s);
        $queued = $queue->submit($review["review_token"]);
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        $worker = PhotoFixture::worker($s);
        self::assertSame("processed", $worker->runOne());
        self::assertTrue($service->view($person->id, true)["institutional"]);
        self::assertFalse($service->view($other->id, true)["has_photo"]);
        self::assertSame([], $s->sql->rows("sis_import_notices"));
        self::assertSame("completed", $worker->runOne());
        self::assertTrue($service->view($other->id, true)["institutional"]);
        self::assertCount(1, $s->sql->rows("sis_import_notices"));
        self::assertSame(
            2,
            (int) $s->sql->one("sis_import_jobs", ["id" => $queued["id"]])[
                "imported"
            ],
        );
        self::assertSame(
            "completed",
            $queue->submit($review["review_token"])["state"],
        );
        $beforeDelete = $s->sql->rows('sis_history_events');
        $notice = $s->sql->rows('sis_import_notices')[0];
        $queue->deleteNotice(new EntityId($notice['id']));
        self::assertSame([], $s->sql->rows('sis_import_notices'));
        $queue->submit($review['review_token']);
        self::assertSame([], $s->sql->rows('sis_import_notices'));
        self::assertSame($beforeDelete, $s->sql->rows('sis_history_events'));
    }

    public static function cancellationStates(): iterable
    {
        yield 'queued next step' => [false];
        yield 'claimed next step' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cancellationStates')]
    public function testCancellationFencesRemainingStepsAndKeepsAcceptedPhotosAndNoticeDeletion(bool $claimed): void
    {
        [$s, $person, $service] = $this->context();
        $other = $s->legacyPerson($s->user(), 'Fictional', 'Cancel');
        $zip = PhotoFixture::zip([
            $person->id->value . '.png' => PhotoFixture::image(),
            $other->id->value . '.png' => PhotoFixture::image(),
        ]);
        $review = PhotoFixture::imports($s)->review($zip, 'photos.zip', 'id', 'Reviewed cancellable photo group.');
        $queue = PhotoFixture::queue($s);
        $job = $queue->submit($review['review_token']);
        $worker = PhotoFixture::worker($s);
        self::assertSame('processed', $worker->runOne());
        $id = new EntityId($job['id']);
        $repository = new \Infrastructure\Persistence\Repository\SqlImportQueueRepository($s->sql);
        if ($claimed) {
            self::assertTrue($repository->claim(
                $id,
                (int) $repository->job($id)['revision'],
                'fictional-lease-to-fence',
                $service->stamp('+2 minutes')
            ));
        }
        $before = $s->sql->rows('sis_history_events');
        $cancelled = $queue->cancel($id);
        self::assertSame('cancelled', $cancelled['state']);
        self::assertSame(1, $cancelled['processed']);
        self::assertNull($repository->job($id)['lease_token']);
        self::assertNull($repository->artifact($id));
        self::assertSame('idle', $worker->runOne());
        self::assertTrue($service->view($person->id, true)['has_photo']);
        self::assertFalse($service->view($other->id, true)['has_photo']);
        self::assertSame($before, $s->sql->rows('sis_history_events'));
        self::assertCount(1, $s->sql->rows('sis_import_notices'));
        self::assertSame('cancelled', $queue->cancel($id)['state']);
        self::assertCount(1, $s->sql->rows('sis_import_notices'));
        $queue->deleteNotice(new EntityId($s->sql->rows('sis_import_notices')[0]['id']));
        self::assertSame('cancelled', $queue->submit($review['review_token'])['state']);
        self::assertSame([], $s->sql->rows('sis_import_notices'));
    }

    public function testChangedRemainingImportSourceStopsWithoutRewritingEarlierImportedPhotos(): void
    {
        [$s, $person, $service] = $this->context();
        $other = $s->legacyPerson($s->user(), "Fictional", "Remaining");
        $zip = PhotoFixture::zip([
            $person->id->value . ".png" => PhotoFixture::image(),
            $other->id->value . ".png" => PhotoFixture::image(),
        ]);
        $review = PhotoFixture::imports($s)->review(
            $zip,
            "photos.zip",
            "id",
            "Reviewed fictional group.",
        );
        $queued = PhotoFixture::queue($s)->submit($review["review_token"]);
        $worker = PhotoFixture::worker($s);
        self::assertSame("processed", $worker->runOne());
        $intervening = $this->reviewed($service, $other);
        $this->accept($service, $other, $intervening);
        $before = $s->sql->rows("sis_history_events");
        self::assertSame("needs_attention", $worker->runOne());
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        self::assertSame(
            1,
            (int) $s->sql->one("sis_import_jobs", ["id" => $queued["id"]])[
                "imported"
            ],
        );
    }

    public function testAmbiguousNamesAndDuplicatePeopleCannotQueueAnImport(): void
    {
        [$s, $person] = $this->context();
        $s->legacyPerson($s->user(), "Fictional", "Photo");
        $imports = PhotoFixture::imports($s);
        $review = $imports->review(
            PhotoFixture::image(),
            "fictional_photo.png",
            "name",
            "Review exact names.",
        );
        self::assertFalse($review["ready"]);
        $zip = PhotoFixture::zip([
            $person->id->value . ".png" => PhotoFixture::image(),
            $person->institutionalId->value . ".jpg" => PhotoFixture::image(
                "jpeg",
            ),
        ]);
        self::assertFalse(
            $imports->review(
                $zip,
                "photos.zip",
                "id",
                "Review distinct identities.",
            )["ready"],
        );
        self::assertSame([], $s->sql->rows("sis_photo_files"));
        self::assertSame([], $s->sql->rows("sis_import_jobs"));
    }

    public function testAnOriginalImportedSourceRetryCannotRestoreAnEarlierPhoto(): void
    {
        [$s, $person, $service] = $this->context();
        $basis = $service->snapshot($person->id, true);
        $bytes = PhotoFixture::image();
        $origin = [
            "archive_id" => EntityId::generate()->value,
            "ordinal" => 0,
            "digest" => hash("sha256", $bytes),
        ];
        $operation = \Domain\Shared\ValueObject\DerivedIdentity::from(
            new EntityId($origin["archive_id"]),
            "photo.0",
        );
        $service->importPhoto(
            $person->id,
            $operation,
            $basis,
            $bytes,
            "photo.png",
            "Fictional original import.",
            $origin,
        );
        $newer = $this->reviewed($service, $person, 1);
        $this->accept($service, $person, $newer);
        $before = $s->sql->rows("sis_history_events");
        $service->importPhoto(
            $person->id,
            $operation,
            $basis,
            $bytes,
            "photo.png",
            "Fictional original import.",
            $origin,
        );
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        self::assertSame(2, $service->view($person->id, true)["version"]);
    }

    public function testAWeakerDatabaseComparisonCannotAdoptADifferentAccentedName(): void
    {
        [$s] = $this->context();
        $s->legacyPerson($s->user(), "José", "Import");
        $s->connection->pdo->sqliteCreateFunction(
            "lower",
            static fn(string $text): string => mb_strtolower(
                str_replace("é", "e", $text),
            ),
        );
        $review = PhotoFixture::imports($s)->review(
            PhotoFixture::image(),
            "Jose_Import.png",
            "name",
            "Require an exact name despite the database comparison.",
        );
        self::assertFalse($review["ready"]);
        self::assertSame([], $s->sql->rows("sis_import_jobs"));
    }

    public function testCancellationRemainsOwnerScopedAndRollsBackIfItsRequiredAuditFails(): void
    {
        [$s, $person] = $this->context();
        $review = PhotoFixture::imports($s)->review(
            PhotoFixture::image(),
            $person->id->value . '.png',
            'id',
            'Review a fictional cancellable file.'
        );
        $job = PhotoFixture::queue($s)->submit($review['review_token']);
        $id = new EntityId($job['id']);
        $actor = $s->access->actor;
        $s->access->actor = $s->user();
        try {
            PhotoFixture::queue($s)->cancel($id);
            self::fail('Another account cancelled private work.');
        } catch (\Domain\Shared\Exception\AccessDeniedException) {
            self::assertSame('queued', $s->sql->one('sis_import_jobs', ['id' => $id->value])['state']);
        }
        $s->access->actor = $actor;
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willThrowException(new \RuntimeException('Fictional cancellation audit failure.'));
        $repository = new \Infrastructure\Persistence\Repository\SqlImportQueueRepository($s->sql);
        $queue = new \Domain\Import\Service\ImportQueueService(
            $repository,
            $s->access,
            $s->transactions,
            $audit,
            $s->dropCipher,
            $s->clock
        );
        $before = $repository->artifact($id);
        try {
            $queue->cancel($id);
            self::fail('Cancellation escaped its mandatory audit.');
        } catch (\RuntimeException) {
            self::assertSame('queued', $repository->job($id)['state']);
            self::assertSame($before, $repository->artifact($id));
            self::assertSame([], $s->sql->rows('sis_import_notices'));
        }
    }

    public static function unsafeArchives(): iterable
    {
        yield "traversal" => ["../person.png"];
        yield "directory" => ["folder/person.png"];
        yield "unsupported file" => ["person.php"];
        yield "hidden file" => [".person.png"];
        yield "backslash" => ["folder\\person.png"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider("unsafeArchives")]
    public function testUnsafeArchiveNamesAreRejectedWithoutExtraction(
        string $name,
    ): void {
        [$s] = $this->context();
        $this->expectException(
            \Domain\Shared\Exception\ValidationException::class,
        );
        PhotoFixture::imports($s)->review(
            PhotoFixture::zip([$name => PhotoFixture::image()]),
            "photos.zip",
            "id",
            "Reject unsafe archive.",
        );
    }
}
