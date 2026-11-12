<?php
declare(strict_types=1);
use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;
use Qubus\Expressive\Schema\AlterTable;
final class CreateSisMoodleLaunches extends Migration
{
    public function up(): void
    {
        $this->schema()->alter("sis_persons", static function (AlterTable $t): void {
            $t->index(["owner_user_id", "id"], "moodle_owner_person_idx");
        });
        $this->schema()->create("sis_moodle_launches", static function (CreateTable $t): void {
            $t->string("id", 26)->primary()->notNull();
            $t->string("ticket_hash", 64)->notNull()->unique("moodle_launch_ticket_uq");
            $t->string("session_id", 26)->notNull();
            $t->string("user_id", 26)->notNull();
            $t->string("person_id", 26)->notNull();
            $t->string("binding_id", 26)->notNull();
            $t->string("link_id", 26)->notNull();
            $t->string("moodle_user_id", 20)->notNull();
            $t->string("ticket_expires_at", 32)->notNull();
            $t->string("session_expires_at", 32)->notNull();
            $t->integer("consumed")->notNull()->defaultValue(0);
            $t->index(["session_id", "id"], "moodle_launch_session_idx");
            $t->index(["session_expires_at", "id"], "moodle_launch_retention_idx");
        });
    }
    public function down(): void
    {
        throw new LogicException("Retain Moodle launch references until their parent sign-in expires.");
    }
}
