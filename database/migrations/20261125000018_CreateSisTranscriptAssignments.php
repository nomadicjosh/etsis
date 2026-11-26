<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;
use Qubus\Expressive\Schema\AlterTable;

final class CreateSisTranscriptAssignments extends Migration
{
    public function up(): void
    {
        $this->schema()->create("sis_transcript_assignments", self::define(...));
        $this->schema()->alter("sis_transcript_requests", self::queue(...));
    }
    public static function define(CreateTable $t): void
    {
        $t->string("id", 26)->primary()->notNull();
        $t->integer("version")->notNull();
        $t->string("assignee_id", 36);
        $t->string("assignee_person_id", 26);
        $t->string("updated_at", 27)->notNull();
        $t->index(["assignee_id", "assignee_person_id", "id"], "sta_assignee_page");
        $t->foreign("id", "sta_request_fk")->references("sis_transcript_requests", "id");
        $t->foreign("assignee_person_id", "sta_person_fk")->references("sis_persons", "id");
    }
    public static function queue(AlterTable $t): void
    {
        $t->index(["status", "id"], "str_status_page");
    }
    public function down(): void
    {
        throw new LogicException(
            "Preserve recorded transcript assignments until institution-approved disposition.",
        );
    }
}
