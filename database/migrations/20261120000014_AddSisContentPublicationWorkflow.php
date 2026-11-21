<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\PortableTransactions;
use Infrastructure\Persistence\SqlRecords;
use Qubus\Expressive\Migration\Adapter\ConnectionAwareMigrationAdapter;
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;
use Qubus\Expressive\Schema\AlterTable;

final class AddSisContentPublicationWorkflow extends Migration
{
    public function up(): void
    {
        $columns = $this->schema()->getColumns("sis_portal_posts", clear: true);
        $missing = array_diff(["visible_from", "visible_until", "review_note", "content_format"], $columns);
        if ($missing !== []) {
            $this->schema()->alter("sis_portal_posts", static function (AlterTable $t) use ($missing): void {
                foreach ($missing as $name) {
                    if ($name === "content_format") {
                        $t->string($name, 8)->notNull()->defaultValue("plain");
                    } elseif ($name === "review_note") {
                        $t->text($name);
                    } else {
                        $t->string($name, 20);
                    }
                }
                if (in_array("visible_from", $missing, true)) {
                    $t->index(["status", "kind", "visible_from", "visible_until"], "spp_visible_news_idx");
                }
            });
        }
        if (!$this->schema()->hasTable("sis_content_operations", clear: true)) {
            $this->schema()->create("sis_content_operations", static function (CreateTable $t): void {
                $t->string("id", 26)->notNull()->primary();
                $t->string("post_id", 26)->notNull();
                $t->string("actor_id", 36)->notNull();
                $t->string("intent", 64)->notNull();
                $t->text("result")->notNull();
                $t->foreign("post_id", "sco_post_fk")->references("sis_portal_posts", "id");
            });
        }
        $adapter = $this->getAdapter();
        if (!($adapter instanceof ConnectionAwareMigrationAdapter)) {
            throw new LogicException("Content publication need a database connection.");
        }
        $db = $adapter->connection()->queryBuilder();
        PortableTransactions::run($db, static function () use ($db): void {
            $sql = new SqlRecords($db);
            $name = "sis:portal:publish";
            $permission = $sql->rows("sis_access_permissions", ["name" => $name], 1)[0] ?? null;
            if ($permission !== null && (int) $permission["is_system"] !== 1) {
                throw new LogicException("Conflicting content publishing permission.");
            }
            $id = $permission["id"] ?? EntityId::generate()->value;
            if ($permission === null) {
                $sql->insert("sis_access_permissions", [
                    "id" => $id,
                    "name" => $name,
                    "description" => "Approve, schedule, return and archive campus content.",
                    "is_system" => 1,
                ]);
            }
            if (
                $sql->rows("sis_access_implications", ["ancestor_id" => $id, "descendant_id" => $id], 1) ===
                []
            ) {
                $sql->insert("sis_access_implications", [
                    "id" => EntityId::generate()->value,
                    "ancestor_id" => $id,
                    "descendant_id" => $id,
                ]);
            }
        });
    }

    public function down(): void
    {
        throw new LogicException("Preserve content publication receipts and current visibility settings.");
    }
}
