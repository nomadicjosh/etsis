<?php

declare(strict_types=1);

namespace Tests\Integration;

use CampusFixture;
use Domain\Portal\Service\CampusContentService;
use Domain\Portal\ValueObject\PublicationWindow;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class CampusContentPublicationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/CampusFixture.php";
    }

    private function draft(CampusFixture $c, string $audience = "public"): EntityId
    {
        return $c->content->save(
            null,
            "news",
            "fictional-" . strtolower(substr(EntityId::generate()->value, -8)),
            "Fictional campus news",
            "A fictional welcome, not a student record.",
            $audience,
            null,
        );
    }

    private function review(
        CampusFixture $c,
        EntityId $post,
        string $action = "approve",
        ?string $from = null,
        ?string $until = null,
        ?string $note = null,
    ): array {
        return $c->content->review(
            $post,
            $action,
            $c->content->get($post)["revision"],
            new PublicationWindow($from, $until),
            $note,
        );
    }

    private function confirm(CampusContentService $service, EntityId $post, array $r): array
    {
        return $service->confirm(
            $post,
            new EntityId($r["operation_id"]),
            $r["action"],
            $r["revision"],
            new PublicationWindow($r["window"]["visible_from"], $r["window"]["visible_until"]),
            $r["note"],
            $r["review_token"],
        );
    }

    public function testAuthorSubmitsPublisherReturnsFeedbackAndApprovalIsAnIndependentDuty(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $post = $this->draft($c);
        $s->access->staff = false;
        $s->access->grants = ["sis:portal:manage"];
        try {
            $this->review($c, $post);
            self::fail("Authoring authority granted publishing authority.");
        } catch (AccessDeniedException) {
            self::assertSame("draft", $c->content->get($post)["status"]);
        }
        $submitted = $this->review($c, $post, "submit");
        self::assertSame("pending", $this->confirm($c->content, $post, $submitted)["status"]);
        self::assertSame([], $c->service->news());
        $s->access->staff = true;
        $publisher = $s->user();
        $s->access->staff = false;
        $s->access->actor = $publisher;
        $s->access->grants = ["sis:portal:manage", "sis:portal:publish"];
        self::assertFalse($c->content->get($post)["can_edit"]);
        $review = $this->review($c, $post, "return", note: "Fictional request to clarify the date.");
        self::assertSame("draft", $this->confirm($c->content, $post, $review)["status"]);
        self::assertSame("Fictional request to clarify the date.", $c->content->get($post)["review_note"]);
        $this->expectException(AccessDeniedException::class);
        $this->review($c, $post);
    }

    public function testReviewedWindowControlsListsDirectArticlesAndAuthenticatedContentAtExactBoundaries(): void
    {
        $c = new CampusFixture();
        $post = $this->draft($c);
        $code = $c->content->get($post)["code"];
        $events = $c->sis->sql->rows("sis_history_events");
        $review = $this->review(
            $c,
            $post,
            from: "2026-08-11T10:00:00-07:00",
            until: "2026-08-12T10:00:00-07:00",
        );
        $this->confirm($c->content, $post, $review);
        self::assertSame("scheduled", $c->content->get($post)["visibility"]);
        foreach ([false, true] as $campus) {
            self::assertSame([], $c->service->news($campus));
        }
        try {
            $c->service->article($code);
            self::fail("A direct URL exposed scheduled content early.");
        } catch (RecordNotFoundException) {
            self::assertTrue(true);
        }
        $c->sis->clock->time = new \DateTimeImmutable("2026-08-11T10:00:00-07:00");
        self::assertCount(1, $c->service->news());
        self::assertSame("Fictional campus news", $c->service->article($code)["title"]);
        self::assertSame($post->value, $c->posts->published($code)->id->value);
        $c->sis->clock->time = new \DateTimeImmutable("2026-08-12T10:00:00-07:00");
        self::assertSame("expired", $c->content->get($post)["visibility"]);
        self::assertSame([], $c->service->news(true));
        self::assertSame($events, $c->sis->sql->rows("sis_history_events"));
        $this->expectException(RecordNotFoundException::class);
        $c->posts->published($code);
    }

    public function testCampusAudienceNeverBecomesPublicWhenItsWindowOpens(): void
    {
        $c = new CampusFixture();
        $post = $this->draft($c, "campus");
        $this->confirm($c->content, $post, $this->review($c, $post));
        self::assertSame([], $c->service->news());
        self::assertCount(1, $c->service->news(true));
        $this->expectException(RecordNotFoundException::class);
        $c->service->article($c->content->get($post)["code"]);
    }

    public function testChangedAudienceAndWordingInvalidateAReviewAndOriginalRetriesPreserveLaterEdits(): void
    {
        $c = new CampusFixture();
        $post = $this->draft($c);
        $review = $this->review($c, $post);
        $saved = $c->content->get($post);
        $c->content->save(
            $post,
            "news",
            $saved["code"],
            "Fictional revised title",
            "Fictional revised text.",
            "campus",
            0,
        );
        try {
            $this->confirm($c->content, $post, $review);
            self::fail("An old public review approved a newer campus draft.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $c->sis->sql->rows("sis_content_operations"));
        }
        $review = $this->review($c, $post);
        $receipt = $this->confirm($c->content, $post, $review);
        $c->content->save(
            $post,
            "news",
            $saved["code"],
            "Fictional later title",
            "Fictional later text.",
            "public",
            2,
        );
        $c->sis->clock->time = $c->sis->clock->time->modify("+1 hour");
        self::assertSame($receipt, $this->confirm($c->content, $post, $review));
        self::assertSame("draft", $c->content->get($post)["status"]);
        self::assertSame("Fictional later title", $c->content->get($post)["title"]);
    }

    public function testAlteredTimesAndAnotherPublisherCannotReuseTheReviewedProof(): void
    {
        $c = new CampusFixture();
        $post = $this->draft($c);
        $review = $this->review($c, $post);
        $altered = $review;
        $altered["window"]["visible_until"] = "2026-08-20T10:00:00Z";
        try {
            $this->confirm($c->content, $post, $altered);
            self::fail("Confirmation substituted a different publication window.");
        } catch (ValidationException) {
            self::assertSame([], $c->sis->sql->rows("sis_content_operations"));
        }
        $c->sis->access->actor = $c->sis->user();
        $this->expectException(AccessDeniedException::class);
        $this->confirm($c->content, $post, $review);
    }

    public function testPublicationCannotPassItsSelectedStartWhileWaitingForConfirmation(): void
    {
        $c = new CampusFixture();
        $post = $this->draft($c);
        $r = $this->review($c, $post, from: "2026-08-10T10:05:00-07:00");
        $c->sis->clock->time = $c->sis->clock->time->modify("+5 minutes");
        $this->expectException(ValidationException::class);
        $this->confirm($c->content, $post, $r);
    }

    public function testEmptyAndMalformedReturnNotesAndUnzonedOrReversedWindowsAreRejected(): void
    {
        $invalidWindows = [
            ["2026-08-20T10:00:00", null],
            ["2026-02-30T10:00:00Z", null],
            ["2026-08-20T10:00:00Z", "2026-08-20T10:00:00Z"],
        ];
        foreach ($invalidWindows as [$from, $until]) {
            try {
                new PublicationWindow($from, $until);
                self::fail("An ambiguous or impossible window was accepted.");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $c = new CampusFixture();
        $post = $this->draft($c);
        $this->confirm($c->content, $post, $this->review($c, $post, "submit"));
        $this->expectException(ValidationException::class);
        $this->review($c, $post, "return");
    }

    public function testOldPublishedRowsRemainVisibleWithoutFabricatingApprovalOrEvents(): void
    {
        $c = new CampusFixture();
        $post = $this->draft($c);
        $c->sis->sql->update("sis_portal_posts", ["status" => "published"], ["id" => $post->value]);
        $before = $c->sis->sql->rows("sis_history_events");
        self::assertCount(1, $c->service->news());
        self::assertSame($before, $c->sis->sql->rows("sis_history_events"));
        self::assertSame([], $c->sis->sql->rows("sis_content_operations"));
    }

    public function testFinalAuthorityLossRollsBackPublicationVisibilityReceiptAndAudit(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $post = $this->draft($c);
        $r = $this->review($c, $post);
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit
            ->expects(self::once())
            ->method("record")
            ->willReturnCallback(static function () use ($s): void {
                $s->access->staff = false;
                $s->access->grants = ["sis:portal:manage"];
            });
        $service = new CampusContentService(
            new \Infrastructure\Persistence\Repository\SqlPortalRepository($s->sql, $s->clock),
            $c->repository,
            $c->posts,
            $s->access,
            $s->transactions,
            $audit,
            $s->clock,
            new \Domain\Shared\Service\ReviewProof($c->cipher, $s->clock),
            new \Infrastructure\Portal\SymfonyContentMarkup(),
            new \Infrastructure\Persistence\Repository\SqlContentAudienceRepository($s->sql),
        );
        $before = $s->sql->rows("sis_portal_posts");
        try {
            $this->confirm($service, $post, $r);
            self::fail("Publication survived loss of publishing authority.");
        } catch (AccessDeniedException) {
            self::assertSame($before, $s->sql->rows("sis_portal_posts"));
            self::assertSame([], $s->sql->rows("sis_content_operations"));
            self::assertSame([], $c->service->news());
        }
    }
    public function testMigrationPreservesExistingContentEventsAndGrantsAndCanBeRepeated(): void
    {
        $c = new CampusFixture();
        $id = $this->draft($c);
        $c->sis->sql->update("sis_portal_posts", ["status" => "published"], ["id" => $id->value]);
        $tables = [
            "sis_portal_posts",
            "sis_portal_audiences",
            "sis_history_events",
            "sis_access_role_permissions",
            "sis_access_user_permissions",
        ];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $c->sis->sql->rows($table);
        }
        $migration = new \AddSisContentPublicationWorkflow("content-schema");
        $migration->setObjectMap(
            new \ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $c->sis->connection,
                    "migration",
                ),
            ]),
        );
        $migration->up();
        $migration->up();
        foreach ($tables as $table) {
            self::assertSame($before[$table], $c->sis->sql->rows($table));
        }
        self::assertSame([], $c->sis->sql->rows("sis_content_operations"));
        self::assertSame(
            1,
            (int) $c->sis->sql->one("sis_access_permissions", ["name" => "sis:portal:publish"])["is_system"],
        );
    }

    public function testSchemaCompilesForAllFiveFrameworkDialectsWithoutReplacingRecordedFacts(): void
    {
        $c = new CampusFixture();
        $definitions = [];
        $schema = $this->createStub(\Qubus\Expressive\Schema::class);
        $schema->method("getColumns")->willReturn([]);
        $schema->method("hasTable")->willReturn(false);
        $schema
            ->method("alter")
            ->willReturnCallback(static function (string $name, callable $build) use (&$definitions): void {
                $t = new \Qubus\Expressive\Schema\AlterTable($name);
                $build($t);
                $definitions["alter"] = $t;
            });
        $schema
            ->method("create")
            ->willReturnCallback(static function (string $name, callable $build) use (&$definitions): void {
                $t = new \Qubus\Expressive\Schema\CreateTable($name);
                $build($t);
                $definitions["create"] = $t;
            });
        $connection = $this->createStub(\Qubus\Expressive\Connection::class);
        $connection->method("getSchema")->willReturn($schema);
        $connection->method("queryBuilder")->willReturn($c->sis->connection->queryBuilder());
        $connection
            ->method("quoteIdentifier")
            ->willReturnCallback(static fn(string $name): string => '"' . $name . '"');
        $migration = new \AddSisContentPublicationWorkflow("content-schema");
        $migration->setObjectMap(
            new \ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $connection,
                    "migration",
                ),
            ]),
        );
        $migration->up();
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $compiler = new $class($connection);
            $sql = json_encode(
                [...$compiler->alter($definitions["alter"]), ...$compiler->create($definitions["create"])],
                JSON_THROW_ON_ERROR,
            );
            self::assertStringContainsString("visible_from", $sql);
            self::assertStringContainsString("spp_visible_news_idx", $sql);
            self::assertStringContainsString("sis_content_operations", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE FROM|AUTO_INCREMENT|sis_history_events/i",
                $sql,
            );
        }
    }
    public function testRichNewsPreservesFormattingAndStripsExecutableMarkupAndExternalMedia(): void
    {
        $c = new CampusFixture();
        $payload = implode("", [
            "<h2>Fictional welcome</h2><p>Visit <strong>campus</strong> and <em>learn</em>.</p>",
            '<ul><li>Meet faculty</li></ul><a href="https://example.invalid/" onclick="bad()">Details</a>',
            '<script>window.newsAttack=1</script><img src="https://example.invalid/tracker" onerror="bad()">',
            '<iframe src="https://example.invalid/"></iframe><svg onload="bad()"></svg>',
            '<p style="position:fixed" id="private">Safe text</p><a href="javascript:bad()">No unsafe link</a>',
        ]);
        $id = $c->content->save(
            null,
            "news",
            "fictional-rich",
            "Fictional rich news",
            $payload,
            "public",
            null,
            "html",
        );
        $saved = $c->content->get($id);
        self::assertSame("html", $saved["content_format"]);
        self::assertStringContainsString("<strong>campus</strong>", $saved["content"]);
        $unsafeFragments = [
            "<script",
            "<img",
            "<iframe",
            "<svg",
            "onclick=",
            "onerror=",
            "javascript:",
            "style=",
            "id=",
        ];
        foreach ($unsafeFragments as $unsafe) {
            self::assertStringNotContainsString($unsafe, $saved["content"]);
        }
        $c->publish($id, true, 0);
        $article = $c->service->article("fictional-rich");
        self::assertSame($saved["content"], $article["content_html"]);
        self::assertStringNotContainsString("<", $article["excerpt"]);
        self::assertStringContainsString("Visit campus and learn.", $article["excerpt"]);
        self::assertStringNotContainsString("newsAttack", $article["excerpt"]);
    }

    public function testPlainTextMarkupStaysLiteralAndEmptyRichContentCannotBeSaved(): void
    {
        $c = new CampusFixture();
        $id = $c->content->save(
            null,
            "news",
            "fictional-plain",
            "Fictional plain text",
            "<b>Literal text</b>",
            "public",
            null,
        );
        $c->publish($id, true, 0);
        self::assertNull($c->service->article("fictional-plain")["content_html"]);
        self::assertSame("<b>Literal text</b>", $c->service->article("fictional-plain")["content"]);
        $this->expectException(ValidationException::class);
        $c->content->save(
            null,
            "news",
            "fictional-empty",
            "Empty",
            "<p><br></p><script>bad()</script>",
            "public",
            null,
            "html",
        );
    }
    public function testPublisherDirectoryScopeIsSeparateFromEditingAndRecheckedAfterReading(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $publisher = false;
        $revoke = false;
        $owners = [];
        $permissions = $this->createStub(\Domain\Access\Repository\AccessRepository::class);
        $permissions
            ->method("allows")
            ->willReturnCallback(static function (string $user, string $permission) use (&$publisher): bool {
                return $permission === "sis:portal:publish" && $publisher;
            });
        $records = $this->createStub(\Domain\Workspace\Repository\WorkspaceRepository::class);
        $records
            ->method("records")
            ->willReturnCallback(static function (
                string $dataset,
                ?EntityId $owner
            ) use (
                &$owners,
                &$publisher,
                &$revoke,
            ): array {
                $owners[] = $owner?->value;
                if ($revoke) {
                    $publisher = false;
                }
                return ["items" => [], "next_after" => null];
            });
        $workspace = new \Domain\Workspace\Service\WorkspaceService(
            $records,
            $s->access,
            $permissions,
            $s->admissionsService(),
        );
        $workspace->records("portal");
        $publisher = true;
        $workspace->records("portal");
        self::assertSame([$s->access->actor->value, null], $owners);
        $revoke = true;
        $this->expectException(AccessDeniedException::class);
        $workspace->records("portal");
    }

    public function testOversizeNewsIsRejectedBeforeThePortableTextColumnCanTruncateIt(): void
    {
        $c = new CampusFixture();
        $before = $c->sis->sql->rows('sis_portal_posts');
        try {
            $c->content->save(
                null,
                'news',
                'oversize-fictional',
                'Fictional long news',
                str_repeat('a', 60001),
                'public',
                null,
            );
            self::fail('Oversize content reached database storage.');
        } catch (ValidationException) {
            self::assertSame($before, $c->sis->sql->rows('sis_portal_posts'));
        }
    }
}
