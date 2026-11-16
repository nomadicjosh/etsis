<?php

declare(strict_types=1);

use Qubus\Expressive\Migration\Migration;
use Qubus\Expressive\Schema\CreateTable;

final class CreateSisCampaignDispatches extends Migration
{
    public function up(): void
    {
        $this->schema()->create('sis_campaign_dispatches', static function (CreateTable $t): void {
            $t->string('id', 26)->primary()->notNull();
            $t->string('actor_id', 36)->notNull();
            $t->string('operation_id', 26)->notNull();
            $t->integer('version')->notNull();
            $t->string('status', 16)->notNull();
            $t->text('encrypted_scope')->size('medium')->notNull();
            $t->string('updated_at', 32)->notNull();
            $t->foreign('id', 'scd_campaign_fk')->references('sis_campaigns', 'id');
            $t->foreign('actor_id', 'scd_actor_fk')->references('users', 'user_id');
            $t->index(['status', 'updated_at', 'id'], 'scd_pending');
        });
    }

    public function down(): void
    {
        throw new LogicException('Cancel outstanding authorizations before arranging explicit queue data disposition.');
    }
}
